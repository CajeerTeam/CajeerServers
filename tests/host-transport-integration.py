#!/usr/bin/env python3
"""Real HTTPS WSMan protocol fixture; optional real SSH server with Paramiko (--ssh)."""
import base64
import http.server
import json
import pathlib
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
import uuid
import xml.etree.ElementTree as ET

ROOT = pathlib.Path(__file__).resolve().parents[1]
SOAP = 'http://www.w3.org/2003/05/soap-envelope'
ADDR = 'http://schemas.xmlsoap.org/ws/2004/08/addressing'
WS = 'http://schemas.dmtf.org/wbem/wsman/1/wsman.xsd'
SHELL = 'http://schemas.microsoft.com/wbem/wsman/1/windows/shell'
TRANSFER = 'http://schemas.xmlsoap.org/ws/2004/09/transfer/'
TAG = lambda ns, name: '{' + ns + '}' + name
SHELL_ID = str(uuid.uuid4())
CMD_ID = str(uuid.uuid4())
METRICS={'identity':'aabbccdd-eeff-0011-2233-445566778899','cpu_ratio':0.35,'memory_total':8192,'memory_available':4096,'disk_total':1000,'disk_available':500,'network':[{'name':'NIC','rx':'18446744073709551615','tx':'100'}]}
DATA = {'identity':'aabbccdd-eeff-0011-2233-445566778899','name':'win-test','os_family':'windows','os_name':'Windows Server','os_version':'10.0','cpu_count':4,'memory_bytes':8589934592,'disks':[{'name':'Disk0','size_bytes':123456}], 'interfaces':[{'name':'NIC','addresses':['192.0.2.1','2001:db8::1']}]}


def execute(provider, port, credential, **kwargs):
    request = {'provider': provider, 'host':'localhost','port':port,'type':'winrm' if provider=='winrm' else 'ssh_password', 'python':sys.executable,'credential':credential, 'networks':['127.0.0.0/8','::1/128'], **kwargs}
    proc = subprocess.run(['php', str(ROOT/'tests/host-transport-fixture.php')], input=json.dumps(request), capture_output=True, text=True, timeout=100)
    assert proc.returncode==0, proc.stderr
    return json.loads(proc.stdout)


class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_POST(self):
        server = self.server
        server.requests.append(self.path)
        if self.headers.get('Authorization') != 'Basic '+base64.b64encode(b'test:fixture-secret').decode():
            self.send_response(401);self.end_headers();return
        assert self.path=='/wsman'
        assert self.headers['Host'].startswith('localhost:')
        root=ET.fromstring(self.rfile.read(int(self.headers['Content-Length'])))
        action=root.findtext('.//'+TAG(ADDR,'Action'))
        message=root.findtext('.//'+TAG(ADDR,'MessageID'))
        server.actions.append(action)
        if server.mode=='redirect':
            self.send_response(302);self.send_header('Location','https://example.invalid');self.end_headers();return
        envelope=ET.Element(TAG(SOAP,'Envelope'));header=ET.SubElement(envelope,TAG(SOAP,'Header'));ET.SubElement(header,TAG(ADDR,'RelatesTo')).text=message
        body=ET.SubElement(envelope,TAG(SOAP,'Body'))
        if action==TRANSFER+'Create':
            ET.SubElement(body,TAG(WS,'Selector'),Name='ShellId').text=SHELL_ID
        elif action==SHELL+'/Command':
            assert root.findtext('.//'+TAG(SHELL,'Command'))=='powershell.exe'
            args=root.findtext('.//'+TAG(SHELL,'Arguments'))
            code=base64.b64decode(args.split()[-1]).decode('utf-16-le')
            assert 'Get-CimInstance' in code and 'fixture-secret' not in code and 'EncodedCommand' in args
            server.metrics='Win32_PerfFormattedData_PerfOS_Processor' in code
            ET.SubElement(body,TAG(SHELL,'CommandId')).text=CMD_ID
        elif action==SHELL+'/Receive':
            assert root.find('.//'+TAG(SHELL,'DesiredStream')).get('CommandId')==CMD_ID
            if server.mode=='bad-output': payload=b'{bad-json'
            elif server.mode=='oversize': payload=b'x'*270000
            else: payload=json.dumps(METRICS if server.metrics else DATA).encode()
            # Exercise multiple Receive calls and stream assembly.
            server.receives+=1
            done=server.receives%2==0 or server.mode!='ok'
            chunk=payload if server.mode!='ok' else (payload[:len(payload)//2] if not done else payload[len(payload)//2:])
            ET.SubElement(body,TAG(SHELL,'Stream'),Name='stdout',CommandId=CMD_ID).text=base64.b64encode(chunk).decode()
            state=ET.SubElement(body,TAG(SHELL,'CommandState'),State=SHELL+'/CommandState/'+('Done' if done else 'Running'))
            if done: ET.SubElement(state,TAG(SHELL,'ExitCode')).text='1' if server.mode=='nonzero' else '0'
        elif action==TRANSFER+'Delete':
            assert root.find('.//'+TAG(WS,'Selector')).text==SHELL_ID
        else: raise AssertionError(action)
        raw=ET.tostring(envelope,encoding='utf-8')
        self.send_response(200);self.send_header('Content-Type','application/soap+xml');self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)


def https_test(tmp):
    cert=tmp/'cert.pem';key=tmp/'key.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost'],check=True,capture_output=True)
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
    server.requests=[];server.actions=[];server.mode='ok';server.receives=0
    ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    port=server.server_address[1];cred={'username':'test','password':'fixture-secret','auth':'basic'}
    try:
        # The helper pins the first allowed IP and retains localhost as the TLS identity.
        out=execute('winrm',port,cred,ca=str(cert));assert out['complete'] and out['items'][0]['fields']['attributes']['host_hardware']['cpu_count']==4, out
        assert server.actions[-1]==TRANSFER+'Delete' and server.receives==2
        out=execute('winrm',port,cred,ca=str(cert),collector='winrm.inventory');assert out['complete'] and out['items'][0]['fields']['attributes']['host_hardware']['cpu_count']==4, out
        out=execute('winrm',port,cred,ca=str(cert),collector='winrm.metrics',operation='inspect');assert len(out['metric_samples'])==7 and out['metric_samples'][5]['value']=='18446744073709551615',out
        out=execute('winrm',port,cred,ca=str(cert),operation='health');assert not out['complete'],out
        assert execute('winrm',port,cred)['error']=='connector_trust_failed'
        assert execute('winrm',port,cred,ca=str(cert),host='127.0.0.1')['error']=='connector_trust_failed'
        assert execute('winrm',port,{**cred,'password':'wrong'},ca=str(cert))['error']=='connector_auth_failed'
        assert execute('winrm',port,{**cred,'auth':'ntlm'},ca=str(cert))['error']=='connector_auth_failed'
        for mode in ('bad-output','nonzero','oversize','redirect'):
            server.mode=mode
            out=execute('winrm',port,cred,ca=str(cert));assert out['error']=='connector_protocol_error',out
            assert 'fixture-secret' not in json.dumps(out)
            if mode!='redirect':assert server.actions[-1]==TRANSFER+'Delete'
        assert execute('winrm',port,cred,ca=str(cert),networks=['192.0.2.0/24'])['error']=='connector_trust_failed'
    finally:
        server.shutdown();server.server_close();thread.join()
    print('WinRM HTTPS fixture: 14 scenarios PASS')


def ssh_test(tmp):
    import paramiko
    from cryptography.hazmat.primitives.asymmetric import ed25519
    from cryptography.hazmat.primitives import serialization
    server_key=paramiko.RSAKey.generate(2048)
    other_key=paramiko.RSAKey.generate(2048)
    client_key=ed25519.Ed25519PrivateKey.generate()
    pem=client_key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.OpenSSH,serialization.NoEncryption()).decode()
    expected=paramiko.Ed25519Key.from_private_key(__import__('io').StringIO(pem))
    commands=[];auth_attempts=[]
    class Server(paramiko.ServerInterface):
        def check_auth_password(self,user,password):
            auth_attempts.append('password')
            return paramiko.AUTH_SUCCESSFUL if (user,password)==('test','fixture-secret') else paramiko.AUTH_FAILED
        def check_auth_publickey(self,user,key):
            auth_attempts.append('key')
            return paramiko.AUTH_SUCCESSFUL if user=='test' and key==expected else paramiko.AUTH_FAILED
        def get_allowed_auths(self,user):return 'password,publickey'
        def check_channel_request(self,kind,chanid):return paramiko.OPEN_SUCCEEDED if kind=='session' else paramiko.OPEN_FAILED_ADMINISTRATIVELY_PROHIBITED
        def check_channel_exec_request(self,channel,command):
            commands.append(command)
            def run():
                # Execute the exact fixed collector received over a real SSH session, locally on the test host.
                import shlex
                args=shlex.split(command.decode());assert args[:2]==['python3','-c'] and len(args)==3
                assert 'fixture-secret' not in args[2]
                proc=subprocess.run([sys.executable,'-c',args[2]],capture_output=True,timeout=20)
                channel.sendall(proc.stdout);channel.sendall_stderr(proc.stderr);channel.send_exit_status(proc.returncode);channel.shutdown_write()
            threading.Thread(target=run,daemon=True).start();return True
    listener=socket.socket();listener.bind(('127.0.0.1',0));listener.listen(8);listener.settimeout(.2);port=listener.getsockname()[1]
    stop=threading.Event()
    def loop():
        while not stop.is_set():
            try:sock,_=listener.accept()
            except socket.timeout:continue
            except OSError:break
            tr=paramiko.Transport(sock);tr.add_server_key(server_key)
            try:
                tr.start_server(server=Server());ch=tr.accept(5)
                if ch:
                    while tr.is_active() and not ch.closed:stop.wait(.05)
            except (paramiko.SSHException,EOFError):pass
            finally:tr.close();sock.close()
    thread=threading.Thread(target=loop,daemon=True);thread.start()
    known=tmp/'known_hosts';known.write_text(f'[localhost]:{port} {server_key.get_name()} {server_key.get_base64()}\n')
    wrong=tmp/'wrong_hosts';wrong.write_text(f'[localhost]:{port} {other_key.get_name()} {other_key.get_base64()}\n')
    cred={'username':'test','password':'fixture-secret'}
    try:
        out=execute('ssh',port,cred,known_hosts=str(known));assert out['complete'] and out['items'][0]['fields']['attributes']['host_hardware']['cpu_count']>0,out
        out=execute('ssh',port,cred,known_hosts=str(known),collector='ssh.inventory');assert out['complete'],out
        out=execute('ssh',port,cred,known_hosts=str(known),collector='ssh.metrics',operation='inspect');assert len(out['metric_samples'])>=5,out
        out=execute('ssh',port,{'username':'test','private_key':pem},type='ssh_private_key',known_hosts=str(known));assert out['complete'],out
        encrypted=client_key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.OpenSSH,serialization.BestAvailableEncryption(b'key-passphrase')).decode()
        out=execute('ssh',port,{'username':'test','private_key':encrypted,'passphrase':'key-passphrase'},type='ssh_private_key',known_hosts=str(known));assert out['complete'],out
        n=len(auth_attempts);assert execute('ssh',port,cred,known_hosts=str(wrong))['error']=='connector_trust_failed';assert len(auth_attempts)==n
        assert execute('ssh',port,{**cred,'password':'wrong'},known_hosts=str(known))['error']=='connector_auth_failed'
        assert len(commands)==5
    finally:stop.set();listener.close();thread.join(timeout=6)
    print('SSH protocol fixture: 7 scenarios PASS')


if __name__=='__main__':
    with tempfile.TemporaryDirectory() as folder:
        tmp=pathlib.Path(folder);https_test(tmp)
        if '--ssh' in sys.argv:ssh_test(tmp)
