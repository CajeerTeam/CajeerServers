#!/usr/bin/env python3
"""Bounded, one-shot transport. Input is a private stdin pipe; output only typed data or a finite error."""
import base64
import http.client
import io
import ipaddress
import json
import pathlib
import shlex
import socket
import ssl
import sys
import time
import uuid
import xml.etree.ElementTree as ET

MAX = 262144
ROOT = pathlib.Path(__file__).resolve().parent


class Failure(Exception):
    def __init__(self, code):
        self.code = code


def fail(code='connector_protocol_error'):
    raise Failure(code)


def ssh_collect(req):
    import paramiko
    from cryptography.hazmat.primitives import serialization
    from cryptography.hazmat.primitives.asymmetric import ed25519, ec, rsa
    secret = req['credential']
    host, port = req['host'], req['port']
    known = paramiko.HostKeys(req['known_hosts'])
    label = host if port == 22 else f'[{host}]:{port}'
    trusted = known.lookup(label)
    if not trusted:
        fail('connector_trust_failed')
    sock = socket.create_connection((req['ip'], port), timeout=8)
    transport = paramiko.Transport(sock)
    try:
        transport.banner_timeout = 8
        transport.auth_timeout = 8
        # Negotiate only algorithms for which the operator has a trusted key.
        algorithms = transport.get_security_options()
        permitted = tuple(x for x in algorithms.key_types if x in trusted or
                          (x in ('rsa-sha2-256', 'rsa-sha2-512') and 'ssh-rsa' in trusted))
        permitted = tuple(x for x in permitted if x not in ('ssh-rsa', 'ssh-dss'))
        if not permitted:
            fail('connector_trust_failed')
        algorithms.key_types = permitted
        transport.start_client(timeout=8)
        key = transport.get_remote_server_key()
        if not known.check(label, key):
            fail('connector_trust_failed')
        if req['credential_type'] == 'ssh_password':
            transport.auth_password(secret['username'], secret['password'], fallback=False)
        else:
            raw = secret['private_key'].encode()
            password = secret.get('passphrase')
            password = password.encode() if password else None
            # Parse PEM, PKCS8 and OpenSSH entirely in memory; never write a key file.
            loader = serialization.load_ssh_private_key if raw.startswith(b'-----BEGIN OPENSSH') else serialization.load_pem_private_key
            try:
                pkey = loader(raw, password)
                pem = pkey.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.OpenSSH, serialization.NoEncryption()).decode()
                cls = (paramiko.Ed25519Key if isinstance(pkey, ed25519.Ed25519PrivateKey) else
                       paramiko.ECDSAKey if isinstance(pkey, ec.EllipticCurvePrivateKey) else
                       paramiko.RSAKey if isinstance(pkey, rsa.RSAPrivateKey) else None)
                if cls is None:
                    fail('connector_auth_failed')
                try:
                    key = cls.from_private_key(io.StringIO(pem))
                except paramiko.SSHException:
                    fail('connector_auth_failed')
            except (ValueError, TypeError):
                fail('connector_auth_failed')
            transport.auth_publickey(secret['username'], key)
        if not transport.is_authenticated():
            fail('connector_auth_failed')
        channel = transport.open_session(timeout=8)
        try:
            channel.settimeout(8)
            # No user-controlled command or interpolated credentials.
            command = 'python3 -c ' + shlex.quote((ROOT / ('linux_metrics.py' if req.get('metrics') is True else 'linux_collect.py')).read_text())
            channel.exec_command(command)
            output = bytearray()
            stderr = 0
            deadline = time.monotonic() + 35
            while True:
                if time.monotonic() >= deadline:
                    fail('connector_transport_failed')
                if channel.recv_ready():
                    output.extend(channel.recv(16384))
                if channel.recv_stderr_ready():
                    stderr += len(channel.recv_stderr(16384))
                if len(output) + stderr > MAX:
                    fail()
                if channel.exit_status_ready() and not channel.recv_ready() and not channel.recv_stderr_ready():
                    if channel.recv_exit_status() != 0:
                        fail()
                    break
                time.sleep(.01)
            return json.loads(output)
        finally:
            channel.close()
    except paramiko.AuthenticationException:
        fail('connector_auth_failed')
    except paramiko.SSHException:
        fail('connector_transport_failed')
    finally:
        transport.close()
        sock.close()


SOAP = 'http://www.w3.org/2003/05/soap-envelope'
ADDR = 'http://schemas.xmlsoap.org/ws/2004/08/addressing'
WS = 'http://schemas.dmtf.org/wbem/wsman/1/wsman.xsd'
SHELL = 'http://schemas.microsoft.com/wbem/wsman/1/windows/shell'
TRANSFER = 'http://schemas.xmlsoap.org/ws/2004/09/transfer/'
URI = SHELL + '/cmd'


def tag(ns, name):
    return '{' + ns + '}' + name


def add(parent, ns, name, value=None, **attrs):
    node = ET.SubElement(parent, tag(ns, name), attrs)
    node.text = value
    return node


class PinnedHTTPS(http.client.HTTPSConnection):
    def __init__(self, host, port, ip, context):
        super().__init__(host, port, timeout=12, context=context)
        self.ip = ip

    def connect(self):
        raw = socket.create_connection((self.ip, self.port), self.timeout)
        try:
            self.sock = self._context.wrap_socket(raw, server_hostname=self.host)
        except BaseException:
            raw.close()
            raise


class WinRM:
    def __init__(self, req):
        self.req = req
        self.context = ssl.create_default_context(cafile=req.get('ca_file') or None)
        self.context.minimum_version = ssl.TLSVersion.TLSv1_2
        c = req['credential']
        if c.get('auth') != 'basic' or ':' in c['username']:
            fail('connector_auth_failed')
        self.auth = 'Basic ' + base64.b64encode((c['username'] + ':' + c['password']).encode()).decode('ascii')
        host = req['host']
        self.endpoint = 'https://' + (f'[{host}]' if ':' in host else host) + f":{req['port']}/wsman"
        self.deadline = time.monotonic() + 65

    def call(self, action, body=None, shell=None, cleanup=False):
        if not cleanup and time.monotonic() >= self.deadline:
            fail('connector_transport_failed')
        envelope = ET.Element(tag(SOAP, 'Envelope'))
        header = add(envelope, SOAP, 'Header')
        add(header, ADDR, 'To', self.endpoint)
        must = {tag(SOAP, 'mustUnderstand'): 'true'}
        reply = add(header, ADDR, 'ReplyTo')
        add(reply, ADDR, 'Address', ADDR + '/role/anonymous', **must)
        add(header, ADDR, 'Action', action, **must)
        message = 'uuid:' + str(uuid.uuid4())
        add(header, ADDR, 'MessageID', message)
        add(header, WS, 'ResourceURI', URI, **must)
        add(header, WS, 'MaxEnvelopeSize', '153600', **must)
        add(header, WS, 'OperationTimeout', 'PT8S')
        add(header, WS, 'Locale', **{tag('http://www.w3.org/XML/1998/namespace', 'lang'): 'en-US', tag(SOAP, 'mustUnderstand'): 'false'})
        if action == TRANSFER + 'Create':
            opts = add(header, WS, 'OptionSet')
            add(opts, WS, 'Option', 'TRUE', Name='WINRS_NOPROFILE')
            add(opts, WS, 'Option', '65001', Name='WINRS_CODEPAGE')
        elif action == SHELL + '/Command':
            opts = add(header, WS, 'OptionSet')
            add(opts, WS, 'Option', 'FALSE', Name='WINRS_CONSOLEMODE_STDIN')
            add(opts, WS, 'Option', 'FALSE', Name='WINRS_SKIP_CMD_SHELL')
        if shell:
            selectors = add(header, WS, 'SelectorSet')
            add(selectors, WS, 'Selector', shell, Name='ShellId')
        content = add(envelope, SOAP, 'Body')
        if body is not None:
            content.append(body)
        conn = PinnedHTTPS(self.req['host'], self.req['port'], self.req['ip'], self.context)
        try:
            conn.request('POST', '/wsman', ET.tostring(envelope, encoding='utf-8', xml_declaration=True),
                         {'Content-Type': 'application/soap+xml;charset=UTF-8', 'Authorization': self.auth, 'Connection': 'close'})
            response = conn.getresponse()
            if response.status in (401, 403):
                fail('connector_auth_failed')
            if response.status not in (200, 500):
                fail('connector_unavailable' if response.status in (502, 503, 504) else 'connector_protocol_error')
            expected = response.length
            raw = response.read(MAX + 1)
            if expected is not None and len(raw) != expected:
                fail()
            if len(raw) > MAX or b'<!' in raw or b'\x00' in raw:
                fail()
            root = ET.fromstring(raw)
            if root.tag != tag(SOAP, 'Envelope'):
                fail()
            fault = root.find('.//' + tag(SOAP, 'Fault'))
            if fault is not None:
                # WSMan Receive may expire while the command is still running. Retry within the overall deadline.
                if action == SHELL + '/Receive' and any(n.get('Code') == '2150858793' for n in fault.iter()):
                    return None
                fail()
            if response.status != 200:
                fail()
            relates = root.findtext('.//' + tag(ADDR, 'RelatesTo'))
            if relates != message:
                fail()
            return root
        finally:
            conn.close()

    def collect(self):
        shell_body = ET.Element(tag(SHELL, 'Shell'))
        add(shell_body, SHELL, 'InputStreams', 'stdin')
        add(shell_body, SHELL, 'OutputStreams', 'stdout stderr')
        add(shell_body, SHELL, 'IdleTimeOut', 'PT60S')
        created = self.call(TRANSFER + 'Create', shell_body)
        selector = created.find('.//' + tag(WS, 'Selector') + "[@Name='ShellId']")
        shell = selector.text if selector is not None else None
        if not shell:
            fail()
        uuid.UUID(shell)
        try:
            body = ET.Element(tag(SHELL, 'CommandLine'))
            add(body, SHELL, 'Command', 'powershell.exe')
            encoded = base64.b64encode((ROOT / ('windows_metrics.ps1' if self.req.get('metrics') is True else 'windows_collect.ps1')).read_text().encode('utf-16-le')).decode('ascii')
            add(body, SHELL, 'Arguments', '-NoLogo -NoProfile -NonInteractive -EncodedCommand ' + encoded)
            result = self.call(SHELL + '/Command', body, shell)
            cmd = result.findtext('.//' + tag(SHELL, 'CommandId'))
            if not cmd:
                fail()
            uuid.UUID(cmd)
            out = bytearray()
            stderr = 0
            for _ in range(40):
                body = ET.Element(tag(SHELL, 'Receive'))
                add(body, SHELL, 'DesiredStream', 'stdout stderr', CommandId=cmd)
                result = self.call(SHELL + '/Receive', body, shell)
                if result is None:
                    continue
                for stream in result.iter(tag(SHELL, 'Stream')):
                    if stream.get('CommandId') not in (None, cmd):
                        fail()
                    data = base64.b64decode(''.join((stream.text or '').split()), validate=True)
                    if stream.get('Name') == 'stdout':
                        out.extend(data)
                    elif stream.get('Name') == 'stderr':
                        stderr += len(data)
                    else:
                        fail()
                    if len(out) + stderr > MAX:
                        fail()
                state = result.find('.//' + tag(SHELL, 'CommandState'))
                if state is not None and state.get('State') == SHELL + '/CommandState/Done':
                    if state.findtext(tag(SHELL, 'ExitCode')) != '0' or stderr:
                        fail()
                    return json.loads(out.decode('utf-8-sig'))
            fail('connector_transport_failed')
        finally:
            # Always release the remote shell, including on malformed output or timeout.
            try:
                self.call(TRANSFER + 'Delete', shell=shell, cleanup=True)
            except Exception:
                pass


def main():
    try:
        raw = sys.stdin.buffer.read(131073)
        if len(raw) > 131072:
            fail()
        req = json.loads(raw)
        ipaddress.ip_address(req['ip'])
        if req['provider'] == 'ssh':
            data = ssh_collect(req)
        elif req['provider'] == 'winrm':
            data = WinRM(req).collect()
        else:
            fail()
        output = json.dumps({'data': data}, ensure_ascii=True, separators=(',', ':'))
        if len(output) > MAX:
            fail()
        print(output)
    except Failure as e:
        print(json.dumps({'error': e.code}))
    except ssl.SSLCertVerificationError:
        print('{"error":"connector_trust_failed"}')
    except (OSError, TimeoutError):
        print('{"error":"connector_transport_failed"}')
    except ImportError:
        print('{"error":"connector_dependency_missing"}')
    except Exception:
        # No exception repr, remote output or traceback may escape the transport.
        print('{"error":"connector_protocol_error"}')


if __name__ == '__main__':
    main()
