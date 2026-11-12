#!/usr/bin/env python3
"""Real TLS + production Python sender and PHP HMAC/parser. DB durability is tested separately."""
import datetime
import http.server
import json
import os
import pathlib
import ssl
import subprocess
import tempfile
import threading

ROOT=pathlib.Path(__file__).resolve().parents[1]
SOURCE='11111111-1111-4111-8111-111111111111'
SECRET='a'*64
PHP=r'''
require $argv[1].'/core/autoload.php';
$body=stream_get_contents(STDIN);
try {
 \Cajeer\Servers\Connector\ConnectorEventPayload::verify($body,getenv('FIXTURE_KEY'),$argv[2],$argv[3],$argv[4]);
 $b=(new \Cajeer\Servers\Collector\WebhookCollector())->parse($body,$argv[5],$argv[2]);
 echo json_encode(['status'=>202,'data'=>$b],JSON_THROW_ON_ERROR);
} catch (\Cajeer\Servers\Http\Exception\HttpException $e) {echo json_encode(['status'=>$e->status,'code'=>$e->errorCode],JSON_THROW_ON_ERROR);}
'''
class Handler(http.server.BaseHTTPRequestHandler):
    mode='metrics'
    accepted=[]
    redirects=0
    def log_message(self,*_):pass
    def do_POST(self):
        if self.path!='/api/v1/connectors/ingestion/'+SOURCE+'/batches':
            Handler.redirects+=1;self.send_error(404);return
        if Handler.mode=='redirect':
            self.send_response(307);self.send_header('Location','/other');self.end_headers();return
        if self.headers.get('Content-Type')!='application/json':self.send_error(415);return
        body=self.rfile.read(int(self.headers['Content-Length']))
        out=subprocess.run(['php','-r',PHP,str(ROOT),SOURCE,self.headers.get('X-Cajeer-Timestamp',''),self.headers.get('X-Cajeer-Signature',''),Handler.mode],input=body,capture_output=True,check=True,timeout=10,env={**os.environ,'FIXTURE_KEY':SECRET})
        result=json.loads(out.stdout);status=result['status']
        if status==202:Handler.accepted.append(result['data'])
        data=json.dumps(result).encode();self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data)
with tempfile.TemporaryDirectory() as d:
    p=pathlib.Path(d);cert=p/'cert.pem';key=p/'key.pem';batch=p/'batch.json'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    srv=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(cert,key);srv.socket=context.wrap_socket(srv.socket,server_side=True);threading.Thread(target=srv.serve_forever,daemon=True).start()
    now=datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00','Z')
    metric={'schema_version':1,'kind':'metrics','batch_id':'test-1','records':[{'metric':'external.bytes','value':'18446744073709551615','kind':'counter','observed_at':now}]}
    base=f'https://127.0.0.1:{srv.server_port}/api/v1'
    def run(data,mode='metrics',secret=SECRET,ca=True,success=True,scheme='https'):
        Handler.mode=mode;batch.write_bytes(data if isinstance(data,bytes) else json.dumps(data).encode());cmd=['python3',str(ROOT/'examples/ingestion/send.py'),base.replace('https',scheme,1),SOURCE,str(batch)]+(['--ca-file',str(cert)] if ca else [])
        r=subprocess.run(cmd,env={**os.environ,'CAJEER_INGESTION_SECRET':secret},capture_output=True,timeout=25)
        assert (r.returncode==0)==success,(r.stdout,r.stderr)
        if success: assert json.loads(r.stdout)['status']==202
    try:
        run(metric);assert Handler.accepted[-1]['records'][0]['value']=='18446744073709551615';assert Handler.accepted[-1]['records'][0]['labels']['cajeer_source']==SOURCE
        run(metric);assert Handler.accepted[-1]['digest']==Handler.accepted[-2]['digest']
        run(metric,secret='b'*64,success=False)
        run(metric,ca=False,success=False)
        run(metric,mode='redirect',success=False);assert Handler.redirects==0
        run(metric,scheme='http',success=False)
        run(b'{' ,success=False)
        run(metric,mode='events',success=False)
        event={'schema_version':1,'kind':'events','batch_id':'event-1','records':[{'event_id':'evt-1','type':'system.changed','message':'Fixture','observed_at':now}]}
        run(event,mode='events');assert Handler.accepted[-1]['records'][0]['event_id']=='evt-1'
        print('Ingestion real TLS + Python sender + PHP HMAC/parser: 9 PASS (fixture, no database)')
    finally:srv.shutdown();srv.server_close()
