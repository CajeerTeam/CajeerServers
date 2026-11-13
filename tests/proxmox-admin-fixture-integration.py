#!/usr/bin/env python3
"""Exercise the exact staging fixture through the production PVE/PBS PHP drivers."""
import hashlib
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
use Cajeer\Servers\Connector\{ConnectorContext,ProxmoxConnector,PbsConnector,HttpsTransport,EndpointPolicy};
$transport=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),3);
$port=(int)$argv[1];$ca=$argv[2];$pin=$argv[3];
$pve=new ProxmoxConnector(null,$ca,$transport);
$c=new ConnectorContext('proxmox','fixture.test',$port,'proxmox_api',['cluster'=>null,'anchor_node'=>'cajeer-e2e-pve','anchor_id'=>null,'primary_pin'=>$pin,'fallbacks'=>[]]);
$secret='{"token_id":"cajeer-e2e@pve!fixture","token_secret":"fixture-only-cajeer-token"}';
function check($condition,$name){if(!$condition)throw new RuntimeException($name);echo "PASS $name\n";}
$s=$pve->cluster($c,$secret)->connection['cluster_discovery'];check($s['nodes'][0]['name']==='cajeer-e2e-pve'&&$s['details_complete'],'cluster');
$s=$pve->guests($c,$secret)->connection['guest_discovery'];check($s['guests'][0]['external_id']==='qemu:101'&&$s['guests'][0]['config']['disks'][0]['volume_id']==='local-lvm:vm-101-disk-0','guest inventory');
$s=$pve->storageNetwork($c,$secret)->connection['storage_network_discovery'];check($s['guests'][0]['disks'][0]['volume_resolved']&&$s['guests'][0]['nics'][0]['bridge_resolved'],'storage topology');
$r=$pve->guestAccess($c,'qemu:101','console',$secret);check(str_contains($r['url'],'console=kvm')&&!str_contains($r['url'],'fixture-only'),'native console');
$name='e2e_12345678';$r=$pve->prepareSnapshot($c,'vm.snapshot.create','qemu:101',$name,null,$secret);$task=$pve->dispatchAction($c,'vm.snapshot.create',$r,$secret);check($pve->actionStatus($c,$r['node'],$task,$secret)==='succeeded','snapshot create UPID');
$s=$pve->guestAccess($c,'qemu:101','snapshots',$secret);$rows=array_column($s['snapshots'],null,'name');check(isset($rows[$name]),'snapshot readback');
$r=$pve->prepareSnapshot($c,'vm.snapshot.delete','qemu:101',$name,$rows[$name]['revision'],$secret);$task=$pve->dispatchAction($c,'vm.snapshot.delete',$r,$secret);check($pve->actionStatus($c,$r['node'],$task,$secret)==='succeeded','snapshot delete UPID');
$s=$pve->tasks($c,$secret,[])->connection['task_discovery'];check($s['observed_count']===2&&count($s['tasks'])===2,'task archive');
$pbs=new PbsConnector($transport,$ca);$ctx=new ConnectorContext('pbs','fixture.test',$port,'api_token');
$s=$pbs->execute($ctx,'inspect','cajeer-e2e@pbs!fixture:fixture-only-pbs-token')->connection['pbs_discovery'];check($s['complete']&&$s['counts']['datastores']===1&&$s['counts']['snapshots']===2&&$s['counts']['jobs']===4,'PBS discovery');
'''
with tempfile.TemporaryDirectory() as tmp:
    key, cert = pathlib.Path(tmp)/'key.pem', pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=fixture.test','-addext','subjectAltName=DNS:fixture.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),fixture.Handler)
    ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    try:
        subprocess.run(['php','-r',PHP,str(server.server_port),str(cert),pin],cwd=ROOT,check=True,timeout=90)
        assert not fixture.Handler.snapshots
        print('Proxmox admin fixture HTTPS: 9 PASS')
    finally:
        server.shutdown();server.server_close();thread.join()
