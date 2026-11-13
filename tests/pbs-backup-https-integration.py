#!/usr/bin/env python3
"""Exercise production PBS driver and collector through verified local HTTPS."""
import http.server, json, pathlib, ssl, subprocess, tempfile, threading, os, sys
ROOT = pathlib.Path(__file__).resolve().parents[1]
TOKEN = 'svc@pbs!read:fixture-secret'
class Handler(http.server.BaseHTTPRequestHandler):
    mode='ok'; requests=[]
    def log_message(self,*args): pass
    def do_POST(self):
        self.requests.append(('POST',self.path)); self.send_error(405)
    def do_GET(self):
        self.requests.append(('GET',self.path))
        if self.headers.get('Authorization')!='PBSAPIToken='+TOKEN or self.mode=='auth': self.send_error(401);return
        p=self.path.removeprefix('/api2/json')
        if self.mode=='acl' and p.endswith('/groups'): self.send_error(403);return
        if self.mode=='bad' and p.endswith('/status'): self.send_response(200);self.end_headers();self.wfile.write(b'{"data":null}');return
        if self.mode=='redirect' and p=='/version': self.send_response(302);self.send_header('Location','https://evil.invalid/');self.end_headers();return
        base='/admin/datastore/vault'
        job={'id':'job1','store':'vault','schedule':'daily','last-run-state':'OK','last-run-endtime':1700000000,'next-run':1700086400}
        data={ '/version':{'version':'3.4.4' if self.mode=='pbs3' else '4.1.1'}, '/admin/datastore':[{'store':'vault'}], '/admin/sync':[dict(job,remote='offsite',**{'remote-store':'remote'})], '/admin/verify':[job], '/admin/prune':[dict(job,**{'keep-last':7})], base+'/status':{'total':18446744073709551615,'used':100,'avail':200}, base+'/gc':{'store':'vault','schedule':'daily','last-run-state':'OK','upid':'UPID:pbs:fixture','removed-bytes':20},base+'/namespace?max-depth=7':[{'ns':''},{'ns':'prod/eu'}] }
        for q in ['', '?ns=prod%2Feu']:
            data[base+'/groups'+q]=[{'backup-type':'vm','backup-id':'101','last-backup':1700000000,'backup-count':1}]
            data[base+'/snapshots'+q]=[{'backup-type':'vm','backup-id':'101','backup-time':1700000000,'size':9007199254740993,'protected':True,'verification':{'state':'ok'},'comment':'hidden fixture','files':[{'filename':'hidden filename'}]}]
        if p not in data: self.send_error(404);return
        body=json.dumps({'data':data[p]}).encode();self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
PHP=r'''
require 'core/autoload.php';
use Cajeer\Servers\Connector\{PbsConnector,EndpointPolicy,HttpsTransport,ConnectorContext,ConnectorTransportException};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Collector\{CollectorRegistry,CollectionRuntimeService,CollectionPlan};
$mode=$argv[1];$ca=$argv[2];$port=(int)$argv[3];
$https=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn($h)=>['127.0.0.1'],true),3);
$driver=new PbsConnector($https,$mode==='untrusted'?null:$ca);
$host=$mode==='hostname'?'wrong.test':'pbs.test';$secret='svc@pbs!read:fixture-secret';
$ctx=new ConnectorContext('pbs',$host,$port,'api_token');
$registry=new ConnectorRegistry($driver);
try {
 if($mode==='health'||$mode==='rotation'){$result=$registry->execute($ctx,$mode==='health'?'health':'validate_rotation','api',$secret);if(count($result->items)!==1)throw new LogicException('missing probe');exit;}
 $runtime=new CollectionRuntimeService(CollectorRegistry::forConnectors($registry));
 $result=$runtime->collect(new CollectionPlan('pbs.backups','inspect'),['provider'=>'pbs','endpoint_host'=>$host,'endpoint_port'=>$port,'credential_type'=>'api_token','purpose'=>'api'],'api_token',$secret);
 $s=$result->connection['pbs_discovery'];
 if(!in_array($mode,['ok','pbs3','acl'],true))throw new LogicException('unexpected success');
 if($s['complete']!==($mode!=='acl')||$s['counts']['snapshots']!==2||$s['counts']['jobs']!==4||$s['datastores'][0]['total']!=='18446744073709551615'||$s['snapshots'][0]['size']!=='9007199254740993'||str_contains(json_encode($s),'hidden fixture'))throw new LogicException('invalid result');
 if($mode==='acl'&&$s['counts']['groups']!==1)throw new LogicException('invalid ACL');
} catch(\Cajeer\Servers\Http\Exception\HttpException $e) {
 $expected=match($mode){'auth'=>'connector_auth_failed','bad','redirect'=>'connector_protocol_error','hostname','untrusted'=>'connector_trust_failed',default=>null};
 if($e->errorCode!==$expected)throw $e;
}
'''
with tempfile.TemporaryDirectory() as tmp:
    key=pathlib.Path(tmp)/'key.pem';cert=pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=pbs.test','-addext','subjectAltName=DNS:pbs.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True);threading.Thread(target=server.serve_forever,daemon=True).start()
    try:
        for mode in ['ok','pbs3','acl','auth','bad','redirect','hostname','untrusted','health','rotation']:
            Handler.mode=mode;Handler.requests=[]
            subprocess.run(['php','-r',PHP,mode,str(cert),str(server.server_port)],cwd=ROOT,check=True,timeout=45)
            assert all(method=='GET' for method,_ in Handler.requests)
            if mode in ['ok','pbs3']: assert ('GET','/api2/json/admin/datastore/vault/snapshots?ns=prod%2Feu') in Handler.requests
            if mode in ['health','rotation']: assert len(Handler.requests)==2
        print('PBS HTTPS integration: 10 PASS')
        if '--postgres' in sys.argv:
            Handler.mode='ok';env=dict(os.environ,CAJEER_PBS_TEST_CA=str(cert),CAJEER_PBS_TEST_PORT=str(server.server_port))
            subprocess.run(['php','tests/pbs-backup-postgres-integration.php'],cwd=ROOT,env=env,check=True,timeout=120)
    finally:server.shutdown();server.server_close()
