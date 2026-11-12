#!/usr/bin/env python3
"""Real TLS regression: framing, bounded slow responses, error categories."""
import http.server, pathlib, ssl, subprocess, tempfile, threading, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_GET(self):
        self.close_connection=True
        responses={
            '/chunk': b'Transfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n6\r\n world\r\n0\r\nX-Test: yes\r\n\r\n',
            '/close': b'Connection: close\r\n\r\nhello world',
            '/length': b'Content-Length: 11\r\n\r\nhello world',
            '/truncated': b'Content-Length: 12\r\n\r\nhello world',
            '/duplicate': b'Content-Length: 1\r\nContent-Length: 1\r\n\r\nx',
            '/ambiguous': b'Content-Length: 1\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n',
            '/badchunk': b'Transfer-Encoding: chunked\r\n\r\n1\r\nx!\r\n0\r\n\r\n',
            '/large': b'Content-Length: 100000\r\n\r\n',
            '/header': b'X-Large: '+b'x'*33000+b'\r\n\r\n',
            '/encoding': b'Content-Encoding: gzip\r\nContent-Length: 1\r\n\r\nx',
        }
        try:
            if self.path in ['/401','/429','/503','/302']:
                self.connection.sendall(f'HTTP/1.1 {self.path[1:]} Error\r\nContent-Length: 0\r\n\r\n'.encode()); return
            self.connection.sendall(b'HTTP/1.1 200 OK\r\n')
            if self.path=='/slow':
                for _ in range(12):
                    self.connection.sendall(b'X-A: a\r\n'); time.sleep(.2)
                self.connection.sendall(b'\r\n'); return
            if self.path=='/slowbody':
                self.connection.sendall(b'Content-Length: 20\r\n\r\n')
                for _ in range(20): self.connection.sendall(b'x'); time.sleep(.15)
                return
            self.connection.sendall(responses[self.path])
        except (OSError,ssl.SSLError): pass
with tempfile.TemporaryDirectory() as tmp:
    key=pathlib.Path(tmp)/'key.pem'; cert=pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=connector.test','-addext','subjectAltName=DNS:connector.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
    ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(cert,key)
    server.socket=ctx.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    try:
        subprocess.run(['php','-r',r'''
require $argv[1].'/core/autoload.php';
use Cajeer\Servers\Connector\{EndpointPolicy,HttpsTransport,ConnectorTransportException};
$t=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),1,1024);
$n=0; $get=fn($path)=>$t->get('connector.test',(int)$argv[2],$path,'Bearer test',$argv[3]);
foreach(['/chunk','/close','/length'] as $p){if($get($p)!=='hello world')throw new RuntimeException($p);$n++;}
foreach(['/truncated'=>'connector_protocol_error','/duplicate'=>'connector_protocol_error','/ambiguous'=>'connector_protocol_error','/badchunk'=>'connector_protocol_error','/large'=>'connector_protocol_error','/header'=>'connector_protocol_error','/encoding'=>'connector_protocol_error','/401'=>'connector_auth_failed','/429'=>'connector_rate_limited','/503'=>'connector_unavailable','/302'=>'connector_protocol_error','/slow'=>'connector_transport_failed','/slowbody'=>'connector_transport_failed'] as $p=>$category){
    $start=hrtime(true);try{$get($p);throw new RuntimeException('Accepted '.$p);}catch(ConnectorTransportException $e){if($e->category!==$category)throw new RuntimeException($p.' expected '.$category.' got '.$e->category);}
    if((hrtime(true)-$start)>2500000000)throw new RuntimeException('Deadline exceeded: '.$p);$n++;
}
echo "Connector stability HTTPS: $n PASS\n";
''',str(ROOT),str(server.server_port),str(cert)],check=True,timeout=20)
    finally: server.shutdown(); server.server_close(); thread.join(5)
