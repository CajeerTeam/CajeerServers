#!/usr/bin/env python3
"""Real TLS requests to a Proxmox-compatible fixture plus trusted SDK demo."""
import http.server, json, pathlib, ssl, subprocess, tempfile, threading, urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[1]
TASK='UPID:pve-test:00000001:00000002:00000003:qmstart:101:svc@pve!test:'
class Handler(http.server.BaseHTTPRequestHandler):
    calls=[]
    def log_message(self,*args): pass
    def reply(self,data):
        b=json.dumps({'data':data}).encode();self.send_response(200);self.send_header('Content-Length',str(len(b)));self.end_headers();self.wfile.write(b)
    def do_GET(self):
        self.calls.append(('GET',self.path));path=urllib.parse.unquote(self.path)
        if path=='/inventory/v1/servers':
            if self.headers.get('Authorization')!='Bearer fixture-demo': self.send_error(401);return
            self.reply([{'id':'s1','name':'server-one','status':'online'}]);return
        if self.headers.get('Authorization')!='PVEAPIToken=svc@pve!test=fixture-secret': self.send_error(401);return
        if path=='/api2/json/nodes': self.reply([{'node':'pve-test','status':'online'}])
        elif path=='/api2/json/cluster/resources?type=vm': self.reply([{'type':'qemu','node':'pve-test','vmid':101,'status':'stopped'}])
        elif path==f'/api2/json/nodes/pve-test/tasks/{TASK}/status': self.reply({'status':'stopped','exitstatus':'OK'})
        else: self.send_error(404)
    def do_POST(self):
        self.calls.append(('POST',self.path))
        if self.headers.get('Authorization')!='PVEAPIToken=svc@pve!test=fixture-secret':self.send_error(401);return
        if self.path=='/api2/json/nodes/pve-test/qemu/101/status/start': self.reply(TASK)
        elif self.path=='/api2/json/nodes/pve-test/qemu/101/status/shutdown': self.reply('UPID:wrong:00000001:00000002:00000003:qmshutdown:101:svc@pve!test:')
        else:self.send_error(404)
with tempfile.TemporaryDirectory() as tmp:
    key,cert=pathlib.Path(tmp)/'key.pem',pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=connector.test','-addext','subjectAltName=DNS:connector.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    try:
        code=r'''
require $argv[1].'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ProxmoxConnector,HttpsTransport,EndpointPolicy};
use Cajeer\Servers\Integration\{ConnectorRegistry,ConnectorExtensionLoader};
$transport=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),3);
$d=new ProxmoxConnector(null,$argv[3],$transport);$ctx=new ConnectorContext('proxmox','connector.test',(int)$argv[2],'proxmox_api');
$secret='{"token_id":"svc@pve!test","token_secret":"fixture-secret"}';
$remote=$d->prepareAction($ctx,'vm.start','qemu:101',$secret);
$task=$d->dispatchAction($ctx,'vm.start',$remote,$secret);
if($d->actionStatus($ctx,$remote['node'],$task,$secret)!=='succeeded')throw new RuntimeException('Task result failed');
try{$d->dispatchAction($ctx,'vm.shutdown',$remote,$secret);throw new LogicException('Wrong task node accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
putenv('DEMO_CONNECTOR_CA_FILE='.$argv[3]);
$r=ConnectorExtensionLoader::load(new ConnectorRegistry($d),$argv[1].'/examples/extensions/demo-connector/entry.php',$transport);
$res=$r->execute(new ConnectorContext('custom','connector.test',(int)$argv[2],'api_token'),'sync','api','fixture-demo');
if(count($res->items)!==1||$res->items[0]['external_id']!=='server:s1')throw new RuntimeException('SDK observation failed');
echo "Action POST, UPID task confirmation, invalid UPID rejection, SDK HTTPS discovery PASS\n";
'''
        subprocess.run(['php','-r',code,str(ROOT),str(server.server_port),str(cert)],check=True,timeout=30)
        assert [p for method,p in Handler.calls if method=='POST']==['/api2/json/nodes/pve-test/qemu/101/status/start','/api2/json/nodes/pve-test/qemu/101/status/shutdown']
    finally: server.shutdown();server.server_close();thread.join(5)
