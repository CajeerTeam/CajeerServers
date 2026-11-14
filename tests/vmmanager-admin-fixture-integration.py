#!/usr/bin/env python3
"""Exercise the exact staging fixture through the production VMmanager PHP driver."""
import hashlib
import json
import http.server
import importlib.util
import pathlib
import ssl
import subprocess
import tempfile
import threading

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('e2e_fixture', ROOT/'tests/connector-e2e-fixture.py')
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)
PHP = r'''
require 'core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,VmmanagerConnector,HttpsTransport,EndpointPolicy};
$transport=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),3);
$driver=new VmmanagerConnector($transport,$argv[2],true);$ctx=new ConnectorContext('vmmanager','fixture.test',(int)$argv[1],'api_token',null,['primary_pin'=>$argv[3],'anchor_id'=>1,'anchor_node'=>'cajeer-e2e-vmm-node-1']);
$token='fixture-vmm-e2e-'.str_repeat('a',32);$other='fixture-vmm-e2e-'.str_repeat('b',32);
function check($c,$name){if(!$c)throw new RuntimeException($name);echo "PASS $name\n";}
$s=$driver->cluster($ctx,$token)->connection['vmmanager_cluster_discovery'];check($s['complete']&&$s['counts']['clusters']===2,'cluster discovery');
$s=$driver->guests($ctx,$token)->connection['vmmanager_guest_discovery'];check($s['complete']&&$s['counts']['guests']===2&&$s['guests'][0]['external_id']==='host:21','guest discovery');
$s=$driver->network($ctx,$token)->connection['vmmanager_network_discovery'];check($s['complete']&&count($s['networks'])===1,'network discovery');
$s=$driver->storage($ctx,$token)->connection['vmmanager_storage_discovery'];check($s['complete']&&$s['counts']['volumes']===2,'storage discovery');
$s=$driver->templates($ctx,$token)->connection['vmmanager_templates_discovery'];check($s['complete']&&$s['counts']['os']===1,'template discovery');
$r=$driver->prepareAction($ctx,'vmmanager.host.start','host:21',$token);$task=$driver->dispatchAction($ctx,'vmmanager.host.start',$r,$token);check($driver->actionStatus($ctx,$r['node'],$task,$token)==='succeeded','start and live state');
$s=$driver->tasks($ctx,$token,[])->connection['vmmanager_task_discovery'];check($s['observed_count']===1&&$s['tasks'][0]['type']==='host_start','task history');
$s=$driver->tasks($ctx,$token,['cursor'=>$s['cursor'],'revision'=>1,'actions'=>[['id'=>'fixture','node'=>$r['node'],'remote_task'=>$task]]])->connection['vmmanager_task_discovery'];check($s['action_checks'][0]['status']==='succeeded','worker reconciliation');
$s=$driver->guests($ctx,$other)->connection['vmmanager_guest_discovery'];check($s['guests'][0]['state']==='stopped','token isolation');
$r=$driver->prepareAction($ctx,'vmmanager.host.start','host:22',$other);$task=$driver->dispatchAction($ctx,'vmmanager.host.start',$r,$other);check($driver->actionStatus($ctx,$r['node'],$task,$other)==='succeeded','LXD start');
'''
with tempfile.TemporaryDirectory() as tmp:
    key, cert = pathlib.Path(tmp)/'key.pem', pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=fixture.test','-addext','subjectAltName=DNS:fixture.test,IP:127.0.0.1'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),fixture.Handler)
    ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    try:
        subprocess.run(['php','-r',PHP,str(server.server_port),str(cert),pin],cwd=ROOT,check=True,timeout=90)
        print('VMmanager admin fixture HTTPS: 10 PASS')
    finally:
        server.shutdown();server.server_close();thread.join()
