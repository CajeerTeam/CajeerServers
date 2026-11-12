#!/usr/bin/env python3
import hashlib, hmac, http.server, pathlib, ssl, subprocess, tempfile, threading, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
SECRET='fixture-export-signing-secret-1234567890'
class Handler(http.server.BaseHTTPRequestHandler):
    mode='ok'; calls=0; ids=set()
    def log_message(self,*_): pass
    def do_POST(self):
        Handler.calls+=1
        body=self.rfile.read(int(self.headers['Content-Length']))
        ident=self.headers['Idempotency-Key'];mime=self.headers['Content-Type'];ts=self.headers['X-Cajeer-Timestamp']
        sig='sha256='+hmac.new(SECRET.encode(),f'v1.{ts}.{ident}\n{mime}\n'.encode()+body,hashlib.sha256).hexdigest()
        assert abs(int(ts)-time.time())<300 and self.headers['X-Cajeer-Signature']==sig
        assert hashlib.sha256(body).hexdigest()==self.headers['X-Artifact-SHA256']
        assert body==b'{"records":[{"value":"18446744073709551615"}]}'
        Handler.ids.add(ident)
        if self.path!='/receive':self.send_error(404);return
        if Handler.mode=='redirect': self.send_response(302);self.send_header('Location','/other');self.end_headers();return
        if Handler.mode=='retry' and Handler.calls==1:self.send_error(503);return
        self.send_response(204);self.end_headers()
code=r'''
require $argv[1].'/core/autoload.php';
use Cajeer\Servers\Connector\{HttpsTransport,EndpointPolicy,ConnectorTransportException};
use Cajeer\Servers\ImportExport\WebhookDelivery;
$t=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1'],true),3,65536);
$b='{"records":[{"value":"18446744073709551615"}]}';
try{$r=(new WebhookDelivery($t))->deliver('https://'.$argv[4].':'.$argv[2].'/receive','fixture-export',['body'=>$b,'sha256'=>hash('sha256',$b),'format'=>'json'],'fixture-export-signing-secret-1234567890',$argv[3]==='none'?null:$argv[3]);if($argv[5]==='fail')throw new RuntimeException('expected failure');echo json_encode($r,JSON_THROW_ON_ERROR);}
catch(ConnectorTransportException $e){if($argv[5]!=='fail')throw $e;echo $e->category;}
'''
with tempfile.TemporaryDirectory() as tmp:
    key=pathlib.Path(tmp)/'key.pem';cert=pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=export.test','-addext','subjectAltName=DNS:export.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    srv=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);srv.socket=ctx.wrap_socket(srv.socket,server_side=True);threading.Thread(target=srv.serve_forever,daemon=True).start()
    try:
        import json
        def run(mode,ca,host,expect):
            Handler.mode=mode;Handler.calls=0;Handler.ids=set()
            return subprocess.check_output(['php','-r',code,str(ROOT),str(srv.server_port),ca,host,expect],timeout=15).decode()
        first=json.loads(run('ok',str(cert),'export.test','ok'));assert first['attempts']==1 and Handler.calls==1
        retry=json.loads(run('retry',str(cert),'export.test','ok'));assert retry['attempts']==2 and Handler.calls==2 and len(Handler.ids)==1 and retry['delivery_id']==first['delivery_id']
        assert run('redirect',str(cert),'export.test','fail')=='connector_protocol_error' and Handler.calls==1
        assert run('ok','none','export.test','fail')=='connector_trust_failed' and Handler.calls==0
        assert run('ok',str(cert),'wrong.test','fail')=='connector_trust_failed' and Handler.calls==0
        print('Data export real TLS, signed body, stable retry, redirect and CA refusal: 5 PASS')
    finally:srv.shutdown();srv.server_close()
