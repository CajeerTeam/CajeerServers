#!/usr/bin/env python3
"""Verify the exact disposable staging RouterOS fixture through the production PHP transport and collectors."""
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
use Cajeer\Servers\Connector\{RouterOsConnector,HttpsTransport,EndpointPolicy,ConnectorContext,NetworkConfigurationBackup};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Collector\{CollectorRegistry,CollectionRuntimeService,CollectionPlan};
use Cajeer\Servers\Inventory\NetworkObservation;
function check($condition,$name){if(!$condition)throw new RuntimeException($name);echo "PASS $name\n";}
$port=(int)$argv[1];$ca=$argv[2];$pin=$argv[3];
$transport=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1'],true),3);
$driver=new RouterOsConnector($transport,$ca);$registry=new ConnectorRegistry($driver);
$runtime=new CollectionRuntimeService(CollectorRegistry::forConnectors($registry));
$profile=['primary_pin'=>$pin,'identity_hash'=>hash('sha256','routeros:router01')];
$target=['provider'=>'routeros','endpoint_host'=>'fixture.test','endpoint_port'=>$port,'credential_type'=>'routeros_api','purpose'=>'api','os_network_profile'=>$profile];
$secret=json_encode(['username'=>'reader-12345678','password'=>'fixture-secret']);
$collect=fn($name,$secret)=>$runtime->collect(new CollectionPlan('routeros.'.$name,'inspect'),$target,'routeros_api',$secret);
$inventory=$collect('inventory',$secret)->connection['routeros_discovery'];check($inventory['complete']&&$inventory['sections']['identity'][0]['name']==='router01','inventory fixture');
$network=$collect('network',$secret)->connection['routeros_discovery'];check($network['complete']&&$network['sections']['interfaces'][0]['name']==='ether1','network fixture');
$projection=NetworkObservation::project(['id'=>'routeros-fixture','provider'=>'routeros','name'=>'router01'],$network,'2026-11-14T12:00:00Z');check(count($projection['interfaces'])===1&&count($projection['addresses'])===2,'topology projection');
$metrics=$collect('metrics',$secret)->metricSamples;check(in_array('18446744073709551615',array_column($metrics,'value'),true),'Counter64 metrics fixture');
$backup=fn($secret)=>$collect('configuration-backup',$secret)->connection['network_configuration_backup'];
$a=$backup($secret);$b=$backup($secret);$c=$backup($secret);
check(NetworkConfigurationBackup::canonical($a)===NetworkConfigurationBackup::canonical($b),'unchanged export');
$diff=NetworkConfigurationBackup::diff($a,$c);check($diff['added']===1&&$diff['removed']===1&&str_contains($c,'action=drop'),'changed export diff');
$d=$backup(json_encode(['username'=>'reader-abcdef01','password'=>'fixture-secret']));check(str_contains($d,'action=accept'),'independent browser fixture account');
try{$backup(json_encode(['username'=>'reader-abcdef01','password'=>'wrong']));throw new RuntimeException('invalid credentials accepted');}catch(\Cajeer\Servers\Http\Exception\HttpException $e){check($e->status>=400,'invalid credential rejected');}
'''
with tempfile.TemporaryDirectory() as tmp:
    key,cert=pathlib.Path(tmp)/'key.pem',pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=fixture.test','-addext','subjectAltName=DNS:fixture.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),fixture.Handler)
    ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    try:
        subprocess.run(['php','-r',PHP,str(server.server_port),str(cert),pin],cwd=ROOT,check=True,timeout=90)
        assert not fixture.Handler.routeros_files, 'temporary files leaked'
        assert fixture.Handler.routeros_exports=={'reader-12345678':3,'reader-abcdef01':1}
        print('OS / Network staging TLS fixture: 9 PASS')
    finally:
        server.shutdown();server.server_close();thread.join()
