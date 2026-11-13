#!/usr/bin/env python3
"""Real independent TLS endpoints; verify no credential is sent on pin mismatch."""
import hashlib, http.server, json, pathlib, ssl, subprocess, tempfile, threading, urllib.parse, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
TASK='UPID:pve-a:00000001:00000002:00000003:qmstart:100:svc@pve!read:'
START=int(time.time())-10
HISTORY_TASK=f'UPID:pve-a:00000003:00000002:{START:08X}:qmstart:100:svc@pve!read:'
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def reply(self,data):
        b=json.dumps({'data':data}).encode();self.send_response(200);self.send_header('Content-Length',str(len(b)));self.end_headers();self.wfile.write(b)
    def do_GET(self):
        self.server.calls.append(('GET',self.path))
        if self.headers.get('Authorization')!='PVEAPIToken=svc@pve!read=fixture-secret':self.send_error(401);return
        if self.server.mode=='auth':self.send_error(403);return
        if self.path=='/api2/json/version':self.reply({'version':'9.0.3'})
        elif self.path=='/api2/json/cluster/status':self.reply([{'type':'cluster','name':'other' if self.server.mode=='wrong' else 'prod','quorate':int(self.server.mode!='cluster-noquorum'),'nodes':2,'version':9},{'type':'node','name':'pve-a','nodeid':1,'online':1,'local':int(self.server.node=='pve-a')},{'type':'node','name':'pve-b','nodeid':2,'online':0,'local':int(self.server.node=='pve-b')}])
        elif self.path=='/api2/json/nodes':
            if self.server.mode=='cluster-failover':self.send_error(503);return
            self.reply([{'node':'pve-a','status':'online'},{'node':'pve-b','status':'offline'}])
        elif self.path=='/api2/json/nodes/pve-a/status':
            if self.server.mode=='cluster-denied':self.send_error(403);return
            if self.server.mode=='cluster-auth':self.send_error(401);return
            if self.server.mode=='cluster-unavailable':self.send_error(503);return
            self.reply({'pveversion':'pve-manager/9.0.3/test','cpuinfo':{'cpus':16,'cores':8,'sockets':1,'model':'TLS fixture CPU'},'memory':{'total':34359738368,'used':4294967296},'uptime':1000,'rootfs':{'total':100000,'used':20000},'swap':{'total':0,'used':0},'secret':'must-not-leak'})
        elif self.path=='/api2/json/access/permissions':self.reply({'/':{'Sys.Audit':1}})
        elif self.path=='/api2/json/cluster/resources?type=vm':
            if self.server.mode in ('late','guest-failover'):self.send_error(503);return
            if self.server.mode.startswith('guest-') or self.server.guest_fixture:
                node='pve-b' if self.server.mode=='guest-race' and sum(p==self.path for m,p in self.server.calls)>1 else 'pve-a'
                self.reply([{'type':'qemu','node':node,'vmid':100,'status':'running','pool':'prod'},{'type':'lxc','node':'pve-b','vmid':200,'status':'stopped'}])
            else:self.reply([{'type':'qemu','node':'pve-a','vmid':100,'status':'running'}])
        elif self.path=='/api2/json/storage':self.reply([{'storage':'local-lvm','type':'lvmthin','content':'images,rootdir','path':'must-not-leak'}])
        elif self.path=='/api2/json/nodes/pve-a/storage':
            if self.server.mode=='storage-failover':self.send_error(503);return
            self.reply([{'storage':'local-lvm','type':'lvmthin','active':1,'enabled':1,'shared':0,'total':9007199254740993,'used':100,'avail':200}])
        elif self.path=='/api2/json/nodes/pve-a/network?type=include_sdn':
            if self.server.mode=='storage-denied':self.send_error(403);return
            self.reply([{'iface':'vmbr0','type':'bridge','bridge_ports':'eno1','bridge_vlan_aware':1},{'iface':'eno1','type':'eth'},{'iface':'vlan20','type':'vlan','vlan-raw-device':'eno1','vlan-id':20}])
        elif self.path=='/api2/json/nodes/pve-a/storage/local-lvm/content':self.reply([{'volid':'local-lvm:vm-100-disk-0','size':34359738368,'format':'raw','vmid':100}])
        elif self.path=='/api2/json/nodes/pve-a/qemu/100/config':
            if self.server.mode=='guest-denied':self.send_error(403);return
            if self.server.mode=='guest-auth':self.send_error(401);return
            if self.server.mode=='guest-rate':self.send_error(429);return
            self.reply({'cores':4,'memory':8192,'scsi0':'local-lvm:vm-100-disk-0,size=32G','net0':'virtio=02:00:00:00:00:01,bridge=vmbr0,tag=20','smbios1':'uuid=11111111-1111-4111-8111-111111111111','cipassword':'must-not-leak'})
        elif self.path=='/api2/json/nodes/pve-a/qemu/100/status/current':self.reply({'vmid':100,'status':'running','cpu':0.25,'mem':4294967296})
        elif self.path=='/api2/json/nodes/pve-b/lxc/200/config':self.reply({'cores':2,'memory':2048,'rootfs':'local-lvm:vm-200-disk-0,size=8G','unprivileged':1,'net0':'name=eth0,bridge=vmbr0,ip=192.0.2.2/24'})
        elif self.path=='/api2/json/nodes/pve-b/lxc/200/status/current':self.reply({'vmid':200,'status':'stopped','uptime':0})
        elif self.path.startswith('/api2/json/nodes/pve-a/tasks?'):
            if self.server.mode=='tasks-denied':self.send_error(403);return
            if self.server.mode=='tasks-failover':self.send_error(503);return
            q=urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query)
            self.reply([{'upid':HISTORY_TASK,'node':'pve-a','type':'qmstart','starttime':START,'status':'RUNNING','secret':'must-not-leak'}] if q['source']==['active'] else [])
        elif urllib.parse.unquote(self.path)==f'/api2/json/nodes/pve-a/tasks/{HISTORY_TASK}/status':self.reply({'status':'stopped','exitstatus':'OK','upid':HISTORY_TASK,'starttime':START})
        elif urllib.parse.unquote(self.path)==f'/api2/json/nodes/pve-a/tasks/{TASK}/status':self.reply({'status':'stopped','exitstatus':'OK'})
        else:self.send_error(404)
    def do_POST(self):
        self.server.calls.append(('POST',self.path));self.reply(TASK)
with tempfile.TemporaryDirectory() as tmp:
    servers=[];threads=[];pins=[];certs=[]
    try:
        for node in ['pve-a','pve-b']:
            cert=pathlib.Path(tmp)/f'{node}.pem';key=pathlib.Path(tmp)/f'{node}.key'
            subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(key),'-out',str(cert),'-subj',f'/CN={node}.test','-addext',f'subjectAltName=DNS:{node}.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            pins.append(hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest());certs.append(cert.read_text())
            server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);server.calls=[];server.mode='ok';server.node=node;server.guest_fixture=False
            context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(cert,key);server.socket=context.wrap_socket(server.socket,server_side=True)
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start();servers.append(server);threads.append(thread)
        ca=pathlib.Path(tmp)/'ca.pem';ca.write_text(''.join(certs))
        code=r'''
require $argv[1].'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ProxmoxConnector,EndpointPolicy,HttpsTransport,ConnectorTransportException};
$profile=['cluster'=>'prod','anchor_node'=>'pve-a','anchor_id'=>1,'primary_pin'=>$argv[5],'fallbacks'=>[['host'=>'pve-b.test','port'=>(int)$argv[3],'pin'=>$argv[6]]]];
$mode=$argv[7];if($mode==='pin')$profile['primary_pin']=str_repeat('0',64);
$ctx=new ConnectorContext('proxmox','pve-a.test',(int)$argv[2],'proxmox_api',$profile);
$d=new ProxmoxConnector(null,$argv[4],new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),2));
$secret='{"token_id":"svc@pve!read","token_secret":"fixture-secret"}';
try{
if(str_starts_with($mode,'tasks-')){
    $r=$d->tasks($ctx,$secret,[]);$s=$r->connection['task_discovery'];
    if($mode==='tasks-denied'){if($s['nodes']->{'pve-a'}['active']!=='permission_denied'||$s['observed_count']!==0)throw new RuntimeException('tasks ACL');}
    else{if($s['observed_count']!==1||$s['tasks'][0]['state']!=='running'||str_contains(json_encode($s),'must-not-leak'))throw new RuntimeException('task discovery');
        $r2=$d->tasks($ctx,$secret,['cursor'=>$s['cursor'],'revision'=>1,'pending'=>[['upid'=>$s['tasks'][0]['upid']]]]);if($r2->connection['task_discovery']['tasks'][0]['state']!=='succeeded')throw new RuntimeException('status poll');
        if($mode==='tasks-failover'&&!$r->connection['failover'])throw new RuntimeException('task failover');}
}
elseif(str_starts_with($mode,'storage-')){
    $r=$d->storageNetwork($ctx,$secret);$s=$r->connection['storage_network_discovery'];$n=$s['nodes'][0];$g=$s['guests'][0];
    if($r->complete||$n['storages'][0]['total']!=='9007199254740993'||!$g['disks'][0]['volume_resolved']||str_contains(json_encode($s),'must-not-leak'))throw new RuntimeException('storage discovery');
    if($mode==='storage-denied'){if($n['network_state']!=='permission_denied'||$g['nics'][0]['bridge_resolved'])throw new RuntimeException('network ACL');}
    else{if(!$g['nics'][0]['bridge_resolved']||$n['interfaces'][1]['links'][0]['iface']!=='eno1')throw new RuntimeException('network topology');}
    if($mode==='storage-failover'&&!$r->connection['failover'])throw new RuntimeException('storage failover');
}
elseif(str_starts_with($mode,'guest-')){
    $r=$d->guests($ctx,$secret);$s=$r->connection['guest_discovery'];$g=array_column($s['guests'],null,'external_id');
    if($r->complete||$s['guest_count']!==2||str_contains(json_encode($s),'must-not-leak'))throw new RuntimeException('guest snapshot');
    if($mode==='guest-auth'||$mode==='guest-race')throw new LogicException('expected refusal');
    if($mode==='guest-denied'){if($g['qemu:100']['config_state']!=='permission_denied'||$g['qemu:100']['runtime']===null)throw new RuntimeException('partial guest config');}
    elseif($mode==='guest-rate'){if($s['details_complete']||$g['qemu:100']['config_state']!=='rate_limited')throw new RuntimeException('rate limiting');}
    else{if($g['qemu:100']['config']['memory']!==8192||$g['qemu:100']['config']['disks'][0]['volume_id']!=='local-lvm:vm-100-disk-0'||$g['lxc:200']['config']['nics'][0]['ip']!=='192.0.2.2/24')throw new RuntimeException('guest config');if($mode==='guest-failover'&&!$r->connection['failover'])throw new RuntimeException('guest failover');}
}
elseif(str_starts_with($mode,'cluster-')){
    $r=$d->cluster($ctx,$secret);$s=$r->connection['cluster_discovery'];$node=$s['nodes'][0];
    if($r->complete||count($r->items)!==3||$r->items[1]['parent_external_id']!=='cluster:prod'||$s['member_count']!==2||$s['nodes'][1]['status']!=='offline'||str_contains(json_encode($s),'must-not-leak'))throw new RuntimeException('cluster metadata');
    if($mode==='cluster-denied'||$mode==='cluster-unavailable'){if($node['details']!==null||$node['details_state']!==($mode==='cluster-denied'?'permission_denied':'unavailable'))throw new RuntimeException('partial details');}
    elseif($mode==='cluster-auth')throw new LogicException('auth unexpectedly accepted');
    else{if($node['details']['cpu']['logical_cpus']!==16||$node['details']['memory_bytes']['total']!=='34359738368')throw new RuntimeException('hardware');if($mode==='cluster-noquorum'&&$s['cluster']['quorate'])throw new RuntimeException('quorum');if($mode==='cluster-failover'&&!$r->connection['failover'])throw new RuntimeException('cluster failover');}
}
elseif($mode==='post'){$r=$d->prepareAction($ctx,'vm.start','qemu:100',$secret);$upid=$d->dispatchAction($ctx,'vm.start',$r,$secret);if($d->actionStatus($ctx,'pve-a',$upid,$secret)!=='succeeded')throw new RuntimeException('task');}
elseif($mode==='write-down'){$d->dispatchAction($ctx,'vm.start',['node'=>'pve-a','vmid'=>100],$secret);throw new LogicException('write unexpectedly succeeded');}
else{$result=$d->execute($ctx,$mode==='health'?'health':'inspect',$secret);if($mode==='late'){if(!$result->connection['failover']||count($result->items)!==3||$result->complete)throw new RuntimeException('failover failed');}
elseif($mode==='health'){if($result->connection['version']!=='9.0.3'||$result->connection['root_privileges']!==['Sys.Audit'])throw new RuntimeException('diagnostics');}
elseif($mode==='down'){if(!$result->connection['failover'])throw new RuntimeException('connection failover');}
else throw new LogicException('unexpected success');}
}catch(ConnectorTransportException $e){$expected=match($mode){'pin','wrong'=>'connector_trust_failed','auth','cluster-auth','guest-auth'=>'connector_auth_failed','guest-race'=>'connector_protocol_error','write-down'=>'connector_transport_failed',default=>''};if($e->category!==$expected)throw $e;}
'''
        def run(mode,port=None):
            subprocess.run(['php','-r',code,str(ROOT),str(port or servers[0].server_port),str(servers[1].server_port),str(ca),*pins,mode],check=True,timeout=30)
        run('health');assert len(servers[0].calls)==4 and not servers[1].calls
        servers[0].calls.clear();run('pin');assert not servers[0].calls and not servers[1].calls
        servers[0].mode='auth';run('auth');assert len(servers[0].calls)==1 and not servers[1].calls
        servers[0].calls.clear();servers[0].mode='wrong';run('wrong');assert len(servers[0].calls)==2 and not servers[1].calls
        servers[0].calls.clear();servers[0].mode='late';run('late');assert len(servers[0].calls)==4 and len(servers[1].calls)==4
        servers[0].calls.clear();servers[1].calls.clear();servers[0].mode='ok';run('post');assert sum(m=='POST' for m,p in servers[0].calls)==1 and not servers[1].calls
        # Full cluster collection on real TLS, including partial and non-quorate results.
        for mode in ['cluster-ok','cluster-denied','cluster-unavailable','cluster-auth','cluster-noquorum','cluster-failover']:
            servers[0].calls.clear();servers[1].calls.clear();servers[0].mode=mode;run(mode)
            assert not any(m=='POST' or '/pve-b/status' in p for m,p in servers[0].calls+servers[1].calls)
            if mode=='cluster-failover':assert len(servers[0].calls)==3 and len(servers[1].calls)==4
            else:assert len(servers[0].calls)==4 and not servers[1].calls
        for mode in ['guest-ok','guest-denied','guest-auth','guest-rate','guest-race','guest-failover']:
            servers[0].calls.clear();servers[1].calls.clear();servers[0].mode=mode;servers[1].guest_fixture=True;run(mode)
            assert not any(m=='POST' for m,p in servers[0].calls+servers[1].calls)
            if mode=='guest-failover':assert len(servers[0].calls)==4 and len(servers[1].calls)==9
            else:assert not servers[1].calls
        servers[1].guest_fixture=False
        for mode in ['storage-ok','storage-denied','storage-failover']:
            servers[0].calls.clear();servers[1].calls.clear();servers[0].mode=mode;run(mode)
            assert not any(m=='POST' for m,p in servers[0].calls+servers[1].calls)
            if mode=='storage-failover':assert any('/storage/local-lvm/content' in p for m,p in servers[1].calls)
            else:assert not servers[1].calls
        for mode in ['tasks-ok','tasks-denied','tasks-failover']:
            servers[0].calls.clear();servers[1].calls.clear();servers[0].mode=mode;run(mode)
            assert not any(m=='POST' for m,p in servers[0].calls+servers[1].calls)
            if mode=='tasks-failover':assert any('/tasks?' in p for m,p in servers[1].calls)
            else:assert not servers[1].calls
        # A closed port is an actual connection failure; the configured fallback stays reachable.
        primary_port=servers[0].server_port;servers[0].shutdown();servers[0].server_close();threads[0].join(5)
        run('down',primary_port);before=len(servers[1].calls);run('write-down',primary_port);assert len(servers[1].calls)==before
        print('Proxmox profile HTTPS: 26 scenarios PASS (pin before auth, identity, auth, failover, single POST, no write failover, hardware, partial details, quorum, cluster failover, guest configs, partial ACL, migration race, guest failover, storage/network ACL and failover, task history/status ACL and failover)')
    finally:
        for server,thread in zip(servers,threads):
            if thread.is_alive():server.shutdown();server.server_close();thread.join(5)
