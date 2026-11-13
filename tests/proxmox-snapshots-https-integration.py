#!/usr/bin/env python3
"""Real TLS transport and PVE snapshot protocol. No production cluster required."""
import hashlib,http.server,json,pathlib,ssl,subprocess,tempfile,threading,urllib.parse,re,time,socket
ROOT=pathlib.Path(__file__).resolve().parents[1];NODE='pve-test';AUTH='PVEAPIToken=svc@pve!test=fixture-secret'
class Handler(http.server.BaseHTTPRequestHandler):
    calls=[];mode='ok';reads=0;counter=0;snaps={'qemu':{},'lxc':{}};tasks={}
    def log_message(self,*args):pass
    def reply(self,data):
        b=json.dumps({'data':data}).encode();self.send_response(200);self.send_header('Content-Length',str(len(b)));self.end_headers();self.wfile.write(b)
    def auth(self):
        self.calls.append((self.command,self.path));return self.headers.get('Authorization')==AUTH
    def do_GET(self):
        if not self.auth():self.send_error(401);return
        path=urllib.parse.unquote(self.path)
        if path=='/api2/json/version':self.reply({'version':'9.0.3'})
        elif path=='/api2/json/cluster/status':self.reply([{'type':'node','name':NODE,'local':1,'online':1}])
        elif path=='/api2/json/nodes':self.reply([{'node':NODE,'status':'online'}])
        elif path=='/api2/json/cluster/resources?type=vm':self.reply([{'type':k,'node':NODE,'vmid':v,'status':'stopped'} for k,v in [('qemu',101),('lxc',102)]])
        elif (m:=re.fullmatch(r'/api2/json/nodes/pve-test/(qemu|lxc)/(101|102)/(config|status/current|snapshot|snapshot/[^/]+/config)',path)):
            kind,vmid,end=m.groups()
            if end=='config':self.reply({'digest':'a'*40,'lock':'backup'} if self.mode=='locked' else {'digest':'a'*40})
            elif end=='status/current':self.reply({'vmid':int(vmid),'status':'running' if self.mode=='running' else 'stopped','qmpstatus':'running','ha':{'managed':0}})
            elif end=='snapshot':
                if self.mode=='denied':self.send_error(403);return
                Handler.reads+=1
                if self.mode=='race' and Handler.reads>=3:self.snaps[kind]['before']['description']='changed'
                self.reply([*self.snaps[kind].values(),{'name':'current'}])
            else:self.reply({'vmstate':'local:ram'} if self.mode=='ram' else {'memory':1024})
        elif '/tasks/' in path and path.endswith('/status'):
            task=path.split('/tasks/')[1][:-7];self.reply(self.tasks[task])
        else:self.send_error(404)
    def do_POST(self):self.mutate()
    def do_DELETE(self):self.mutate()
    def mutate(self):
        if not self.auth():self.send_error(401);return
        m=re.fullmatch(r'/api2/json/nodes/pve-test/(qemu|lxc)/(101|102)/snapshot(?:/([A-Za-z][A-Za-z0-9_-]*)(/rollback)?)?',self.path)
        if not m:self.send_error(400);return
        kind,vmid,name,rollback=m.groups();body=self.rfile.read(int(self.headers.get('Content-Length','0'))).decode();op='rollback' if rollback else 'delete' if name else 'create'
        assert self.command==('DELETE' if op=='delete' else 'POST')
        if op=='create':
            assert self.headers.get('Content-Type')=='application/x-www-form-urlencoded';params=urllib.parse.parse_qs(body);assert list(params)==['snapname'];name=params['snapname'][0];self.snaps[kind][name]={'name':name,'snaptime':123,'description':''}
        else:
            assert body=='' and name in self.snaps[kind]
            if op=='delete':del self.snaps[kind][name]
        Handler.counter+=1;worker=('qm' if kind=='qemu' else 'vz')+{'create':'snapshot','delete':'delsnapshot','rollback':'rollback'}[op]
        task=f'UPID:{NODE}:{self.counter:08X}:00000002:{int(time.time()):08X}:{worker}:{vmid}:svc@pve!test:'
        self.tasks[task]={'status':'stopped','exitstatus':'OK','upid':task,'node':NODE}
        if self.mode=='lost':self.connection.shutdown(socket.SHUT_RDWR);self.connection.close();return
        self.reply(task if self.mode!='bad-upid' else task.replace(worker,'qmstart'))
code=r'''
require $argv[1].'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ProxmoxConnector,HttpsTransport,EndpointPolicy,ConnectorActionNotSentException};
$d=new ProxmoxConnector(null,$argv[3],new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),3));
$ctx=new ConnectorContext('proxmox','connector.test',(int)$argv[2],'proxmox_api',['cluster'=>null,'anchor_node'=>'pve-test','anchor_id'=>null,'primary_pin'=>$argv[4],'fallbacks'=>[]]);
$secret='{"token_id":"svc@pve!test","token_secret":"fixture-secret"}';[$mode,$action,$external]=array_slice($argv,5);$rev=null;
try{
 if($mode==='console'){$r=$d->guestAccess($ctx,$external,'console',$secret);if(!str_contains($r['url'],str_starts_with($external,'qemu:')?'console=kvm':'console=lxc')||str_contains(json_encode($r),'fixture-secret'))throw new LogicException('console link');return;}
 if(!str_ends_with($action,'.create')){$r=$d->guestAccess($ctx,$external,'snapshots',$secret);foreach($r['snapshots'] as $s)if($s['name']==='before')$rev=$s['revision'];}
 $r=$d->prepareSnapshot($ctx,$action,$external,'before',$rev,$secret);
 $task=$d->dispatchAction($ctx,$action,$r,$secret);
 if($d->actionStatus($ctx,$r['node'],$task,$secret)!=='succeeded')throw new LogicException('task status');
 if($mode!=='ok')throw new LogicException('unsafe success');
}catch(Throwable $e){if(in_array($mode,['ok','console'],true)||$e instanceof LogicException)throw $e;if($mode==='race'&&!$e instanceof ConnectorActionNotSentException)throw $e;if(in_array($mode,['lost','bad-upid'],true)&&$e instanceof ConnectorActionNotSentException)throw $e;}
'''
with tempfile.TemporaryDirectory() as tmp:
    key,cert=pathlib.Path(tmp)/'key.pem',pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=connector.test','-addext','subjectAltName=DNS:connector.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest();server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True);threading.Thread(target=server.serve_forever,daemon=True).start()
    n=0
    def run(mode,action,external,writes):
        global n
        Handler.mode=mode;Handler.calls.clear();Handler.reads=0
        subprocess.run(['php','-r',code,str(ROOT),str(server.server_port),str(cert),pin,mode,action,external],check=True,timeout=35)
        actual=[x for x in Handler.calls if x[0]!='GET'];assert len(actual)==writes,(mode,actual);n+=1
    try:
        for prefix,external,kind in [('vm','qemu:101','qemu'),('lxc','lxc:102','lxc')]:
            run('console',prefix+'.snapshot.create',external,0)
            run('ok',prefix+'.snapshot.create',external,1);assert 'before' in Handler.snaps[kind]
            run('ok',prefix+'.snapshot.rollback',external,1)
            run('ok',prefix+'.snapshot.delete',external,1);assert 'before' not in Handler.snaps[kind]
        run('ok','vm.snapshot.create','qemu:101',1)
        for mode in ['running','ram','locked','denied','race']:run(mode,'vm.snapshot.rollback','qemu:101',0)
        run('lost','vm.snapshot.delete','qemu:101',1);assert 'before' not in Handler.snaps['qemu']
        run('bad-upid','vm.snapshot.create','qemu:101',1)
        print(f'Proxmox snapshot HTTPS: {n} PASS')
    finally:server.shutdown();server.server_close()
