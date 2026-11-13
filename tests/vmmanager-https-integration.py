#!/usr/bin/env python3
"""Real TLS integration against a VMmanager 6 API-v3 fixture; optional disposable PostgreSQL chain.
The fixture is not a vendor appliance and does not claim vendor E2E compatibility.
"""
import argparse
import http.server
import json
import pathlib
import re
import ssl
import subprocess
import tempfile
import threading
from urllib.parse import parse_qs, urlsplit

ROOT = pathlib.Path(__file__).resolve().parents[1]
CLUSTERS = [dict(id=1, name='KVM production', virtualization_type='kvm'), dict(id=2, name='LXD production', virtualization_type='lxd')]
NODES = [dict(id=1, name='vmm-node-a', state='active', cluster={'id': 1, 'name': 'KVM production'}, ip='192.0.2.11', cpu_number=32, ram_mib={'total': 65536, 'used': 16384}, storage_mib={'total': '9007199254740993', 'used': 1024}, host_count=5, host_limit=-1, password='remote-secret-marker'),
         dict(id=2, name='vmm-node-b', state='active', maintenance_mode=True, cluster={'id': 2, 'name': 'LXD production'}, ipv6='2001:db8::2', cpu_number=16, ram_mib={'total': 32768, 'used': 4096})]
HOSTS = [dict(id=21, name='vmm-guest-a', state=True, node={'id': 1}, cluster={'id': 1, 'virtualization_type': 'kvm'}),
         dict(id=22, name='vmm-container-b', state=False, node={'id': 2}, cluster={'id': 2, 'virtualization_type': 'lxd'})]

class Handler(http.server.BaseHTTPRequestHandler):
    calls = []
    anchor_calls = 0
    identity_calls = 0

    def log_message(self, *_):
        pass

    def reply(self, value, status=200, truncated=False):
        body = json.dumps(value, separators=(',', ':')).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body) + (100 if truncated else 0)))
        self.end_headers()
        try:
            self.wfile.write(body)
        except (ConnectionResetError, BrokenPipeError, ssl.SSLError):
            # Oversized responses are intentionally rejected before the body is read.
            if not len(body) > 65536:
                raise

    def do_GET(self):
        token = self.headers.get('x-xsrf-token')
        self.calls.append((self.path, self.headers.get('Host'), token))
        if self.headers.get('Authorization') or self.headers.get('Cookie') or not token:
            self.reply({'error': {'code': 3001}}, 401)
            return
        if token == 'fixture-vmm-denied':
            self.reply({'error': {'code': 3001, 'msg': 'remote-secret-marker'}}, 401)
            return
        if token == 'fixture-vmm-error':
            self.reply({'error': {'code': 3001, 'msg': 'remote-secret-marker'}})
            return
        if token == 'fixture-vmm-throttle':
            self.reply({'error': {'code': 42}}, 429)
            return
        if token == 'fixture-vmm-503':
            self.reply({'error': {'code': 42}}, 503)
            return
        if token == 'fixture-vmm-redirect':
            self.send_response(302)
            self.send_header('Location', '/redirect-destination')
            self.end_headers()
            return
        if token == 'fixture-vmm-large':
            self.reply({'list': [], 'padding': 'x' * 200000})
            return
        if token == 'fixture-vmm-truncated':
            self.reply({'list': []}, truncated=True)
            return
        path = urlsplit(self.path)
        if path.path == '/auth/v4/self/handles':
            if token == 'fixture-vmm-capabilities':
                self.reply({'error': {'code': 42}}, 403)
            else:
                self.reply({'user_id': 7, 'handles': {'vm_v3': {'post': ['/host/{host_id}/start', '/host/{id}/restart', '/host/{id}/stop/not-a-match'], 'get': ['/host']}}})
            return
        if path.path == '/auth/v4/whoami':
            owner = 7
            if token == 'fixture-vmm-identity-drift':
                Handler.identity_calls += 1
                if Handler.identity_calls > 1:
                    owner = 8
            if token == 'fixture-vmm-invalid-identity':
                self.reply({'owner_id': 7, 'roles': {}})
                return
            roles = ['@user'] if token == 'fixture-vmm-scoped' else ['@admin']
            self.reply({'owner_id': owner, 'owner_email': 'remote-secret-marker@example.test', 'roles': roles})
            return
        args = parse_qs(path.query, strict_parsing=True)
        match = re.fullmatch(r'id GT (\d+)', args.get('where', [''])[0])
        limit_match = re.fullmatch(r'0,(1|100)', args.get('limit', [''])[0])
        if path.path not in ('/vm/v3/node', '/vm/v3/host', '/vm/v3/cluster', '/vm/v3/task', '/ip/v3/ipnet', '/ip/v3/ippool', '/ip/v3/ip') or not match or not limit_match or args.get('orderby') != ['id asc']:
            self.reply({'error': {'code': 400}}, 400)
            return
        after, limit = int(match[1]), int(limit_match[1])
        if path.path == '/vm/v3/cluster':
            if token == 'fixture-vmm-cluster-denied':
                self.reply({'error': {'code': 42}}, 403)
                return
            rows = CLUSTERS[:1] if token in ('fixture-vmm-scoped', 'fixture-vmm-cluster-orphan') else CLUSTERS
            if token == 'fixture-vmm-cluster-many':
                rows = [dict(id=i, name=f'cluster-{i}', virtualization_type='kvm') for i in range(1, 103)]
            if token == 'fixture-vmm-cluster-midfail' and after > 0:
                self.reply({'error': {'code': 42}}, 503)
                return
            if token == 'fixture-vmm-cap-revoked':
                self.reply({'error': {'code': 3001}})
                return
            page = [row for row in rows if row['id'] > after][:limit]
            if token == 'fixture-vmm-short':
                page = page[:1]
            self.reply({'list': page})
            return
        if path.path not in ('/vm/v3/node', '/vm/v3/host'):
            if token == 'fixture-vmm-capabilities' and path.path == '/ip/v3/ipnet':
                self.reply({'error': {'code': 42}}, 403)
            elif token == 'fixture-vmm-capabilities' and path.path == '/vm/v3/task':
                self.reply({'error': {'code': 42}}, 404)
            elif token == 'fixture-vmm-cap-revoked':
                self.reply({'error': {'code': 3001, 'msg': 'remote-secret-marker'}})
            else:
                self.reply({'list': []})
            return
        if token == 'fixture-vmm-object':
            self.reply({'list': {}})
            return
        if token == 'fixture-vmm-empty':
            self.reply({'list': [], 'size': 0, 'last_notify': 10})
            return
        rows = [dict(row) for row in (NODES if path.path.endswith('/node') else HOSTS)]
        if path.path.endswith('/node'):
            if token == 'fixture-vmm-cluster-many':
                rows = [dict(id=i, name=f'node-{i}', state='active', cluster={'id': i}) for i in range(1, 103)]
                rows[0]['name'] = 'vmm-node-a'
                rows[1]['name'] = 'vmm-node-b'
            if token == 'fixture-vmm-cluster-move':
                rows[0]['cluster'] = {'id': 2}
            if token == 'fixture-vmm-wrong-anchor':
                rows[0]['name'] = 'other-platform'
            if token == 'fixture-vmm-no-name':
                rows[0].pop('name')
            if token == 'fixture-vmm-anchor-drift' and limit == 1:
                Handler.anchor_calls += 1
                if Handler.anchor_calls > 1:
                    rows[0]['name'] = 'other-platform'
        if path.path.endswith('/host'):
            if token == 'fixture-vmm-scoped':
                rows = rows[:1]
            if token == 'fixture-vmm-rotated':
                rows[0].update(name='vmm-guest-renamed', node={'id': 2})
            if token == 'fixture-vmm-missing':
                rows[0]['node'] = {'id': 999}
            if token == 'fixture-vmm-unknown':
                rows[0]['cluster'] = {'virtualization_type': 'dummy'}
            if token == 'fixture-vmm-many':
                rows = [dict(id=i, name=f'guest-{i}', state=True, node={'id': 1}, cluster={'virtualization_type': 'kvm'}) for i in range(1000, 1102)]
            if token == 'fixture-vmm-midfail' and after > 0:
                self.reply({'error': {'code': 503}}, 503)
                return
        if token == 'fixture-vmm-duplicate' and after > 0:
            page = rows[:limit]  # broken server ignores cursor
        else:
            page = [row for row in rows if row['id'] > after][:limit]
        if token == 'fixture-vmm-short' and page:
            page = page[:1]  # a short page is not exhaustion
        self.reply({'list': page, 'size': len(rows), 'last_notify': 10})


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--postgres', action='store_true', help='also run isolated PostgreSQL/Vault/Queue chain')
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix='cajeer-vmm-tls-') as tmp:
        key, cert = pathlib.Path(tmp)/'key.pem', pathlib.Path(tmp)/'cert.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj','/CN=vmmanager.test','-addext','subjectAltName=DNS:vmmanager.test,IP:127.0.0.1'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cert,key)
        server.socket = context.wrap_socket(server.socket,server_side=True)
        thread = threading.Thread(target=server.serve_forever,daemon=True)
        thread.start()
        try:
            code = r'''
require $argv[1].'/core/autoload.php';
use Cajeer\Servers\Connector\{EndpointPolicy,HttpsTransport,ConnectorContext,VmmanagerConnector,ProxmoxConnector};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;
$port=(int)$argv[2];$ca=$argv[3];$n=0;
$ok=static function(bool $v,string $label)use(&$n):void{if(!$v)throw new RuntimeException($label);$n++;};
$transport=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),3,65536);
$driver=new VmmanagerConnector($transport,$ca);
$registry=new ConnectorRegistry(new ProxmoxConnector(null,$ca,$transport),$driver);
$ctx=new ConnectorContext('vmmanager','vmmanager.test',$port,'vmmanager_api');
$secret=static fn(string $token):string=>json_encode(['token'=>$token],JSON_THROW_ON_ERROR);
$execute=static fn(string $token,string $op='sync')=>$registry->execute($ctx,$op,'api',$secret($token));
foreach(['inspect','sync','validate_rotation'] as $op){$result=$execute('fixture-vmm-active',$op);$ok(count($result->items)===4&&$result->complete,'full discovery '.$op);}
$collector=new \Cajeer\Servers\Collector\CollectionRuntimeService(\Cajeer\Servers\Collector\CollectorRegistry::forConnectors($registry));
$target=['provider'=>'vmmanager','endpoint_host'=>'vmmanager.test','endpoint_port'=>$port,'credential_type'=>'vmmanager_api','purpose'=>'api'];
$collected=$collector->collect(new \Cajeer\Servers\Collector\CollectionPlan('vmmanager.inventory','inspect'),$target,'vmmanager_api',$secret('fixture-vmm-active'));
$ok(count($collected->items)===4&&$collected->complete,'named VMmanager collector');
$health=$execute('fixture-vmm-active','health');$ok(count($health->items)===1&&!$health->complete,'health one-node partial');
$ok($health->connection['authentication']['owner_id']===7&&$health->connection['authentication']['administrator']===true&&$health->connection['authentication']['expiry_source']==='unknown','verified remote principal');
$ok(count($health->connection['capabilities'])===7&&$health->connection['capabilities']['tasks']==='available'&&!str_contains(json_encode($health->connection),'remote-secret-marker'),'real read probes, empty list accessible, no remote PII');
$ok($health->connection['action_capabilities']===['start'=>'advertised','stop'=>'not_advertised','restart'=>'advertised'],'advertised handlers, exact template match');
$restrictedHealth=$execute('fixture-vmm-capabilities','health')->connection;
$ok($restrictedHealth['action_capabilities']['start']==='unknown','handler ACL not treated as write permission');
$cap=$restrictedHealth['capabilities'];
$ok($cap['networks']==='denied'&&$cap['tasks']==='not_found'&&$cap['guests']==='available','capability ACL and 404 distinct');
$scoped=$execute('fixture-vmm-scoped');$ok(!$scoped->complete&&count($scoped->items)===3,'scoped identity cannot authorize missing-resource reconciliation');
foreach(['identity-drift'=>'connector_auth_failed','invalid-identity'=>'connector_protocol_error','cap-revoked'=>'connector_auth_failed'] as $case=>$category){
 try{$execute('fixture-vmm-'.$case,$case==='cap-revoked'?'health':'sync');throw new LogicException('accepted '.$case);}catch(HttpException $e){$ok($e->errorCode===$category,'reject '.$case);}
}
$expires=gmdate('Y-m-d\\TH:i:s\\Z',time()+3600);
$r=$registry->execute($ctx,'health','api',json_encode(['token'=>'fixture-vmm-active','expires_at'=>$expires]));
$ok($r->connection['authentication']['expires_at']===$expires&&$r->connection['authentication']['expiry_source']==='configured','configured expiry reported');
try{$registry->execute($ctx,'health','api',json_encode(['token'=>'fixture-vmm-expired','expires_at'=>'2000-01-01T00:00:00Z']));throw new LogicException('expired token accepted');}catch(HttpException $e){$ok($e->errorCode==='connector_auth_failed','expired credential fails locally');}

$many=$execute('fixture-vmm-many');$ok(count($many->items)===104&&$many->items[103]['external_id']==='host:1101','keyset pagination across more than 100 hosts');
$short=$execute('fixture-vmm-short');$ok(count($short->items)===4&&$short->complete,'short pages still exhausted');
$changed=$execute('fixture-vmm-rotated');$ok($changed->items[2]['external_id']==='host:21'&&$changed->items[2]['parent_external_id']==='node:2'&&$changed->items[2]['fields']['name']==='vmm-guest-renamed','stable ID on migration');
$legacy=new ConnectorContext('vmmanager','vmmanager.test',$port,'api_token');
$ok(count($registry->execute($legacy,'sync','api','fixture-vmm-active')->items)===4,'legacy token profile works');
foreach(['denied'=>'connector_auth_failed','error'=>'connector_auth_failed','throttle'=>'connector_rate_limited','503'=>'connector_unavailable','redirect'=>'connector_protocol_error','large'=>null,'truncated'=>null,'object'=>'connector_protocol_error','duplicate'=>null,'missing'=>null,'unknown'=>null,'empty'=>null,'midfail'=>'connector_unavailable'] as $name=>$expected){
 try{$execute('fixture-vmm-'.$name);throw new LogicException('accepted invalid '.$name);}catch(HttpException $e){$ok($e->status===502&&($expected===null||$e->errorCode===$expected)&&!str_contains($e->getMessage(),'remote-secret-marker')&&!str_contains($e->getMessage(),'fixture-vmm-'),'safe rejection '.$name);}
}
foreach([[new VmmanagerConnector($transport),'vmmanager.test'],[new VmmanagerConnector($transport,$ca),'wrong.test'],[new VmmanagerConnector(new HttpsTransport(new EndpointPolicy([],null,true)),$ca),'127.0.0.1']] as [$bad,$host]){
 try{(new ConnectorRegistry($bad))->execute(new ConnectorContext('vmmanager',$host,$port,'api_token'),'health','api','fixture-vmm-active');throw new LogicException('unsafe TLS/network');}catch(HttpException $e){$ok($e->status===502,'TLS or network policy fails closed');}
}
$pin=openssl_x509_fingerprint(file_get_contents($ca),'sha256');
$profile=['primary_pin'=>strtoupper($pin),'anchor_id'=>2,'anchor_node'=>'vmm-node-b'];
$trusted=new ConnectorContext('vmmanager','vmmanager.test',$port,'api_token',null,$profile);
$strict=new ConnectorRegistry(new VmmanagerConnector($transport,$ca,true));
$health=$strict->execute($trusted,'health','api','fixture-vmm-active');
$ok($health->connection['identity_verified']===true&&$health->connection['tls_pinned']===true&&$health->connection['api_version']==='v3'&&$health->items[0]['external_id']==='node:2','pinned profile health exact non-first anchor');
foreach(['inspect','sync','validate_rotation'] as $op){$r=$strict->execute($trusted,$op,'api','fixture-vmm-active');$ok($r->complete&&count($r->items)===4&&$r->connection['anchor_id']===2,'pinned '.$op);}
$target['vmmanager_profile']=$profile;$target['credential_type']='api_token';
$collected=$collector->collect(new \Cajeer\Servers\Collector\CollectionPlan('vmmanager.inventory','inspect'),$target,'api_token','fixture-vmm-active');
$ok($collected->connection['identity_verified']===true,'collector forwards profile');
$ok(count($driver->metrics($trusted,'fixture-vmm-active'))===3,'metrics use pinned profile');
$rejectTrust=static function(ConnectorContext $ctx,string $token,string $op='health')use($strict,$ok):void{
 try{$strict->execute($ctx,$op,'api',$token);throw new LogicException('accepted invalid trust');}catch(HttpException $e){$ok($e->status===502&&$e->errorCode==='connector_trust_failed','reject trust '.$op);}
};
$rejectTrust(new ConnectorContext('vmmanager','vmmanager.test',$port,'api_token'),'fixture-vmm-mandatory-missing');
$wrongPin=$profile;$wrongPin['primary_pin']=str_repeat('0',64);
$rejectTrust(new ConnectorContext('vmmanager','vmmanager.test',$port,'api_token',null,$wrongPin),'fixture-vmm-wrong-pin');
$wrongNode=$profile;$wrongNode['anchor_node']='other-platform';
$rejectTrust(new ConnectorContext('vmmanager','vmmanager.test',$port,'api_token',null,$wrongNode),'fixture-vmm-active');
$missing=$profile;$missing['anchor_id']=99;
$rejectTrust(new ConnectorContext('vmmanager','vmmanager.test',$port,'api_token',null,$missing),'fixture-vmm-active');
$first=['primary_pin'=>$pin,'anchor_id'=>1,'anchor_node'=>'vmm-node-a'];
$firstCtx=new ConnectorContext('vmmanager','vmmanager.test',$port,'api_token',null,$first);
$rejectTrust($firstCtx,'fixture-vmm-wrong-anchor','sync');
$rejectTrust($firstCtx,'fixture-vmm-no-name','health');
$rejectTrust($firstCtx,'fixture-vmm-anchor-drift','sync');
foreach([null,[],['primary_pin'=>$pin,'anchor_id'=>'1','anchor_node'=>'node'],['primary_pin'=>$pin,'anchor_id'=>1,'anchor_node'=>"a\n"],$profile+['token'=>'secret']] as $bad){
 try{\Cajeer\Servers\Connector\VmmanagerProfile::normalize($bad);throw new LogicException('accepted invalid profile');}catch(HttpException $e){$ok($e->status===422,'invalid profile rejected');}
}
try{new ConnectorContext('proxmox','vmmanager.test',$port,'api_token',null,$profile);throw new LogicException('accepted cross provider');}catch(InvalidArgumentException){$ok(true,'provider scope');}
$clusterPlan=new \Cajeer\Servers\Collector\CollectionPlan('vmmanager.cluster','sync');
$catalog=array_column($collector->catalog(),null,'name');$ok($catalog['vmmanager.cluster']['resource_types']===['cluster','hypervisor_node']&&$catalog['vmmanager.cluster']['operations']===['inspect','sync'],'cluster collector actual types and operations');
$cluster=static fn(string $token)=>$collector->collect($clusterPlan,$target,'api_token',$token);
$r=$cluster('fixture-vmm-active');$d=$r->connection['vmmanager_cluster_discovery'];
$ok(count($r->items)===4&&!$r->complete&&$d['complete']&&$d['counts']===['clusters'=>2,'nodes'=>2,'unlinked_nodes'=>0],'cluster-specific completeness never marks guests absent');
$ok($r->items[2]['external_id']==='node:1'&&$r->items[2]['parent_external_id']==='cluster:1'&&$r->items[0]['type']==='cluster','stable CMDB parent identity');
$ok($d['nodes'][0]['cpu_number']==='32'&&$d['nodes'][0]['storage_mib']['total']==='9007199254740993'&&$d['nodes'][0]['ram_mib']['used']==='16384'&&$d['nodes'][0]['host_limit']==='-1'&&!str_contains(json_encode($d),'remote-secret-marker'),'documented units, exact capacity and field allowlist');
$ok($d['nodes'][1]['status']==='maintenance'&&$d['nodes'][1]['storage_mib']===null,'maintenance and absent capacity');
$r=$cluster('fixture-vmm-scoped');$d=$r->connection['vmmanager_cluster_discovery'];$ok(!$d['complete']&&$d['counts']['unlinked_nodes']===1&&!isset($r->items[2]['parent_external_id']),'scoped visibility does not invent cluster');
$r=$cluster('fixture-vmm-cluster-move');$ok($r->items[2]['parent_external_id']==='cluster:2'&&$r->items[2]['external_id']==='node:1','node reassignment preserves identity');
$r=$cluster('fixture-vmm-cluster-many');$ok(count($r->items)===204&&$r->connection['vmmanager_cluster_discovery']['counts']['nodes']===102,'cluster and node pagination');
$r=$cluster('fixture-vmm-short');$ok(count($r->items)===4,'short pages are not exhaustion');
foreach(['cluster-denied'=>'connector_auth_failed','cluster-midfail'=>'connector_unavailable','cluster-orphan'=>'connector_protocol_error'] as $case=>$category){
    try{$cluster('fixture-vmm-'.$case);throw new LogicException('accepted '.$case);}catch(HttpException $e){$ok($e->status===502&&$e->errorCode===$category,'cluster rejects '.$case);}
}
try{$collector->collect($clusterPlan,array_diff_key($target,['vmmanager_profile'=>true]),'api_token','fixture-vmm-active');throw new LogicException('missing profile');}catch(HttpException $e){$ok($e->errorCode==='collection_invalid','profile required for detailed collector');}
echo "VMmanager HTTPS integration: $n PASS\n";
'''
            subprocess.run(['php','-r',code,str(ROOT),str(server.server_port),str(cert)],check=True,timeout=60)
            assert all(host == f'vmmanager.test:{server.server_port}' for _,host,_ in Handler.calls)
            assert not any(token in ('fixture-vmm-wrong-pin','fixture-vmm-mandatory-missing','fixture-vmm-expired') for _,_,token in Handler.calls), 'credentials sent before trust check'
            assert not any(path == '/redirect-destination' for path,_,_ in Handler.calls)
            assert any('id%20GT%201099' in path for path,_,_ in Handler.calls)
            if args.postgres:
                subprocess.run(['php',str(ROOT/'tests/vmmanager-postgres-integration.php'),str(server.server_port),str(cert)],check=True,timeout=180)
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)

if __name__ == '__main__':
    main()
