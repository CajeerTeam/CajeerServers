#!/usr/bin/env python3
"""Real independent TLS endpoints; verify no credential is sent on pin mismatch."""
import hashlib, http.server, json, pathlib, ssl, subprocess, tempfile, threading, urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[1]
TASK='UPID:pve-a:00000001:00000002:00000003:qmstart:100:svc@pve!read:'
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def reply(self,data):
        b=json.dumps({'data':data}).encode();self.send_response(200);self.send_header('Content-Length',str(len(b)));self.end_headers();self.wfile.write(b)
    def do_GET(self):
        self.server.calls.append(('GET',self.path))
        if self.headers.get('Authorization')!='PVEAPIToken=svc@pve!read=fixture-secret':self.send_error(401);return
        if self.server.mode=='auth':self.send_error(403);return
        if self.path=='/api2/json/version':self.reply({'version':'9.0.3'})
        elif self.path=='/api2/json/cluster/status':self.reply([{'type':'cluster','name':'other' if self.server.mode=='wrong' else 'prod','quorate':1},{'type':'node','name':'pve-a','nodeid':1,'local':int(self.server.node=='pve-a')},{'type':'node','name':'pve-b','nodeid':2,'local':int(self.server.node=='pve-b')}])
        elif self.path=='/api2/json/nodes':self.reply([{'node':'pve-a','status':'online'},{'node':'pve-b','status':'online'}])
        elif self.path=='/api2/json/access/permissions':self.reply({'/':{'Sys.Audit':1}})
        elif self.path=='/api2/json/cluster/resources?type=vm':
            if self.server.mode=='late':self.send_error(503);return
            self.reply([{'type':'qemu','node':'pve-a','vmid':100,'status':'running'}])
        elif urllib.parse.unquote(self.path)==f'/api2/json/nodes/pve-a/tasks/{TASK}/status':self.reply({'status':'stopped','exitstatus':'OK'})
        else:self.send_error(404)
    def do_POST(self):
        self.server.calls.append(('POST',self.path));self.reply(TASK)
with tempfile.TemporaryDirectory() as tmp:
    servers=[];threads=[];pins=[];certs=[]
    try:
        for node in ['pve-a','pve-b']:
            cert=pathlib.Path(tmp)/f'{node}.pem';key=pathlib.Path(tmp)/f'{node}.key'
            subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj',f'/CN={node}.test','-addext',f'subjectAltName=DNS:{node}.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            pins.append(hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest());certs.append(cert.read_text())
            server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);server.calls=[];server.mode='ok';server.node=node
            context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(cert,key);server.socket=context.wrap_socket(server.socket,server_side=True)
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start();servers.append(server);threads.append(thread)
        ca=pathlib.Path(tmp)/'ca.pem';ca.write_text(''.join(certs))
        code=r'''
require $argv[1].'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ProxmoxConnector,EndpointPolicy,HttpsTransport,ConnectorTransportException};
$profile=['cluster'=>'prod','anchor_node'=>'pve-a','anchor_id'=>1,'primary_pin'=>$argv[5],'fallbacks'=>[['host'=>'pve-b.test','port'=>(int)$argv[3],'pin'=>$argv[6]]]];
$mode=$argv[7];if($mode==='pin')$profile['primary_pin']=str_repeat('0',64);
$ctx=new ConnectorContext('proxmox','pve-a.test',(int)$argv[2],'proxmox_api',$profile);
$d=new ProxmoxConnector(null,$argv[4],new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),2));
$secret='{"token_id":"svc@pve!read","token_secret":"fixture-secret"}';
try{
if($mode==='post'){$r=$d->prepareAction($ctx,'vm.start','qemu:100',$secret);$upid=$d->dispatchAction($ctx,'vm.start',$r,$secret);if($d->actionStatus($ctx,'pve-a',$upid,$secret)!=='succeeded')throw new RuntimeException('task');}
elseif($mode==='write-down'){$d->dispatchAction($ctx,'vm.start',['node'=>'pve-a','vmid'=>100],$secret);throw new LogicException('write unexpectedly succeeded');}
else{$result=$d->execute($ctx,$mode==='health'?'health':'inspect',$secret);if($mode==='late'){if(!$result->connection['failover']||count($result->items)!==3||$result->complete)throw new RuntimeException('failover failed');}
elseif($mode==='health'){if($result->connection['version']!=='9.0.3'||$result->connection['root_privileges']!==['Sys.Audit'])throw new RuntimeException('diagnostics');}
elseif($mode==='down'){if(!$result->connection['failover'])throw new RuntimeException('connection failover');}
else throw new LogicException('unexpected success');}
}catch(ConnectorTransportException $e){$expected=match($mode){'pin','wrong'=>'connector_trust_failed','auth'=>'connector_auth_failed','write-down'=>'connector_transport_failed',default=>''};if($e->category!==$expected)throw $e;}
'''
        def run(mode,port=None):
            subprocess.run(['php','-r',code,str(ROOT),str(port or servers[0].server_port),str(servers[1].server_port),str(ca),*pins,mode],check=True,timeout=30)
        run('health');assert len(servers[0].calls)==4 and not servers[1].calls
        servers[0].calls.clear();run('pin');assert not servers[0].calls and not servers[1].calls
        servers[0].mode='auth';run('auth');assert len(servers[0].calls)==1 and not servers[1].calls
        servers[0].calls.clear();servers[0].mode='wrong';run('wrong');assert len(servers[0].calls)==2 and not servers[1].calls
        servers[0].calls.clear();servers[0].mode='late';run('late');assert len(servers[0].calls)==4 and len(servers[1].calls)==4
        servers[0].calls.clear();servers[1].calls.clear();servers[0].mode='ok';run('post');assert sum(m=='POST' for m,p in servers[0].calls)==1 and not servers[1].calls
        # A closed port is an actual connection failure; the configured fallback stays reachable.
        primary_port=servers[0].server_port;servers[0].shutdown();servers[0].server_close();threads[0].join(5)
        run('down',primary_port);before=len(servers[1].calls);run('write-down',primary_port);assert len(servers[1].calls)==before
        print('Proxmox profile HTTPS: 8 scenarios PASS (pin before auth, identity, auth, failover, single POST, no write failover)')
    finally:
        for server,thread in zip(servers,threads):
            if thread.is_alive():server.shutdown();server.server_close();thread.join(5)
