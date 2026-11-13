#!/usr/bin/env python3
"""Isolated real HTTPS fixture, no external credentials or network required."""
import http.server
import pathlib
import ssl
import subprocess
import tempfile
import threading

ROOT = pathlib.Path(__file__).resolve().parents[1]

class Handler(http.server.BaseHTTPRequestHandler):
    calls = []
    def log_message(self, *_):
        pass
    def do_GET(self):
        self.calls.append((self.path, self.headers.get('Host')))
        if self.headers.get('Authorization') != 'PVEAPIToken=svc@pve!test=fixture-secret':
            self.send_error(401)
            return
        if self.path == '/redirect':
            self.send_response(302)
            self.send_header('Location', '/redirect-destination')
            self.end_headers()
            return
        if self.path == '/oversized':
            body = b'x' * 4096
        elif self.path == '/api2/json/nodes':
            body = b'{"data":[{"node":"pve-test","status":"online"}]}'
        elif self.path == '/api2/json/cluster/resources?type=vm':
            body = b'{"data":[{"type":"qemu","node":"pve-test","vmid":100,"status":"running"}]}'
        else:
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

with tempfile.TemporaryDirectory(prefix='cajeer-connector-tls-') as tmp:
    key, cert = pathlib.Path(tmp)/'key.pem', pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=connector.test','-addext','subjectAltName=DNS:connector.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert,key)
    server.socket = context.wrap_socket(server.socket,server_side=True)
    thread = threading.Thread(target=server.serve_forever,daemon=True)
    thread.start()
    try:
        code = r'''
require $argv[1].'/core/autoload.php';
use Cajeer\Servers\Connector\{EndpointPolicy,HttpsTransport,ConnectorContext,ProxmoxConnector};
use Cajeer\Servers\Integration\ConnectorRegistry;
$port=(int)$argv[2];$ca=$argv[3];
$transport=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),3,1024);
$registry=new ConnectorRegistry(new ProxmoxConnector(null,$ca,$transport));
$ctx=new ConnectorContext('proxmox','connector.test',$port,'proxmox_api');
$secret='{"token_id":"svc@pve!test","token_secret":"fixture-secret"}';
if(count($registry->execute($ctx,'inspect','api',$secret)->items)!==2)throw new RuntimeException('Bad discovery');
if(count($registry->execute($ctx,'health','api',$secret)->items)!==1)throw new RuntimeException('Bad health');
$collector=new \Cajeer\Servers\Collector\CollectionRuntimeService(\Cajeer\Servers\Collector\CollectorRegistry::forConnectors($registry));
$plan=new \Cajeer\Servers\Collector\CollectionPlan('proxmox.inventory','inspect');
$target=['provider'=>'proxmox','endpoint_host'=>'connector.test','endpoint_port'=>$port,'credential_type'=>'proxmox_api','purpose'=>'api'];
$observations=$collector->collect($plan,$target,'proxmox_api',$secret);
if(count($observations->items)!==2 || $observations->complete)throw new RuntimeException('Bad named HTTPS collection');
$auth='PVEAPIToken=svc@pve!test=fixture-secret';
foreach ([['connector.test','/api2/json/nodes',null],['wrong.test','/api2/json/nodes',$ca],['connector.test','/redirect',$ca],['connector.test','/oversized',$ca]] as [$host,$path,$trust]) {
    try{$transport->get($host,$port,$path,$auth,$trust);}catch(RuntimeException){continue;}
    throw new RuntimeException('Unsafe HTTPS response accepted: '.$path);
}
echo "HTTPS integration: verified CA, hostname, discovery, health, redirect rejection, size limit PASS\n";
'''
        subprocess.run(['php','-r',code,str(ROOT),str(server.server_port),str(cert)],check=True,timeout=30)
        assert all(host == f'connector.test:{server.server_port}' for _,host in Handler.calls)
        assert '/redirect-destination' not in [path for path,_ in Handler.calls]
        assert len(Handler.calls)==7, Handler.calls
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)
