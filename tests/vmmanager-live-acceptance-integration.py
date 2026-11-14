#!/usr/bin/env python3
"""Test the actual acceptance CLI against a TLS fixture; no remote writes allowed."""
import hashlib
import http.server
import importlib.util
import json
import pathlib
import ssl
import subprocess
import tempfile
import threading

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('fixture', ROOT/'tests/connector-e2e-fixture.py')
fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)
TOKEN='fixture-vmm-e2e-'+'c'*32
class Handler(fixture.Handler):
    writes=0
    change_identity=False
    whoami_calls=0
    def vmm_get(self, token):
        if self.path=='/auth/v4/whoami' and Handler.change_identity:
            Handler.whoami_calls+=1
            if Handler.whoami_calls>2:
                return self.vmm_reply({'owner_id':8,'roles':['@admin']})
        return super().vmm_get(token)
    def do_POST(self):
        Handler.writes+=1
        self.vmm_reply({'error':{'code':403}},403)

with tempfile.TemporaryDirectory() as tmp:
    root=pathlib.Path(tmp);key=root/'key.pem';cert=root/'cert.pem';secret=root/'secret';config=root/'acceptance.json'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
    secret.write_text(TOKEN);secret.chmod(0o600)
    Handler.vmm_tasks[TOKEN]=[dict(id=9,consul_id=100009,name='host_start',status='complete',host={'id':21})]
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    data={'allowed_networks':['127.0.0.1/32'],'target':{'host':'127.0.0.1','port':server.server_port,'ca_file':str(cert),'secret_file':str(secret),'credential_type':'api_token','profile':{'primary_pin':pin,'anchor_id':1,'anchor_node':'cajeer-e2e-vmm-node-1'}},'expect':{'owner_id':7,'kvm_host':21,'lxd_host':22,'storage_id':1,'os_id':1,'preset_id':1,'image_id':1,'iso_id':1,'network_id':1,'pool_id':1,'address':'192.0.2.21','task_id':9}}
    def run(code, label, failed_check=None):
        config.write_text(json.dumps(data));p=subprocess.run(['php','scripts/verify-vmmanager-live.php',str(config)],cwd=ROOT,text=True,capture_output=True,timeout=90)
        assert p.returncode==code,(label,p.returncode,p.stdout,p.stderr)
        assert TOKEN not in p.stdout+p.stderr and Handler.writes==0
        if code!=2:
            report=json.loads(p.stdout);assert report['ok']==(code==0),report
            if code==0: assert len(report['checks'])==10,report
            if failed_check: assert any(c['name']==failed_check and not c['ok'] for c in report['checks']),report
        print('PASS '+label)
    try:
        run(0,'actual pinned driver live acceptance')
        data['target']['profile']['primary_pin']='0'*64;run(1,'wrong TLS pin','vmmanager.authentication');data['target']['profile']['primary_pin']=pin
        data['expect']['owner_id']=8;run(1,'wrong identity','vmmanager.authentication');data['expect']['owner_id']=7
        data['expect']['storage_id']=99;run(1,'missing storage','vmmanager.storage');data['expect']['storage_id']=1
        data['expect']['address']='192.0.2.22';run(1,'IP belongs to other guest','vmmanager.network');data['expect']['address']='192.0.2.21'
        data['expect']['task_id']=10;run(1,'missing task','vmmanager.tasks');data['expect']['task_id']=9
        Handler.change_identity=True;run(1,'identity changed after health','vmmanager.clusters');Handler.change_identity=False
        secret.chmod(0o644);run(2,'unsafe credential permissions');secret.chmod(0o600)
        data['allowed_networks']=[];run(2,'empty allowlist')
        print('VMmanager live acceptance CLI: 9 PASS')
    finally:
        server.shutdown();server.server_close();thread.join()
