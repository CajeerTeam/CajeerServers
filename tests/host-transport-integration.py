#!/usr/bin/env python3
"""Real HTTPS WSMan protocol fixture; optional real SSH server with Paramiko (--ssh)."""
import base64
import hashlib
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


# Wire-level fixture responses, not executions of PowerShell on Windows.
WINDOWS_SECTIONS={
 'system':{'system':[{'hostname':'win-test','os_name':'Windows Server 2025','os_version':'10.0','build':'26100','architecture':'64-bit','product_type':'3','manufacturer':'Fixture','model':'VM','cpu_count':'4','memory_bytes':'8589934592','memory_available_bytes':'4294967296','boot_time':'2026-11-13T00:00:00.0000000Z'}],'processors':[{'id':'CPU0','model':'Fixture CPU','cores':'2','logical_processors':'4','max_clock_mhz':'3000'}]},
 'software':{'applications':[{'id':'App','registry_view':'Registry64','name':'Fixture app','version':'1.0','publisher':'Fixture','install_date':None}]},
 'services':{'services':[{'name':'WinRM','display_name':'Windows Remote Management','state':'Running','start_mode':'Auto','status':'OK','process_id':'100'}]},
 'storage':{'disks':[{'id':'disk0','index':'0','model':'Fixture disk','interface_type':'SCSI','size_bytes':'18446744073709551615','status':'OK'}],'partitions':[{'id':'disk0-part0','disk_index':'0','index':'0','type':'GPT','size_bytes':'1000','offset_bytes':'1024'}],'volumes':[{'id':'volume0','drive_letter':'C:','label':'System','filesystem':'NTFS','size_bytes':'1000','available_bytes':'500'}]},
 'network':{'interfaces':[{'index':'1','name':'NIC','mac':'00:11:22:33:44:55','speed_bps':'1000000000','status':'2'}],'addresses':[{'index':'1','address':'2001:db8::1','prefix':'64','family':'IPv6','state':'Preferred'}],'routes':[{'index':'1','destination':'::/0','gateway':'fe80::1','family':'IPv6','metric':'1'}]},
 'updates':{'hotfixes':[{'id':'KB123456','description':'Security Update','installed_on':'11/14/2026'}],'reboot':[{'cbs':'true','windows_update':'false','pending_file_rename':'false','detected':'true'}]},
}


def execute(provider, port, credential, *, expected_error=None, **kwargs):
    request = {'provider': provider, 'host':'localhost','port':port,'type':'winrm' if provider=='winrm' else 'ssh_password', 'python':sys.executable,'credential':credential, 'networks':['127.0.0.0/8','::1/128'], **kwargs}
    proc = subprocess.run(['php', str(ROOT/'tests/host-transport-fixture.php')], input=json.dumps(request), capture_output=True, text=True, timeout=100)
    label = f"{provider} {kwargs.get('collector', kwargs.get('operation', 'sync'))}"
    assert proc.returncode == 0, f'{label}: PHP fixture exit {proc.returncode}'
    try:
        result = json.loads(proc.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError(f'{label}: invalid fixture JSON') from exc
    assert isinstance(result, dict), f'{label}: invalid fixture response'
    # Reject unexpected errors before indexing success fields. Never print
    # credentials or raw remote output. Expected rejections remain mandatory.
    if expected_error is not None:
        assert result.get('error') == expected_error, f'{label}: expected {expected_error}, got {result.get("error", "success")}'
    else:
        assert 'error' not in result, f'{label}: unexpected connector error {result.get("error")}'
        assert set(result) == {'items','complete','metric_samples','connection'}, f'{label}: malformed success response'
    return result


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
            assert 'fixture-secret' not in code and 'EncodedCommand' in args
            server.scope=next((scope for scope in WINDOWS_SECTIONS if code==(ROOT/'core/Connector/agents'/f'windows_{scope}.ps1').read_text()),None)
            if 'Get-WinEvent' in code:
                import re
                cursor=re.search(r"FromBase64String\('([A-Za-z0-9+/=]+)'\)",code).group(1)
                server.event_previous=json.loads(base64.b64decode(cursor))
                assert code==(ROOT/'core/Connector/agents/windows_events.ps1').read_text().replace('__CURSOR_BASE64__',cursor)
                server.scope='events'
            if server.scope is None: assert 'Get-CimInstance' in code
            else: assert 'MachineGuid' in code and 'Win32_Product' not in code
            server.chunk_offset=0
            server.receives=0
            server.metrics='Win32_PerfFormattedData_PerfOS_Processor' in code
            ET.SubElement(body,TAG(SHELL,'CommandId')).text=CMD_ID
        elif action==SHELL+'/Receive':
            assert root.find('.//'+TAG(SHELL,'DesiredStream')).get('CommandId')==CMD_ID
            if server.mode in ('timeout-fault','foreign-fault') and server.receives==0:
                server.receives+=1
                if server.mode=='foreign-fault':header.find(TAG(ADDR,'RelatesTo')).text='uuid:'+str(uuid.uuid4())
                fault=ET.SubElement(body,TAG(SOAP,'Fault'))
                ET.SubElement(fault,TAG(WS,'WSManFault'),Code='2150858793')
                raw=ET.tostring(envelope,encoding='utf-8');self.send_response(500);self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw);return
            if server.mode=='bad-output': payload=b'{bad-json'
            elif server.mode=='oversize': payload=b'x'*270000
            else:
                value=METRICS if server.metrics else DATA
                if server.scope=='events':
                    previous=server.event_previous
                    events=[];cursor=dict(previous)
                    for channel in ['System','Application']:
                        if channel in previous:continue
                        at='2026-11-14T12:00:00.000000Z'
                        events.append({'key':channel+':7:'+at,'channel':channel,'event_at':at,'severity':4,'facility':None,'hostname':'win-test','application':'Fixture','message':'fixture event'})
                        cursor[channel]={'id':'7','at':at}
                    value={'identity':DATA['identity'],'events':events,'cursor':cursor,'more':False}
                    if server.mode=='identity':value['identity']='12345678-1234-1234-1234-123456789abc'
                    if server.mode=='extra':value['cursor']['System']['id']='999'
                elif server.scope:
                    import copy
                    value={'scope':server.scope,'identity':DATA['identity'],'sections':copy.deepcopy(WINDOWS_SECTIONS[server.scope])}
                    if server.mode=='identity': value['identity']='12345678-1234-1234-1234-123456789abc'
                    if server.mode=='extra': next(iter(value['sections'].values()))[0]['secret']='fixture-secret'
                    if server.mode=='large':
                        assert server.scope=='software'
                        value['sections']['applications']=[{**WINDOWS_SECTIONS['software']['applications'][0],'id':str(i),'name':'x'*400} for i in range(850)]
                payload=json.dumps(value).encode()
            # Exercise multiple Receive calls and stream assembly.
            server.receives+=1
            if server.mode=='large':
                chunk=payload[server.chunk_offset:server.chunk_offset+90000];server.chunk_offset+=len(chunk);done=server.chunk_offset==len(payload)
            else:
                done=server.receives%2==0 or server.mode!='ok'
                chunk=payload if server.mode!='ok' else (payload[:len(payload)//2] if not done else payload[len(payload)//2:])
            ET.SubElement(body,TAG(SHELL,'Stream'),Name='stdout',CommandId=CMD_ID).text=base64.b64encode(chunk).decode()
            state=ET.SubElement(body,TAG(SHELL,'CommandState'),State=SHELL+'/CommandState/'+('Done' if done else 'Running'))
            if server.mode=='foreign-command':state.set('CommandId',str(uuid.uuid4()))
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
        pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
        profile={'primary_pin':pin,'identity_hash':hashlib.sha256(('winrm:'+DATA['identity'].replace('-','').lower()).encode()).hexdigest()}
        out=execute('winrm',port,cred,ca=str(cert),profile=profile,require_profile=True,operation='health');assert out['connection']['profile_verified'] and out['connection']['identity_verified'],out
        out=execute('winrm',port,cred,ca=str(cert),profile=profile,collector='winrm.inventory',require_profile=True);assert out['complete'],out
        out=execute('winrm',port,cred,ca=str(cert),profile=profile,collector='winrm.metrics',operation='inspect',require_profile=True);assert len(out['metric_samples'])==7,out
        n=len(server.requests);assert execute('winrm',port,cred,ca=str(cert),profile={**profile,'primary_pin':'0'*64},expected_error='connector_trust_failed')['error']=='connector_trust_failed';assert len(server.requests)==n
        assert execute('winrm',port,cred,ca=str(cert),require_profile=True,expected_error='connector_trust_failed')['error']=='connector_trust_failed';assert len(server.requests)==n
        assert execute('winrm',port,cred,ca=str(cert),profile={**profile,'identity_hash':'0'*64},expected_error='connector_trust_failed')['error']=='connector_trust_failed'
        assert execute('winrm',port,cred,ca=str(cert),profile={**profile,'identity_hash':'0'*64},collector='winrm.metrics',operation='inspect',expected_error='connector_trust_failed')['error']=='connector_trust_failed'
        assert execute('winrm',port,cred,profile=profile,expected_error='connector_trust_failed')['error']=='connector_trust_failed'
        assert execute('winrm',port,cred,ca=str(cert),profile=profile,operation='validate_rotation')['connection']['identity_verified']
        print('WinRM profile: 9 scenarios PASS')
        for scope in WINDOWS_SECTIONS:
            out=execute('winrm',port,cred,ca=str(cert),profile=profile,collector='winrm.'+scope,operation='inspect')
            snap=out['connection']['windows_discovery'];assert snap['scope']==scope and snap['sections']==WINDOWS_SECTIONS[scope] and snap['complete'],out
            assert out['items']==[] and not out['complete'] and 'identity' not in snap and server.actions[-1]==TRANSFER+'Delete'
        n=len(server.requests)
        assert execute('winrm',port,cred,ca=str(cert),collector='winrm.system',operation='inspect',expected_error='collection_invalid')['error']=='collection_invalid'
        assert execute('winrm',port,cred,ca=str(cert),profile=profile,collector='winrm.system',operation='sync',expected_error='collection_invalid')['error']=='collection_invalid'
        assert execute('winrm',port,cred,ca=str(cert),profile={**profile,'primary_pin':'0'*64},collector='winrm.system',operation='inspect',expected_error='connector_trust_failed')['error']=='connector_trust_failed'
        assert len(server.requests)==n
        for mode,code in [('identity','connector_trust_failed'),('extra','connector_protocol_error'),('nonzero','connector_protocol_error')]:
            server.mode=mode
            out=execute('winrm',port,cred,ca=str(cert),profile=profile,collector='winrm.system',operation='inspect',expected_error=code)
            assert out['error']==code and 'fixture-secret' not in json.dumps(out),out
            assert server.actions[-1]==TRANSFER+'Delete'
        server.mode='large'
        out=execute('winrm',port,cred,ca=str(cert),profile=profile,collector='winrm.software',operation='inspect')
        assert out['connection']['windows_discovery']['counts']['applications']==850 and server.receives>2,out
        server.mode='ok'
        print('Windows Discovery HTTPS: 13 scenarios PASS')
        out=execute('winrm',port,cred,ca=str(cert),profile=profile,collector='winrm.events',operation='inspect');batch=out['connection']['system_events'];assert len(batch['events'])==2 and batch['cursor']['System']['id']=='7',out
        out=execute('winrm',port,cred,ca=str(cert),profile=profile,collector='winrm.events',operation='inspect',previous=batch['cursor']);assert out['connection']['system_events']['events']==[],out
        for mode,code in [('identity','connector_trust_failed'),('extra','connector_protocol_error'),('nonzero','connector_protocol_error')]:
            server.mode=mode
            out=execute('winrm',port,cred,ca=str(cert),profile=profile,collector='winrm.events',operation='inspect',expected_error=code);assert out['error']==code,out
        server.mode='ok'
        assert execute('winrm',port,cred,ca=str(cert),profile=profile,collector='winrm.events',operation='sync',expected_error='collection_invalid')['error']=='collection_invalid'
        assert execute('winrm',port,cred,ca=str(cert),collector='winrm.events',operation='inspect',expected_error='collection_invalid')['error']=='collection_invalid'
        print('Windows system events HTTPS: 7 scenarios PASS')
        if '--postgres' in sys.argv:
            profile_path=tmp/'windows-profile.json';profile_path.write_text(json.dumps(profile))
            subprocess.run(['php',str(ROOT/'tests/windows-discovery-postgres-integration.php'),str(port),str(profile_path),sys.executable,str(cert)],check=True,timeout=300)

        out=execute('winrm',port,cred,ca=str(cert),collector='winrm.inventory');assert out['complete'] and out['items'][0]['fields']['attributes']['host_hardware']['cpu_count']==4, out
        out=execute('winrm',port,cred,ca=str(cert),collector='winrm.metrics',operation='inspect');assert len(out['metric_samples'])==7 and out['metric_samples'][5]['value']=='18446744073709551615',out
        out=execute('winrm',port,cred,ca=str(cert),operation='health');assert not out['complete'],out
        assert execute('winrm',port,cred,expected_error='connector_trust_failed')['error']=='connector_trust_failed'
        assert execute('winrm',port,cred,ca=str(cert),host='127.0.0.1',expected_error='connector_trust_failed')['error']=='connector_trust_failed'
        assert execute('winrm',port,{**cred,'password':'wrong'},ca=str(cert),expected_error='connector_auth_failed')['error']=='connector_auth_failed'
        assert execute('winrm',port,{**cred,'auth':'ntlm'},ca=str(cert),expected_error='connector_auth_failed')['error']=='connector_auth_failed'
        for mode in ('bad-output','nonzero','oversize','redirect'):
            server.mode=mode
            out=execute('winrm',port,cred,ca=str(cert),expected_error='connector_protocol_error');assert out['error']=='connector_protocol_error',out
            assert 'fixture-secret' not in json.dumps(out)
            if mode!='redirect':assert server.actions[-1]==TRANSFER+'Delete'
        assert execute('winrm',port,cred,ca=str(cert),networks=['192.0.2.0/24'],expected_error='connector_trust_failed')['error']=='connector_trust_failed'
        server.mode='timeout-fault'
        assert execute('winrm',port,cred,ca=str(cert))['complete'] and server.receives==2
        for mode in ('foreign-fault','foreign-command'):
            server.mode=mode
            out=execute('winrm',port,cred,ca=str(cert),expected_error='connector_protocol_error');assert out['error']=='connector_protocol_error',out
            assert server.receives==1 and server.actions[-1]==TRANSFER+'Delete'
        print('WinRM correlated fault and command state: 3 scenarios PASS')
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
    last_probe={}
    # Extra wire-level regression: execute the exact production collector,
    # injecting a valid zero-capacity block device at the lsblk boundary.
    # All ordinary discovery, metrics and PostgreSQL scenarios still use the
    # real Linux host and its unmodified command results.
    disk_mode='normal'
    disk_wrapper=r"""
import json, subprocess, sys
original=subprocess.check_output
def check_output(args,*pos,**kw):
    raw=original(args,*pos,**kw)
    if args[0]=='lsblk':
        data=json.loads(raw)
        data['blockdevices'].append({'name':'cajeer-fixture-zero','size':0,'type':'disk'})
        if sys.argv[2]=='conflict':
            data['blockdevices'].append({'name':'cajeer-fixture-zero','size':1,'type':'disk'})
        return json.dumps(data).encode()
    return raw
subprocess.check_output=check_output
exec(compile(sys.argv[1], '<fixed-linux-collector>', 'exec'), {'__name__':'__main__'})
"""
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
                args=shlex.split(command.decode());assert args[:2]==['python3','-c'] and len(args) in (3,4)
                if len(args)==4:
                    if args[2]==(ROOT/'core/Connector/agents/linux_events.py').read_text():
                        assert isinstance(json.loads(args[3]),dict)
                    else:
                        assert args[3] in ('system','software','services','storage','network')
                        assert args[2]==(ROOT/'core/Connector/agents/linux_discovery.py').read_text()
                assert 'fixture-secret' not in args[2]
                is_inventory=len(args)==3 and args[2]==(ROOT/'core/Connector/agents/linux_collect.py').read_text()
                if len(args)==3:
                    assert is_inventory or args[2]==(ROOT/'core/Connector/agents/linux_metrics.py').read_text()
                last_probe.clear()
                last_probe.update(stage='collector',inventory=is_inventory)
                child_command=[sys.executable,'-c',*args[2:]]
                if is_inventory and disk_mode!='normal':
                    child_command=[sys.executable,'-c',disk_wrapper,args[2],disk_mode]
                try:
                    proc=subprocess.run(child_command,capture_output=True,timeout=35)
                except subprocess.TimeoutExpired:
                    last_probe.update(stage='collector_timeout')
                    channel.send_exit_status(124);channel.shutdown_write();return
                last_probe.update(stage='sending',returncode=proc.returncode,stdout_bytes=len(proc.stdout),stderr_bytes=len(proc.stderr))
                # Store bytes privately, never print host data, credentials or remote stderr.
                if is_inventory and proc.returncode==0:
                    last_probe['snapshot']=proc.stdout
                # Real SSH exit status is not an EOF marker. Deliver a late stdout tail.
                if proc.returncode==0:
                    channel.sendall(proc.stdout[:len(proc.stdout)//2]);channel.send_exit_status(0)
                    __import__('time').sleep(.08);channel.sendall(proc.stdout[len(proc.stdout)//2:])
                else:
                    channel.sendall(proc.stdout);channel.send_exit_status(proc.returncode)
                channel.sendall_stderr(proc.stderr);channel.shutdown_write()
                last_probe['stage']='eof_sent'
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
        n=len(auth_attempts);assert execute('ssh',port,cred,known_hosts=str(wrong),expected_error='connector_trust_failed')['error']=='connector_trust_failed';assert len(auth_attempts)==n
        assert execute('ssh',port,{**cred,'password':'wrong'},known_hosts=str(known),expected_error='connector_auth_failed')['error']=='connector_auth_failed'
        assert len(commands)==5
        disk_mode='zero'
        out=execute('ssh',port,cred,known_hosts=str(known))
        assert out['complete'] and 'cajeer-fixture-zero' not in [d['name'] for d in out['items'][0]['fields']['attributes']['host_hardware']['disks']]
        disk_mode='conflict'
        assert execute('ssh',port,cred,known_hosts=str(known),expected_error='connector_protocol_error')['error']=='connector_protocol_error'
        disk_mode='normal'
        print('Linux inventory SSH: zero capacity accepted, conflicting capacity rejected PASS')
        identity=(pathlib.Path('/etc/machine-id').read_text().strip().lower().replace('-',''))
        profile={'primary_pin':hashlib.sha256(server_key.asbytes()).hexdigest(),'identity_hash':hashlib.sha256(('ssh:'+identity).encode()).hexdigest(),'key_type':'ssh-rsa'}
        out=execute('ssh',port,cred,profile=profile,require_profile=True,operation='health');assert out['connection']['profile_verified'],out
        out=execute('ssh',port,cred,profile=profile,collector='ssh.inventory',require_profile=True);assert out['complete'],out
        out=execute('ssh',port,cred,profile=profile,collector='ssh.metrics',operation='inspect',require_profile=True);assert len(out['metric_samples'])>=5,out
        n=len(auth_attempts);assert execute('ssh',port,cred,profile={**profile,'primary_pin':'0'*64},expected_error='connector_trust_failed')['error']=='connector_trust_failed';assert len(auth_attempts)==n
        assert execute('ssh',port,cred,known_hosts=str(known),require_profile=True,expected_error='connector_trust_failed')['error']=='connector_trust_failed';assert len(auth_attempts)==n
        assert execute('ssh',port,cred,profile={**profile,'identity_hash':'0'*64},expected_error='connector_trust_failed')['error']=='connector_trust_failed'
        assert execute('ssh',port,cred,profile={**profile,'identity_hash':'0'*64},collector='ssh.metrics',operation='inspect',expected_error='connector_trust_failed')['error']=='connector_trust_failed'
        out=execute('ssh',port,{'username':'test','private_key':pem},type='ssh_private_key',profile=profile,operation='validate_rotation');assert out['connection']['identity_verified'],out
        print('SSH profile: 8 scenarios PASS')
        for scope in ('system','software','storage','network'):
            out=execute('ssh',port,cred,profile=profile,collector='ssh.'+scope,operation='inspect',require_profile=True)
            assert out['connection']['linux_discovery']['scope']==scope and out['connection']['linux_discovery']['complete'],out
            assert out['items']==[] and 'identity' not in out['connection']['linux_discovery'],out
        n=len(auth_attempts)
        assert execute('ssh',port,cred,known_hosts=str(known),collector='ssh.system',operation='inspect',expected_error='collection_invalid')['error']=='collection_invalid'
        assert execute('ssh',port,cred,profile=profile,collector='ssh.software',operation='sync',expected_error='collection_invalid')['error']=='collection_invalid'
        assert execute('ssh',port,cred,profile={**profile,'primary_pin':'0'*64},collector='ssh.system',operation='inspect',expected_error='connector_trust_failed')['error']=='connector_trust_failed'
        assert len(auth_attempts)==n
        assert execute('ssh',port,cred,profile={**profile,'identity_hash':'0'*64},collector='ssh.software',operation='inspect',expected_error='connector_trust_failed')['error']=='connector_trust_failed'
        # The integration host may be a container without a systemd manager.
        # Verify the success path on real systemd hosts and fail closed otherwise.
        has_systemd=subprocess.run(['systemctl','list-units','--type=service','--no-pager'],capture_output=True,timeout=10).returncode==0
        out=execute('ssh',port,cred,profile=profile,collector='ssh.services',operation='inspect',expected_error=None if has_systemd else 'connector_protocol_error')
        if has_systemd:
            assert out['connection']['linux_discovery']['scope']=='services',out
        else:
            assert out['error']=='connector_protocol_error',out
        print('Linux discovery SSH: 9 scenarios PASS')
        # The production probe runs on the SSH fixture host; a container may have no visible journal.
        local=subprocess.run([sys.executable,str(ROOT/'core/Connector/agents/linux_events.py'),'{}'],capture_output=True,timeout=35)
        out=execute('ssh',port,cred,profile=profile,collector='ssh.events',operation='inspect',expected_error=None if local.returncode==0 else 'connector_protocol_error')
        if local.returncode==0:
            assert 'system_events' in out['connection'],out
        else:
            assert out['error']=='connector_protocol_error',out
        assert execute('ssh',port,cred,profile=profile,collector='ssh.events',operation='sync',expected_error='collection_invalid')['error']=='collection_invalid'
        print('Linux events SSH: 2 scenarios PASS')
        if '--postgres' in sys.argv:
            profile_path=tmp/'linux-profile.json'
            profile_path.write_text(json.dumps(profile))
            subprocess.run(['php',str(ROOT/'tests/linux-discovery-postgres-integration.php'),str(port),str(profile_path),sys.executable],check=True,timeout=300)
    except (AssertionError, subprocess.SubprocessError):
        # Keep a bounded, non-secret diagnostic on unexpected failures. A
        # local replay of only the observation validator distinguishes bad
        # inventory data from SSH/helper framing; it does not retry the test.
        snapshot=last_probe.get('snapshot')
        diagnostic={k:v for k,v in last_probe.items() if k!='snapshot'}
        if snapshot is not None:
            validation=r"""
require $argv[1].'/core/autoload.php';
try {
    $data=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    $result=\Cajeer\Servers\Connector\HostObservation::result($data,'ssh','sync');
    $manifest=new \Cajeer\Servers\Connector\ConnectorManifest('ssh','ssh',['ssh_password','ssh_private_key'],\Cajeer\Servers\Connector\ConnectorManifest::OPERATIONS,['server'],'ssh');
    $result->validated($manifest);
} catch (Throwable $e) { exit(1); }
"""
            try:
                result=subprocess.run(['php','-r',validation,str(ROOT)],input=snapshot,capture_output=True,timeout=10)
                diagnostic['local_observation_validation']='pass' if result.returncode==0 else 'fail'
            except subprocess.SubprocessError:
                diagnostic['local_observation_validation']='unavailable'
        print('SSH fixture diagnostic: '+json.dumps(diagnostic),file=sys.stderr,flush=True)
        raise
    finally:stop.set();listener.close();thread.join(timeout=6)
    print('SSH protocol fixture: 7 scenarios PASS')


if __name__=='__main__':
    if '--ssh' in sys.argv:
        try:
            import paramiko
        except ImportError as exc:
            sys.exit(f'SSH integration requires Paramiko: {exc}')
    with tempfile.TemporaryDirectory() as folder:
        tmp=pathlib.Path(folder);https_test(tmp)
        if '--ssh' in sys.argv:ssh_test(tmp)
