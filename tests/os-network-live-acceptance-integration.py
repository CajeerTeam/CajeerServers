#!/usr/bin/env python3
"""Exercise live acceptance CLI against two real TLS protocol fixtures and two unavailable endpoints.
The full matrix must fail closed; this is not evidence of native Windows, SSH or SNMP availability.
"""
import hashlib
import http.server
import importlib.util
import json
import pathlib
import socket
import ssl
import subprocess
import sys
import tempfile
import threading

ROOT=pathlib.Path(__file__).resolve().parents[1]

def load(name,file):
    spec=importlib.util.spec_from_file_location(name,ROOT/'tests'/file)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module

win=load('win_fixture','host-transport-integration.py')
router=load('network_fixture','connector-e2e-fixture.py')

def main():
    with tempfile.TemporaryDirectory() as tmp:
        folder=pathlib.Path(tmp);cert=folder/'cert.pem';key=folder/'key.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1'],check=True,capture_output=True)
        pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
        context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(cert,key)
        servers=[];threads=[]
        for handler in (win.Handler,router.Handler):
            server=http.server.ThreadingHTTPServer(('127.0.0.1',0),handler)
            server.socket=context.wrap_socket(server.socket,server_side=True)
            server.requests=[];server.actions=[];server.mode='ok';server.receives=0
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start();servers.append(server);threads.append(thread)
        tcp=socket.socket();tcp.bind(('127.0.0.1',0)) # reserved, but never listening
        udp=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);udp.bind(('127.0.0.1',0)) # no SNMP replies
        def target(provider,port,typ,cred,profile,name,iface,ca=None):
            secret=folder/(provider+'.json');secret.write_text(json.dumps(cred));secret.chmod(0o600)
            return {'provider':provider,'host':'localhost' if provider=='winrm' else '127.0.0.1','port':port,'credential_type':typ,'secret_file':str(secret),'profile':profile,'ca_file':str(ca) if ca else None,'expected_name':name,'expected_interface':iface}
        config={'allowed_networks':['127.0.0.0/8','::1/128'],'python':sys.executable,'allow_routeros_export':True,'targets':[
            target('ssh',tcp.getsockname()[1],'ssh_password',{'username':'reader','password':'fixture-secret'},{'primary_pin':'1'*64,'identity_hash':'2'*64,'key_type':'ssh-ed25519'},'linux','eth0'),
            target('winrm',servers[0].server_port,'winrm',{'username':'test','password':'fixture-secret','auth':'basic'},{'primary_pin':pin,'identity_hash':hashlib.sha256(('winrm:'+win.DATA['identity'].replace('-','')).encode()).hexdigest()},'win-test','NIC',cert),
            target('snmp',udp.getsockname()[1],'snmp_v3',{'username':'reader','auth_protocol':'sha256','auth_passphrase':'fixture-auth','privacy_protocol':'aes128','privacy_passphrase':'fixture-priv'},{'engine_id':'8000000001','object_id':'.1.3.6.1.4.1.8072'},'switch','eth0'),
            target('routeros',servers[1].server_port,'routeros_api',{'username':'reader-12345678','password':'fixture-secret'},{'primary_pin':pin,'identity_hash':hashlib.sha256(b'routeros:router01').hexdigest()},'router01','ether1',cert),
        ]}
        path=folder/'acceptance.json'
        def run(value):
            path.write_text(json.dumps(value));return subprocess.run(['php',str(ROOT/'scripts/verify-os-network-live.php'),str(path)],capture_output=True,text=True,timeout=200)
        try:
            result=run(config);assert result.returncode==1,result.stderr
            assert 'fixture-secret' not in result.stdout+result.stderr and 'router01' not in result.stdout
            report=json.loads(result.stdout);assert not report['ok']
            rows={r['provider']:r for r in report['targets']}
            assert rows['winrm']['ok'] and rows['routeros']['ok'],report
            assert not rows['ssh']['ok'] and not rows['snmp']['ok'],report
            assert len(rows['winrm']['checks'])==10 and len(rows['routeros']['checks'])==6,report
            assert not router.Handler.routeros_files
            print('PASS real TLS driver acceptance and unavailable providers fail closed')
            count=len(servers[0].requests)
            for invalid in ({**config,'allow_routeros_export':False},{**config,'targets':config['targets'][:-1]},{**config,'targets':config['targets']+[config['targets'][0]]}):
                result=run(invalid);assert result.returncode==2 and not result.stdout and 'fixture-secret' not in result.stderr
            assert len(servers[0].requests)==count
            print('PASS missing provider, duplicate target and missing export consent rejected before network access')
            config['targets'][0]['secret_file']=str(folder/'ssh.json');(folder/'ssh.json').chmod(0o644)
            result=run(config);assert result.returncode==2 and not result.stdout
            print('PASS nonprivate credential refused')
        finally:
            tcp.close();udp.close()
            for server,thread in zip(servers,threads):server.shutdown();server.server_close();thread.join()

if __name__=='__main__':main()
