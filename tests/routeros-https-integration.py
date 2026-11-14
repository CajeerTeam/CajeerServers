#!/usr/bin/env python3
"""Production RouterOS driver via real pinned TLS, registry and collection runtime."""
import os, sys, base64, http.server, json, pathlib, ssl, subprocess, tempfile, threading, hashlib, urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[1]
AUTH='Basic '+base64.b64encode(b'reader:fixture-secret').decode()
class Handler(http.server.BaseHTTPRequestHandler):
    mode='ok'; requests=[]; identities=0; files={}; exports=0
    def log_message(self,*args): pass
    def reply(self,data):
        body=json.dumps(data).encode();self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    def do_POST(self):
        self.requests.append(('POST',self.path))
        if self.headers.get('Authorization')!=AUTH:self.send_error(401);return
        data=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if self.path=='/rest/export':
            assert set(data)=={'file','compact'} and data['file'].startswith('cajeer-backup-')
            Handler.exports+=1
            content='# 2026-11-14 12:00:00 by RouterOS 7.16.2\n/ip firewall filter\nadd chain=input action=accept\n'
            if self.mode=='backup_large':content+='# '+('a'*300+'\n')*160
            if self.mode=='backup_partial':content+='#error exporting /ip/firewall\n'
            if self.mode=='backup_empty':content=''
            if self.mode=='backup_changed' or (self.mode=='ok' and Handler.exports>=3):content=content.replace('action=accept','action=drop')
            Handler.files[data['file']+'.rsc']=content;self.reply([]);return
        if self.path=='/rest/file/read':
            if self.mode=='backup_read_fail':self.send_error(503);return
            assert set(data)=={'file','offset','chunk-size'} and data['chunk-size']=='32768'
            if data['file'] not in Handler.files:self.send_error(404);return
            content=Handler.files[data['file']];off=int(data['offset']);self.reply([{'data':content[off:off+32768]}]);return
        self.send_error(405)
    def do_DELETE(self):
        self.requests.append(('DELETE',self.path))
        if self.headers.get('Authorization')!=AUTH:self.send_error(401);return
        name=self.path.removeprefix('/rest/file/')
        if name not in Handler.files:self.send_error(404);return
        del Handler.files[name];self.send_response(204);self.end_headers()
    def do_GET(self):
        self.requests.append(('GET',self.path))
        if self.headers.get('Authorization')!=AUTH or self.mode=='auth': self.send_error(401);return
        url=urllib.parse.urlsplit(self.path);p=url.path
        assert '.proplist' in urllib.parse.parse_qs(url.query)
        if p=='/rest/system/identity':
            Handler.identities+=1
            name='other' if self.mode=='identity' or (self.mode=='changed' and Handler.identities>1) else 'router01'
            data={'name':name}
        elif p=='/rest/system/resource':data=[{'version':'6.49' if self.mode=='v6' else '7.16.2 (stable)','platform':'MikroTik','cpu-load':'10','total-memory':'9007199254740993','free-memory':'100','board-name':'CHR'}]
        elif p=='/rest/system/routerboard':data={'routerboard':'false','model':'CHR'}
        elif p=='/rest/interface':data=[{'.id':'*1','name':'ether1','type':'ether','rx-byte':'18446744073709551615','tx-byte':'20','mac-address':'00:11:22:33:44:55'}]
        elif p=='/rest/interface/bridge':data=[{'.id':'*10','name':'bridge1','vlan-filtering':'true'}]
        elif p=='/rest/interface/bridge/port':data=[{'.id':'*11','bridge':'bridge1','interface':'ether1','pvid':'20'}]
        elif p=='/rest/interface/bridge/vlan':data=[{'.id':'*12','bridge':'bridge1','vlan-ids':'20,30','tagged':'ether1','untagged':'bridge1'}]
        elif p=='/rest/interface/vlan':data=[{'.id':'*13','name':'vlan20','interface':'ether1','vlan-id':'20'}]
        elif p=='/rest/ipv6/address':data=[{'.id':'*14','address':'2001:db8::1/64','interface':'ether1'}]
        elif p=='/rest/ip/pool':data=[{'.id':'*15','name':'lan','ranges':'192.0.2.10-192.0.2.100'}]
        elif p=='/rest/ip/dhcp-server/lease':data=[{'.id':'*16','address':'192.0.2.10','mac-address':'00:11:22:33:44:56','status':'bound'}]
        elif p=='/rest/ip/neighbor':data=[{'.id':'*17','interface':'ether1','identity':'switch01','address':'192.0.2.2'}]
        elif p=='/rest/ipv6/route':data=[{'.id':'*18','dst-address':'::/0','gateway':'2001:db8::2','routing-table':'main'}]
        elif p=='/rest/routing/table':data=[{'.id':'*19','name':'main','fib':'true'}]
        elif p=='/rest/ip/firewall/nat':data=[{'.id':'*20','chain':'srcnat','action':'masquerade','out-interface':'ether1'}]
        elif p=='/rest/ipv6/firewall/filter':data=[{'.id':'*21','chain':'forward','action':'drop'}]
        elif p=='/rest/ip/firewall/address-list':data=[{'.id':'*22','list':'trusted','address':'192.0.2.0/24'}]
        elif p=='/rest/ip/address':data=[{'.id':'*2','address':'192.0.2.1/24','interface':'ether1'}]
        elif p=='/rest/ip/route':data=[{'.id':'*3','dst-address':'0.0.0.0/0','gateway':'192.0.2.254','routing-table':'main','active':'true'}]
        elif p=='/rest/ip/firewall/filter':
            if self.mode=='acl':self.send_error(403);return
            if self.mode=='missing':self.send_error(404);return
            if self.mode=='bad':data={'error':400,'message':'fixture-secret'}
            elif self.mode=='duplicate':data=[{'.id':'*4','chain':'input'},{'.id':'*4','chain':'forward'}]
            elif self.mode=='failure':self.send_error(503);return
            else:data=[{'.id':'*4','chain':'input','action':'accept','protocol':'tcp','dst-port':'443','comment':'fixture-secret','password':'fixture-secret'},{'.id':'*5','chain':'input','action':'drop'}]
        else:data=[]
        if self.mode=='redirect' and p=='/rest/system/identity':self.send_response(302);self.send_header('Location','https://evil.invalid/');self.end_headers();return
        body=json.dumps(data).encode();self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
PHP=r'''
require 'core/autoload.php';
use Cajeer\Servers\Connector\{RouterOsConnector,EndpointPolicy,HttpsTransport,ConnectorContext};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Collector\{CollectorRegistry,CollectionRuntimeService,CollectionPlan};
use Cajeer\Servers\Http\Exception\HttpException;
[$unused,$mode,$ca,$port,$pin]=$argv;
$https=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn($h)=>['127.0.0.1'],true),3);
$driver=new RouterOsConnector($https,$mode==='untrusted'?null:$ca);$registry=new ConnectorRegistry($driver);
$profile=['primary_pin'=>$mode==='pin'?str_repeat('0',64):$pin,'identity_hash'=>hash('sha256','routeros:router01')];
$target=['provider'=>'routeros','endpoint_host'=>$mode==='hostname'?'wrong.test':'router.test','endpoint_port'=>(int)$port,'credential_type'=>'routeros_api','purpose'=>'api','os_network_profile'=>$mode==='no_profile'?null:$profile];
$secret=json_encode(['username'=>'reader','password'=>'fixture-secret']);
$runtime=new CollectionRuntimeService(CollectorRegistry::forConnectors($registry));
$expected=match($mode){'auth'=>'connector_auth_failed','pin','identity','changed','untrusted','hostname'=>'connector_trust_failed','redirect','bad','duplicate','v6'=>'connector_protocol_error','backup_read_fail','failure'=>'connector_unavailable','backup_partial','backup_empty'=>'connector_protocol_error','no_profile','sync_routing'=>'collection_invalid',default=>null};
try {
  if(str_starts_with($mode,'backup_')){
    $r=$runtime->collect(new CollectionPlan('routeros.configuration-backup','inspect'),$target,'routeros_api',$secret);
    if($expected!==null)throw new RuntimeException('expected error '.$expected);
    $text=$r->connection['network_configuration_backup'];
    if(!str_contains($text,'/ip firewall filter')||($mode==='backup_large'&&strlen($text)<=32768))throw new RuntimeException('invalid native backup');
  } elseif(in_array($mode,['health','rotation'],true)){
    $ctx=new ConnectorContext('routeros','router.test',(int)$port,'routeros_api',null,null,$profile);
    $r=$registry->execute($ctx,$mode==='health'?'health':'validate_rotation','api',$secret);
    if(!$r->connection['identity_verified'])throw new RuntimeException('trust not verified');
  } else {
    $scope=match($mode){'inventory','sync'=>'inventory','network'=>'network','metrics'=>'metrics',default=>'routing'};
    $r=$runtime->collect(new CollectionPlan('routeros.'.$scope,in_array($mode,['sync','sync_routing'],true)?'sync':'inspect'),$target,'routeros_api',$secret);
    if($expected!==null)throw new RuntimeException('expected error '.$expected);
    if($scope==='metrics') {if(!in_array('18446744073709551615',array_column($r->metricSamples,'value'),true))throw new RuntimeException('lost Counter64');}
    else {
      $s=$r->connection['routeros_discovery'];if($s['scope']!==$scope||str_contains(json_encode($s),'fixture-secret')||$s['complete']!==!in_array($mode,['acl','missing'],true))throw new RuntimeException('bad snapshot');
      if($scope==='routing'&&$mode==='ok'&&($s['sections']['firewall_filter'][1]['action']!=='drop'||$s['sections']['routes4'][0]['gateway']!=='192.0.2.254'||$s['sections']['routes6'][0]['dst-address']!=='::/0'||$s['sections']['firewall_nat'][0]['action']!=='masquerade'||$s['sections']['address_lists'][0]['list']!=='trusted'))throw new RuntimeException('bad rules');
      if($scope==='network'&&($s['sections']['ipv4'][0]['interface']!=='ether1'||$s['sections']['ipv4'][0]['interface-id']!=='*1'||$s['sections']['ipv6'][0]['address']!=='2001:db8::1/64'||$s['sections']['bridge_ports'][0]['interface-id']!=='*1'||$s['sections']['bridge_ports'][0]['bridge-id']!==null||$s['sections']['bridge_vlans'][0]['vlan-ids']!=='20,30'||$s['sections']['dhcp_leases'][0]['status']!=='bound'))throw new RuntimeException('bad network');
      if($scope==='network'){
        $projection=\Cajeer\Servers\Inventory\NetworkObservation::project(['id'=>'routeros-fixture','provider'=>'routeros','name'=>'router01'],$s,'2026-11-14T12:00:00Z');
        if(count($projection['interfaces'])!==1||count($projection['addresses'])!==2||array_column($projection['networks'],'cidr')!==['192.0.2.0/24','2001:db8::/64']||$projection['addresses'][0]['interface_id']!==$projection['interfaces'][0]['id']||$projection['neighbors'][0]['evidence']!=='routeros_neighbor'||$projection['links']!==[])throw new RuntimeException('bad topology projection of real RouterOS snapshot');
      }
      if($scope==='inventory'&&($r->items[0]['type']!=='router'||$s['sections']['resource'][0]['total-memory']!=='9007199254740993'))throw new RuntimeException('bad inventory');
      if($mode==='acl'&&$s['visibility']['firewall_filter']!=='denied')throw new RuntimeException('bad ACL');
      if($mode==='missing'&&$s['visibility']['firewall_filter']!=='not_found')throw new RuntimeException('bad visibility');
    }
  }
} catch(HttpException $e){if($e->errorCode!==$expected)throw $e;}
'''
with tempfile.TemporaryDirectory() as tmp:
    key=pathlib.Path(tmp)/'key.pem';cert=pathlib.Path(tmp)/'cert.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=router.test','-addext','subjectAltName=DNS:router.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    pin=hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True);threading.Thread(target=server.serve_forever,daemon=True).start()
    modes=['ok','inventory','sync','network','metrics','health','rotation','acl','missing','auth','pin','identity','changed','untrusted','hostname','redirect','bad','duplicate','v6','failure','no_profile','sync_routing','backup_ok','backup_large','backup_partial','backup_empty','backup_read_fail','backup_changed']
    try:
        for mode in modes:
            Handler.mode=mode;Handler.requests=[];Handler.identities=0
            subprocess.run(['php','-r',PHP,mode,str(cert),str(server.server_port),pin],cwd=ROOT,check=True,timeout=45)
            if mode.startswith('backup_'):
                assert not Handler.files and Handler.requests[-1][0]=='DELETE',mode
                assert sum(method=='POST' and path=='/rest/file/read' for method,path in Handler.requests)>=1
            else:assert all(method=='GET' for method,_ in Handler.requests)
            if mode in ['pin','untrusted','hostname','no_profile','sync_routing']:assert not Handler.requests,mode
            print('PASS',mode,flush=True)
        print(f'RouterOS HTTPS integration: {len(modes)} PASS')
        if '--postgres' in sys.argv:
            Handler.mode='ok';Handler.exports=0;env=dict(os.environ,CAJEER_ROUTEROS_TEST_CA=str(cert),CAJEER_ROUTEROS_TEST_PIN=pin)
            subprocess.run(['php','tests/routeros-postgres-integration.php',str(server.server_port)],cwd=ROOT,env=env,check=True,timeout=180)
    finally:server.shutdown();server.server_close()
