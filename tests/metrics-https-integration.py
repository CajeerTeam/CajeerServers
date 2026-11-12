#!/usr/bin/env python3
import http.server, pathlib, ssl, subprocess, tempfile, threading
ROOT=pathlib.Path(__file__).resolve().parents[1]
class Handler(http.server.BaseHTTPRequestHandler):
    mode='valid'
    def log_message(self,*_): pass
    def do_GET(self):
        if self.path!='/metrics' or self.headers.get('Authorization')!='Bearer fixture-metrics-token' or 'text/plain' not in self.headers.get('Accept',''):
            self.send_error(401);return
        if Handler.mode=='redirect':
            self.send_response(302);self.send_header('Location','/elsewhere');self.end_headers();return
        body={'valid':b'# TYPE requests_total counter\nrequests_total{route="/api"} 18446744073709551615\n# TYPE temperature gauge\ntemperature 32.5\n','bad':b'temperature NaN\n','duplicate':b'x 1\nx 2\n','oversize':b'#'+b'a'*270000}.get(Handler.mode,b'')
        self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
code=r'''
require $argv[1].'/core/autoload.php';
use Cajeer\Servers\Connector\{EndpointPolicy,HttpsTransport,ConnectorContext,PrometheusConnector};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Collector\{CollectorRegistry,CollectionRuntimeService,CollectionPlan};
$https=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),3,262144);
$registry=new ConnectorRegistry(new PrometheusConnector($https,$argv[3]==='none'?null:$argv[3]));
$rt=new CollectionRuntimeService(CollectorRegistry::forConnectors($registry));
$target=['provider'=>'prometheus','endpoint_host'=>$argv[4],'endpoint_port'=>(int)$argv[2],'credential_type'=>'api_token','purpose'=>'api'];
try{$result=$rt->collect(new CollectionPlan('prometheus.metrics','inspect'),$target,'api_token',$argv[5]);}
catch(\Cajeer\Servers\Http\Exception\HttpException $e){if($argv[6]==='fail'){echo $e->errorCode."\n";exit(0);}throw $e;}
if($argv[6]==='fail'||count($result->metricSamples)!==2||$result->metricSamples[0]['kind']!=='counter'||$result->metricSamples[0]['value']!=='18446744073709551615'||$result->metricSamples[1]['value']!=='32.5')throw new RuntimeException('unexpected metric response');
'''
with tempfile.TemporaryDirectory() as tmp:
    key=pathlib.Path(tmp)/'key.pem';cert=pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=metrics.test','-addext','subjectAltName=DNS:metrics.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True);threading.Thread(target=server.serve_forever,daemon=True).start()
    try:
        cases=[('valid',str(cert),'metrics.test','fixture-metrics-token','ok'),('valid','none','metrics.test','fixture-metrics-token','fail'),('valid',str(cert),'wrong.test','fixture-metrics-token','fail'),('valid',str(cert),'metrics.test','wrong','fail')]+[(m,str(cert),'metrics.test','fixture-metrics-token','fail') for m in ['bad','duplicate','oversize','redirect']]
        for mode,ca,host,token,expect in cases:
            Handler.mode=mode;subprocess.run(['php','-r',code,str(ROOT),str(server.server_port),ca,host,token,expect],check=True,timeout=15)
        print(f'Metrics real HTTPS: {len(cases)} PASS')
    finally:server.shutdown();server.server_close()
