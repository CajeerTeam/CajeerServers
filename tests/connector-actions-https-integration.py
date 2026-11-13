#!/usr/bin/env python3
"""Real TLS requests, eight power operations, fail-closed checks and no POST retry."""
import hashlib, http.server, json, pathlib, ssl, subprocess, tempfile, threading, urllib.parse, re, time, socket
ROOT=pathlib.Path(__file__).resolve().parents[1]
NODE='pve-test'
AUTH='PVEAPIToken=svc@pve!test=fixture-secret'
class Handler(http.server.BaseHTTPRequestHandler):
    calls=[]; state={'qemu':'stopped','lxc':'stopped'}; tasks={}; mode='ok'; config_reads=0; counter=0
    def log_message(self,*args): pass
    def reply(self,data):
        b=json.dumps({'data':data}).encode();self.send_response(200);self.send_header('Content-Length',str(len(b)));self.end_headers();self.wfile.write(b)
    def do_GET(self):
        self.calls.append(('GET',self.path));path=urllib.parse.unquote(self.path)
        if path=='/inventory/v1/servers':
            if self.headers.get('Authorization')!='Bearer fixture-demo': self.send_error(401);return
            self.reply([{'id':'s1','name':'server-one','status':'online'}]);return
        if self.headers.get('Authorization')!=AUTH: self.send_error(401);return
        mode=Handler.mode
        if path=='/api2/json/version': self.reply({'version':'9.0.3'})
        elif path=='/api2/json/cluster/status': self.reply([{'type':'node','name':NODE,'local':1,'online':1}])
        elif path=='/api2/json/nodes': self.reply([{'node':NODE,'status':'offline' if mode=='offline' else 'online'}])
        elif path=='/api2/json/cluster/resources?type=vm': self.reply([{'type':kind,'node':NODE,'vmid':101 if kind=='qemu' else 102,'status':state} for kind,state in self.state.items()])
        elif (m:=re.fullmatch(r'/api2/json/nodes/pve-test/(qemu|lxc)/(101|102)/(config|status/current)',path)):
            kind,vmid,endpoint=m.groups()
            if endpoint=='config':
                Handler.config_reads+=1
                if mode=='denied':self.send_error(403);return
                cfg={'digest':('b' if mode=='race' and Handler.config_reads>1 else 'a')*40}
                if mode=='locked':cfg['lock']='backup'
                if mode=='template':cfg['template']=1
                self.reply(cfg)
            else:
                self.reply({'vmid':int(vmid),'status':self.state[kind],'qmpstatus':'paused' if mode=='paused' else self.state[kind],'ha':{'managed':int(mode=='ha')}})
        elif path.startswith(f'/api2/json/nodes/{NODE}/tasks/') and path.endswith('/status'):
            task=path.split('/tasks/',1)[1][:-len('/status')]
            if task not in self.tasks:self.send_error(404);return
            data=self.tasks[task].copy()
            if mode=='bad-status':data['upid']='UPID:wrong'
            self.reply(data)
        else: self.send_error(404)
    def do_POST(self):
        self.calls.append(('POST',self.path))
        if self.headers.get('Authorization')!=AUTH:self.send_error(401);return
        m=re.fullmatch(r'/api2/json/nodes/pve-test/(qemu|lxc)/(101|102)/status/(start|stop|shutdown|reboot)',self.path)
        if not m or int(self.headers.get('Content-Length','0'))!=0:self.send_error(400);return
        kind,vmid,op=m.groups();Handler.counter+=1
        self.state[kind]='running' if op in ('start','reboot') else 'stopped'
        worker=('qm' if kind=='qemu' else 'vz')+op
        node='wrong' if self.mode=='bad-upid' else NODE
        task=f'UPID:{node}:{Handler.counter:08X}:00000002:{int(time.time()):08X}:{worker}:{vmid}:svc@pve!test:'
        self.tasks[task]={'status':'stopped','exitstatus':'OK','upid':task,'node':NODE}
        if self.mode=='lost':
            self.connection.shutdown(socket.SHUT_RDWR);self.connection.close();return
        self.reply(task)
with tempfile.TemporaryDirectory() as tmp:
    key,cert=pathlib.Path(tmp)/'key.pem',pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=connector.test','-addext','subjectAltName=DNS:connector.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    try:
        code=r'''
require $argv[1].'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ProxmoxConnector,HttpsTransport,EndpointPolicy,ConnectorActionNotSentException};
use Cajeer\Servers\Integration\{ConnectorRegistry,ConnectorExtensionLoader};
$transport=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),3);
$d=new ProxmoxConnector(null,$argv[3],$transport);$ctx=new ConnectorContext('proxmox','connector.test',(int)$argv[2],'proxmox_api',['cluster'=>null,'anchor_node'=>'pve-test','anchor_id'=>null,'primary_pin'=>$argv[4],'fallbacks'=>[]]);
$secret='{"token_id":"svc@pve!test","token_secret":"fixture-secret"}';
$mode=$argv[5];$action=$argv[6];$external=$argv[7];
if($mode==='sdk'){
    putenv('DEMO_CONNECTOR_CA_FILE='.$argv[3]);$r=ConnectorExtensionLoader::load(new ConnectorRegistry($d),$argv[1].'/examples/extensions/demo-connector/entry.php',$transport);
    $res=$r->execute(new ConnectorContext('custom','connector.test',(int)$argv[2],'api_token'),'sync','api','fixture-demo');
    if(count($res->items)!==1||$res->items[0]['external_id']!=='server:s1')throw new RuntimeException('SDK observation failed');return;
}
try{
    $remote=$d->prepareAction($ctx,$action,$external,$secret);
    $task=$d->dispatchAction($ctx,$action,$remote,$secret);
    if($d->actionStatus($ctx,$remote['node'],$task,$secret)!=='succeeded')throw new RuntimeException('Task result failed');
    if($mode!=='ok')throw new LogicException('Unsafe action unexpectedly succeeded: '.$mode);
}catch(\Throwable $e){
    if($mode==='ok'||$e instanceof LogicException)throw $e;
    if($mode==='race'&&!$e instanceof ConnectorActionNotSentException)throw $e;
    if(in_array($mode,['lost','bad-upid'],true)&&$e instanceof ConnectorActionNotSentException)throw $e;
}
'''
        def run(mode,action='vm.start',external='qemu:101',posts=0):
            Handler.mode=mode;Handler.calls.clear();Handler.config_reads=0
            subprocess.run(['php','-r',code,str(ROOT),str(server.server_port),str(cert),pin,mode,action,external],check=True,timeout=35)
            actual=[p for method,p in Handler.calls if method=='POST'];assert len(actual)==posts,(mode,actual)
            if posts:
                kind,vmid=external.split(':');assert actual==[f'/api2/json/nodes/{NODE}/{kind}/{vmid}/status/{action.split(".")[1]}']
        for prefix,external,kind in [('vm','qemu:101','qemu'),('lxc','lxc:102','lxc')]:
            for op in ['start','reboot','shutdown','start','stop']:
                run('ok',prefix+'.'+op,external,1)
                assert Handler.state[kind]==('running' if op in ('start','reboot') else 'stopped')
        for mode in ['locked','template','offline','ha','denied','race']:run(mode)
        Handler.state['qemu']='running';run('wrong-state');run('paused','vm.reboot');Handler.state['qemu']='stopped'
        run('bad-upid',posts=1);Handler.state['qemu']='stopped'
        run('lost',posts=1);Handler.state['qemu']='stopped'
        run('bad-status',posts=1);run('sdk')
        print('Safe power actions HTTPS: 22 scenarios PASS (all 8 actions, blocked POST, config race, bad UPID/status, lost response, SDK)')
    finally: server.shutdown();server.server_close();thread.join(5)
