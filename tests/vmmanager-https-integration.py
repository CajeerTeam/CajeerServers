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
HOSTS = [dict(id=21, name='vmm-guest-a', state=True, node={'id': 1}, cluster={'id': 1, 'virtualization_type': 'kvm', 'name': 'KVM production'}, cpu_number=4, ram_mib=8192, hdd_mib='9007199254740993', os={'id': 12, 'name': 'Ubuntu 24.04'}, account={'id': 7, 'email': 'remote-secret-marker'}, disk=[{'id': 31, 'name': 'root', 'size_mib': '9007199254740993', 'storage': {'id': 6, 'name': 'local'}, 'bus': 'virtio', 'type': 'hard_drive', 'path': 'remote-secret-marker'}], iface=[{'id': 41, 'name': 'eth0', 'mac': '02:00:00:00:00:01', 'model': 'virtio', 'bridge': 'br0', 'ip': [{'name': '192.0.2.21'}, {'name': '2001:db8::21'}]}], ip=[{'name': '192.0.2.21'}], password='remote-secret-marker'),
         dict(id=22, name='vmm-container-b', state=False, node={'id': 2}, cluster={'id': 2, 'virtualization_type': 'lxd'})]

NETWORKS = [dict(id=1, name='192.0.2.0/24', gateway='192.0.2.1', vlan_id=12, userspace=1, network_type='active', total_ip='254', using_ip='3'),
            dict(id=2, name='2001:db8::/64', gateway='2001:db8::1', userspace=1, network_type='active', total_ip='18446744073709551616', using_ip='1')]
POOLS = [dict(id=1, name='Public', userspace=1, ipnets=[{'id': 1}, {'id': 2}], total_ip='18446744073709551870', using_ip='4')]
ADDRESSES = [dict(id=1, name='192.0.2.21', ipnet=1, ippool=1, userspace=1, status='busy', locked=False, note='remote-secret-marker'),
             dict(id=2, name='2001:db8::21', ipnet=2, ippool=1, userspace=1, status='busy'),
             dict(id=3, name='192.0.2.1', ipnet=1, ippool=1, status='special', locked=True),
             dict(id=4, name='2001:db8::/80', ipnet=2, ippool=1, status='busy')]

STORAGES = [dict(id=6,name='local',type='lvm',shared=False,cluster=[{'id':1}],node=[{'id':1}],storage_mib={'total':'9007199254740993','used':'1024','free':'9007199254739969'},storage_params={'password':'remote-secret-marker'}),dict(id=7,name='ceph',type='rbd',shared=True,clusters=[1,2],nodes=[1,2],state='active')]
OS = [dict(id=12,name='Ubuntu 24.04',version='24.04',arch='x86_64',os_family='linux',clusters=[1],repository=3,enabled=True,password='remote-secret-marker')]
PRESETS = [dict(id=1,name='Small KVM',cpu_number=2,ram_mib=4096,hdd_mib=32768,cluster=1,os=12)]
IMAGES = [dict(id=1,name='golden-image',size_mib='9007199254740993',os=12,state='ready',path='remote-secret-marker')]
ISOS = [dict(id=1,name='install.iso',size_mib=1024,repository=3,url='https://remote-secret-marker.invalid')]
CATALOGS = {'/vm/v3/storage': STORAGES, '/vm/v3/os': OS, '/vm/v3/preset': PRESETS, '/vm/v3/image': IMAGES, '/vm/v3/os/iso': ISOS}

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
        if path.path not in ('/vm/v3/node', '/vm/v3/host', '/vm/v3/cluster', '/vm/v3/task', '/ip/v3/ipnet', '/ip/v3/ippool', '/ip/v3/ip', *CATALOGS) or not match or not limit_match or args.get('orderby') != ['id asc']:
            self.reply({'error': {'code': 400}}, 400)
            return
        after, limit = int(match[1]), int(limit_match[1])
        if path.path == '/vm/v3/task' and token not in ('fixture-vmm-capabilities', 'fixture-vmm-cap-revoked'):
            if token == 'fixture-vmm-task-denied':
                self.reply({'error': {'code': 42}}, 403); return
            if token == 'fixture-vmm-task-midfail' and after > 0:
                self.reply({'error': {'code': 503}}, 503); return
            rows = [dict(id=10, name='host_start', consul_id=1341774670, status='running', host={'id':21}, params={'password':'remote-secret-marker'}),
                    dict(id=11, name='host_stop', consul_id='1341774671', status='deferred'),
                    dict(id=12, name='host_restart', status='complete'),
                    dict(id=13, name='host_create', status='fail'),
                    dict(id=14, name='host_backup', status='created'),
                    dict(id=15, name='host_other', status='unrecognized')]
            if token == 'fixture-vmm-task-done':
                rows[0]['status']='complete'; rows[1]['status']='running'; rows[5]['status']='fail'
                rows.append(dict(id=16,name='host_start',status='created'))
            if token == 'fixture-vmm-task-missing': rows=rows[1:]
            if token == 'fixture-vmm-task-late': rows.insert(0,dict(id=9,name='host_start',status='complete'))
            if token == 'fixture-vmm-task-stale' and after==9 and limit==1: rows[0]['status']='complete'
            if token == 'fixture-vmm-scoped': rows=rows[:1]
            if token == 'fixture-vmm-task-many': rows=[dict(id=i,name='host_start',status='complete') for i in range(1, 704)]
            if token == 'fixture-vmm-task-duplicate' and after > 0:
                self.reply({'list':rows[:limit]});return
            # For the real worker test, a second run sees completion on the same credentials.
            if token == 'fixture-vmm-rotated':
                if getattr(Handler,'task_history_finished',False): rows[0]['status']='complete'
                if limit==100 and after>=15: Handler.task_history_finished=True
            self.reply({'list':[row for row in rows if row['id']>after][:limit]}); return
        if path.path in CATALOGS:
            rows = [dict(r) for r in CATALOGS[path.path]]
            if token == 'fixture-vmm-catalog-denied' and path.path == '/vm/v3/os':
                self.reply({'error': {'code': 42}}, 403); return
            if token == 'fixture-vmm-catalog-not-found' and path.path == '/vm/v3/os/iso':
                self.reply({'error': {'code': 42}}, 404); return
            if token == 'fixture-vmm-catalog-midfail' and path.path == '/vm/v3/storage' and after > 0:
                self.reply({'error': {'code': 42}}, 503); return
            if token == 'fixture-vmm-catalog-revoked' and path.path == '/vm/v3/image':
                self.reply({'error': {'code': 3001}}); return
            if token == 'fixture-vmm-catalog-many' and path.path == '/vm/v3/os':
                rows = [dict(id=i, name=f'OS {i}') for i in range(1,103)]
            if token == 'fixture-vmm-catalog-duplicate' and path.path == '/vm/v3/storage':
                rows = [rows[0],rows[0]]
            if token == 'fixture-vmm-catalog-unlinked' and path.path == '/vm/v3/storage':
                rows = rows[1:]
            self.reply({'list':[row for row in rows if row['id']>after][:limit]}); return
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
        if path.path.startswith('/ip/v3/') and token != 'fixture-vmm-capabilities' and token != 'fixture-vmm-cap-revoked':
            rows = [dict(row) for row in {'/ip/v3/ipnet': NETWORKS, '/ip/v3/ippool': POOLS, '/ip/v3/ip': ADDRESSES}[path.path]]
            if token == 'fixture-vmm-network-denied' and path.path.endswith('/ip'):
                self.reply({'error': {'code': 42}}, 403); return
            if token == 'fixture-vmm-network-midfail' and path.path.endswith('/ip') and after > 0:
                self.reply({'error': {'code': 42}}, 503); return
            if token == 'fixture-vmm-network-overlap' and path.path.endswith('/ipnet'):
                rows.append(dict(id=3,name='192.0.2.0/25',userspace=2,network_type='active'))
            if token == 'fixture-vmm-network-duplicate' and path.path.endswith('/ip'):
                rows.append(dict(id=5,name='192.0.2.21',ipnet=1,userspace=2,status='busy'))
            if token == 'fixture-vmm-network-userspace' and path.path.endswith('/ip'):
                rows[0]['userspace']=0
            if token == 'fixture-vmm-pool-missing' and path.path.endswith('/ippool'):
                rows=[]
            if token == 'fixture-vmm-pool-unverified' and path.path.endswith('/ippool'):
                rows[0].pop('ipnets',None)
            if token == 'fixture-vmm-pool-network' and path.path.endswith('/ippool'):
                rows[0]['ipnets']=[{'id':2}]
            if token == 'fixture-vmm-pool-space' and path.path.endswith('/ippool'):
                rows[0]['userspace']=2
            if token == 'fixture-vmm-network-invalid' and path.path.endswith('/ipnet'):
                rows[0]['name']='192.0.2.5/24'
            if token == 'fixture-vmm-network-many' and path.path.endswith('/ippool'):
                rows=[dict(id=i,name=f'Pool {i}',ipnets=[{'id':1}]) for i in range(1,103)]
            if token == 'fixture-vmm-scoped': rows=rows[:1]
            self.reply({'list':[row for row in rows if row['id']>after][:limit]}); return
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
            if token == 'fixture-vmm-network-duplicate-mac':
                rows[1]['iface']=[{'id':42,'name':'eth0','mac':'02:00:00:00:00:01','ip':[{'name':'192.0.2.22'}]}]
            if token == 'fixture-vmm-scoped':
                rows = rows[:1]
            if token == 'fixture-vmm-rotated':
                rows[0].update(name='vmm-guest-renamed', node={'id': 2}, cluster={'id': 2, 'virtualization_type': 'kvm'}, cpu_number=8)
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
$ok(count($health->connection['capabilities'])===12&&$health->connection['capabilities']['tasks']==='available'&&!str_contains(json_encode($health->connection),'remote-secret-marker'),'real read probes, empty list accessible, no remote PII');
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
$guestPlan=new \Cajeer\Servers\Collector\CollectionPlan('vmmanager.guests','sync');
$guest=static fn(string $token)=>$collector->collect($guestPlan,$target,'api_token',$token);
$ok($catalog['vmmanager.guests']['operations']===['inspect','sync'],'guest collector registered');
$r=$guest('fixture-vmm-active');$d=$r->connection['vmmanager_guest_discovery'];$g=$d['guests'][0];
$ok($r->complete&&$d['complete']&&$d['counts']===['nodes'=>2,'guests'=>2,'unlinked_guests'=>0]&&$r->items[2]['type']==='virtual_machine'&&$r->items[3]['type']==='container','KVM and LXD complete scoped discovery');
$ok($g['cpu_number']==='4'&&$g['ram_mib']==='8192'&&$g['hdd_mib']==='9007199254740993'&&$g['os_name']==='Ubuntu 24.04'&&$g['account_id']===7,'actual host configuration exact units');
$ok($g['disks'][0]['size_mib']==='9007199254740993'&&$g['disks'][0]['storage_id']===6&&$g['interfaces'][0]['mac']==='02:00:00:00:00:01'&&$g['ip_addresses']===['192.0.2.21','2001:db8::21']&&!str_contains(json_encode($d),'remote-secret-marker'),'disk/NIC/IP allowlist and precision');
$ok($r->items[2]['fields']['os_family']==='ubuntu'&&$r->items[2]['fields']['ip_addresses']===$g['ip_addresses']&&$r->items[2]['parent_external_id']==='node:1','CMDB normalized observations');
$ok($d['guests'][1]['ram_mib']===null&&$d['guests'][1]['disks']===null&&$d['guests'][1]['interfaces']===null&&$d['guests'][1]['state']==='stopped','missing fields are unknown, not zero');
$r=$guest('fixture-vmm-rotated');$ok($r->items[2]['external_id']==='host:21'&&$r->items[2]['parent_external_id']==='node:2'&&$r->connection['vmmanager_guest_discovery']['guests'][0]['cpu_number']==='8','stable guest ID during move and resize');
$r=$guest('fixture-vmm-scoped');$ok(!$r->complete&&!$r->connection['vmmanager_guest_discovery']['complete'],'restricted collection never marks other guests missing');
$r=$guest('fixture-vmm-many');$ok(count($r->items)===104&&$r->connection['vmmanager_guest_discovery']['guests'][101]['id']===1101,'detailed host keyset pagination');
$r=$guest('fixture-vmm-short');$ok(count($r->items)===4,'guest short page does not terminate pagination');
foreach(['midfail'=>'connector_unavailable','duplicate'=>'connector_protocol_error','missing'=>'connector_protocol_error','unknown'=>'connector_protocol_error'] as $case=>$category){
 try{$guest('fixture-vmm-'.$case);throw new LogicException('accepted '.$case);}catch(HttpException $e){$ok($e->status===502&&$e->errorCode===$category,'guest rejects '.$case);}
}
try{$collector->collect($guestPlan,array_diff_key($target,['vmmanager_profile'=>true]),'api_token','fixture-vmm-active');throw new LogicException('missing guest profile');}catch(HttpException $e){$ok($e->errorCode==='collection_invalid','guest profile required');}
$networkPlan=new \Cajeer\Servers\Collector\CollectionPlan('vmmanager.network','sync');
$ok($catalog['vmmanager.network']['operations']===['inspect','sync'],'network collector registered');
$network=static fn(string $token)=>$collector->collect($networkPlan,$target,'api_token',$token);
$r=$network('fixture-vmm-active');$d=$r->connection['vmmanager_network_discovery'];
$ok(!$r->complete&&$r->items===[]&&$d['complete']&&$d['counts']===['networks'=>2,'pools'=>1,'addresses'=>4,'interfaces'=>1],'network scoped completeness cannot mark CMDB absent');
$ok($d['networks'][0]['cidr']==='192.0.2.0/24'&&$d['networks'][0]['vlan_id']===12&&$d['networks'][0]['gateway']==='192.0.2.1','network fields');
$ok($d['networks'][1]['free_ip']==='18446744073709551615'&&$d['pools'][0]['network_ids']===[1,2],'IPv6 exact free capacity and pool links');
$ok($d['addresses'][0]['nic_key']==='host:21:nic:41'&&$d['addresses'][1]['nic_key']==='host:21:nic:41'&&$d['interfaces'][0]['mac']==='02:00:00:00:00:01','explicit dual stack NIC links');
$ok($d['addresses'][2]['status']==='special'&&$d['addresses'][2]['nic_key']===null&&$d['addresses'][3]['issue']==='prefix_allocation','reserved and prefix status preserved');
$ok(!str_contains(json_encode($d),'remote-secret-marker'),'remote secrets omitted');
$d=$network('fixture-vmm-scoped')->connection['vmmanager_network_discovery'];$ok(!$d['complete'],'restricted visibility cannot mutate IPAM');
$d=$network('fixture-vmm-network-overlap')->connection['vmmanager_network_discovery'];$ok($d['networks'][0]['issue']==='overlapping_network'&&$d['networks'][2]['issue']==='overlapping_network','overlap both sides');
$d=$network('fixture-vmm-network-duplicate')->connection['vmmanager_network_discovery'];$ok($d['addresses'][0]['issue']==='duplicate_address'&&$d['addresses'][4]['issue']==='duplicate_address','duplicate remote addresses conflict');
$d=$network('fixture-vmm-network-userspace')->connection['vmmanager_network_discovery'];$ok($d['addresses'][0]['issue']==='userspace_mismatch'&&$d['addresses'][0]['userspace']===0,'userspace zero is distinct from unknown');
$d=$network('fixture-vmm-network-duplicate-mac')->connection['vmmanager_network_discovery'];$ok($d['interfaces'][0]['issue']==='duplicate_mac'&&$d['interfaces'][1]['issue']==='duplicate_mac'&&$d['addresses'][0]['issue']==='duplicate_mac','duplicate MAC blocks both NICs and assignment');
$d=$network('fixture-vmm-network-many')->connection['vmmanager_network_discovery'];$ok(count($d['pools'])===102,'IPmanager keyset pagination');
foreach(['pool-missing'=>'pool_not_visible','pool-unverified'=>'pool_network_unverified','pool-network'=>'pool_network_mismatch','pool-space'=>'pool_userspace_mismatch'] as $case=>$issue){$d=$network('fixture-vmm-'.$case)->connection['vmmanager_network_discovery'];$ok($d['addresses'][0]['issue']===$issue,'unsafe pool relationship '.$case);}
foreach(['network-denied'=>'connector_auth_failed','network-midfail'=>'connector_unavailable','network-invalid'=>'connector_protocol_error'] as $case=>$category){try{$network('fixture-vmm-'.$case);throw new LogicException('accepted '.$case);}catch(HttpException $e){$ok($e->errorCode===$category,'network rejects '.$case);}}
$storagePlan=new \Cajeer\Servers\Collector\CollectionPlan('vmmanager.storage','inspect');
$templatePlan=new \Cajeer\Servers\Collector\CollectionPlan('vmmanager.templates','inspect');
$ok($catalog['vmmanager.storage']['operations']===['inspect']&&$catalog['vmmanager.templates']['operations']===['inspect'],'catalog collectors inspect only');
$storage=static fn(string $token)=>$collector->collect($storagePlan,$target,'api_token',$token);
$templates=static fn(string $token)=>$collector->collect($templatePlan,$target,'api_token',$token);
$r=$storage('fixture-vmm-active');$d=$r->connection['vmmanager_storage_discovery'];
$ok($r->items===[]&&!$r->complete&&$d['counts']===['storages'=>2,'nodes'=>2,'volumes'=>1],'storage does not reconcile CMDB');
$ok($d['storages'][0]['capacity_mib']['total']==='9007199254740993'&&$d['storages'][0]['capacity_mib']['free']==='9007199254739969','exact physical capacity');
$ok($d['storages'][0]['cluster_ids']===[1]&&$d['storages'][1]['shared']&&$d['storages'][1]['node_ids']===[1,2],'storage membership and shared status');
$ok($d['volumes'][0]['key']==='host:21:disk:31'&&$d['volumes'][0]['storage_visible']&&$d['storages'][0]['visible_disks']===1&&$d['volumes'][0]['size_mib']==='9007199254740993','disk placement via exact storage ID');
$ok($d['unknown_guest_disks']===1&&!$d['complete']&&!str_contains(json_encode($d),'remote-secret-marker'),'missing disk data and no secrets');
$d=$storage('fixture-vmm-catalog-unlinked')->connection['vmmanager_storage_discovery'];$ok($d['unlinked_volumes']===1&&!$d['volumes'][0]['storage_visible'],'unresolved disk preserved');
$d=$templates('fixture-vmm-active')->connection['vmmanager_templates_discovery'];
$ok($d['counts']===['os'=>1,'presets'=>1,'images'=>1,'isos'=>1,'guest_os_links'=>1]&&$d['guest_os_links'][0]['os_visible'],'independent catalogs and OS identity');
$ok($d['os'][0]['architecture']==='x86_64'&&$d['presets'][0]['ram_mib']==='4096'&&$d['images'][0]['size_mib']==='9007199254740993'&&!str_contains(json_encode($d),'remote-secret-marker'),'allowlisted template metadata');
$d=$templates('fixture-vmm-catalog-denied')->connection['vmmanager_templates_discovery'];$ok(!$d['complete']&&$d['sections']['os']==='denied'&&$d['os']===[]&&count($d['presets'])===1&&!$d['guest_os_links'][0]['os_visible'],'ACL isolates catalog');
$d=$templates('fixture-vmm-catalog-not-found')->connection['vmmanager_templates_discovery'];$ok(!$d['complete']&&$d['sections']['isos']==='not_found','missing catalog distinguished from empty');
$d=$templates('fixture-vmm-catalog-many')->connection['vmmanager_templates_discovery'];$ok(count($d['os'])===102,'catalog keyset pagination');
$d=$templates('fixture-vmm-scoped')->connection['vmmanager_templates_discovery'];$ok(!$d['complete']&&$d['visibility']==='restricted','scoped catalog incomplete');
foreach(['catalog-midfail'=>'connector_unavailable','catalog-duplicate'=>'connector_protocol_error'] as $case=>$category){try{$storage('fixture-vmm-'.$case);throw new LogicException('accepted '.$case);}catch(HttpException $e){$ok($e->errorCode===$category,'storage rejects '.$case);}}
try{$templates('fixture-vmm-catalog-revoked');throw new LogicException('accepted revoked');}catch(HttpException $e){$ok($e->errorCode==='connector_auth_failed','revoked token is not partial ACL');}
try{$collector->collect($storagePlan,array_diff_key($target,['vmmanager_profile'=>true]),'api_token','fixture-vmm-active');throw new LogicException('missing storage profile');}catch(HttpException $e){$ok($e->errorCode==='collection_invalid','catalog profile required');}
try{$collector->collect(new \Cajeer\Servers\Collector\CollectionPlan('vmmanager.templates','sync'),$target,'api_token','fixture-vmm-active');throw new LogicException('accepted sync');}catch(HttpException $e){$ok($e->errorCode==='collection_invalid','catalog sync prohibited');}

$taskPlan=new \Cajeer\Servers\Collector\CollectionPlan('vmmanager.tasks','inspect');
$ok($catalog['vmmanager.tasks']['operations']===['inspect'],'task collector inspect only');
$taskRun=static fn(string $token,array $previous=[])=>$collector->collect($taskPlan,$target,'api_token',$token,$previous)->connection['vmmanager_task_discovery'];
$d=$taskRun('fixture-vmm-active');
$ok($d['high_water_id']===15&&$d['caught_up']&&$d['observed_count']===6,'task keyset history');
$by=array_column($d['tasks'],null,'remote_id');
$ok($by[10]['consul_id']==='1341774670'&&$by[10]['resource_id']==='host:21'&&$by[10]['state']==='running'&&!str_contains(json_encode($d),'remote-secret-marker'),'allowlisted task metadata');
$ok(array_column($d['tasks'],'state')===['running','deferred','succeeded','failed','queued','unknown'],'documented status mapping');
$prior=['revision'=>1,'cursor'=>$d['cursor'],'pending'=>[10,11,14,15]];
$next=$taskRun('fixture-vmm-task-done',$prior);$by=array_column($next['tasks'],null,'remote_id');
$ok($next['expected_revision']===1&&$next['high_water_id']===16&&$by[10]['state']==='succeeded'&&$by[15]['state']==='failed','pending completion and new ID');
$late=$taskRun('fixture-vmm-task-late',$prior);$ok($late['high_water_id']===15&&isset(array_column($late['tasks'],null,'remote_id')[9]),'late-visible ID below committed cursor');
$stale=$taskRun('fixture-vmm-task-stale',$prior);$ok(array_column($stale['tasks'],null,'remote_id')[10]['state']==='succeeded','stale list cannot override terminal status read');
$missing=$taskRun('fixture-vmm-task-missing',$prior);$ok($missing['checked'][10]==='not_visible'&&!isset(array_column($missing['tasks'],null,'remote_id')[10]),'missing pending remains unconfirmed');
$many=$taskRun('fixture-vmm-task-many');$ok($many['observed_count']===500&&!$many['caught_up']&&$many['high_water_id']===500,'bounded first history window');
$many2=$taskRun('fixture-vmm-task-many',['revision'=>1,'cursor'=>$many['cursor']]);$ok($many2['high_water_id']===703&&$many2['caught_up']&&$many2['observed_count']===303,'history resumes and bounded sweep');
$ok($taskRun('fixture-vmm-scoped')['visibility']==='restricted','scope restriction explicit');
foreach(['task-denied'=>'connector_auth_failed','task-midfail'=>'connector_unavailable','task-duplicate'=>'connector_protocol_error'] as $case=>$category){try{$taskRun('fixture-vmm-'.$case);throw new LogicException('accepted '.$case);}catch(HttpException $e){$ok($e->errorCode===$category,'tasks reject '.$case.': '.$e->errorCode);}}
try{$taskRun('fixture-vmm-scoped',$prior);throw new LogicException('accepted identity drift');}catch(HttpException $e){$ok($e->errorCode==='connector_auth_failed','cursor identity fenced');}
try{$collector->collect(new \Cajeer\Servers\Collector\CollectionPlan('vmmanager.tasks','sync'),$target,'api_token','fixture-vmm-active');throw new LogicException('accepted sync');}catch(HttpException $e){$ok($e->errorCode==='collection_invalid','task sync prohibited');}
try{$collector->collect($taskPlan,array_diff_key($target,['vmmanager_profile'=>true]),'api_token','fixture-vmm-active');throw new LogicException('missing task profile');}catch(HttpException $e){$ok($e->errorCode==='collection_invalid','task profile required');}
$ok(\Cajeer\Servers\Connector\VmmanagerTaskDiscovery::merge('succeeded','running')==='succeeded','confirmed terminal outcome preserved');

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
