#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Collector\{CollectorRegistry,CollectionPlan,CollectionRuntimeService};
use Cajeer\Servers\Connector\{ProxmoxConnector,ProxmoxGuestDiscovery,ProxmoxGuestStore,ConnectorTransportException};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;
$n=0;$ok=static function(bool $v,string $m)use(&$n){if(!$v)throw new RuntimeException('FAIL '.$m);$n++;};
$reject=static function(callable $f,string $code)use($ok){try{$f();}catch(HttpException $e){$ok($e->errorCode===$code,$code.' got '.$e->errorCode);return;}catch(ConnectorTransportException $e){$ok($e->category===$code,$code);return;}throw new RuntimeException('Expected '.$code);};
$profile=['cluster'=>'prod','anchor_node'=>'pve-a','anchor_id'=>1,'primary_pin'=>str_repeat('a',64),'fallbacks'=>[]];
$target=['provider'=>'proxmox','endpoint_host'=>'pve-a.test','endpoint_port'=>8006,'credential_type'=>'proxmox_api','purpose'=>'api','proxmox_profile'=>$profile];
$secret='{"token_id":"svc@pve!read","token_secret":"fixture-secret"}';
$mode='ok';$lists=0;$calls=[];
$driver=new ProxmoxConnector(static function($url,$auth,$ca)use(&$mode,&$lists,&$calls):string{
    $path=parse_url($url,PHP_URL_PATH);$calls[]=$path;
    if($auth!=='PVEAPIToken=svc@pve!read=fixture-secret')throw new RuntimeException('auth');
    if(str_ends_with($path,'/config')&&str_contains($path,'/qemu/')){if($mode==='denied')throw ConnectorTransportException::forStatus(403);if($mode==='auth')throw ConnectorTransportException::forStatus(401);if($mode==='rate')throw ConnectorTransportException::forStatus(429);if($mode==='trust')throw new ConnectorTransportException('connector_trust_failed');}
    $node=$mode==='moved'?'pve-b':'pve-a';
    $rows=[['type'=>'qemu','vmid'=>100,'node'=>$node,'name'=>'VM','status'=>'running','pool'=>'prod','template'=>0],['type'=>'lxc','vmid'=>200,'node'=>'pve-b','name'=>'CT','status'=>'stopped','template'=>1]];
    if($path==='/api2/json/cluster/resources'){$lists++;if($mode==='race'&&$lists%2===0)$rows[0]['node']='pve-b';if($mode==='duplicate')$rows[1]['vmid']=100;}
    return json_encode(['data'=>match(true){
        $path==='/api2/json/version'=>['version'=>'9.0.3'],
        $path==='/api2/json/cluster/status'=>[['type'=>'cluster','name'=>'prod','quorate'=>1],['type'=>'node','name'=>'pve-a','nodeid'=>1,'local'=>1,'online'=>1],['type'=>'node','name'=>'pve-b','nodeid'=>2,'local'=>0,'online'=>1]],
        $path==='/api2/json/nodes'=>[['node'=>'pve-a','status'=>'online'],['node'=>'pve-b','status'=>'online']],
        $path==='/api2/json/cluster/resources'=>$rows,
        str_ends_with($path,'/qemu/100/config')=>['cores'=>4,'sockets'=>2,'memory'=>8192,'balloon'=>4096,'cpu'=>'host,flags=+aes','bios'=>'ovmf','machine'=>'type=q35,viommu=intel','boot'=>'order=scsi0;net0','tags'=>'prod;web','scsi0'=>'local-lvm:vm-100-disk-0,size=32G,discard=on','ide2'=>'local-lvm:vm-100-cloudinit,media=cdrom','net0'=>'virtio=02:00:00:00:00:01,bridge=vmbr0,tag=20,trunks=30;40,firewall=1','smbios1'=>'uuid=11111111-1111-4111-8111-111111111111,serial=secret-serial','cipassword'=>'must-not-leak','args'=>'must-not-leak','description'=>'must-not-leak'],
        str_ends_with($path,'/lxc/200/config')=>['cores'=>2,'memory'=>2048,'swap'=>512,'unprivileged'=>1,'ostype'=>'debian','features'=>'nesting=1,keyctl=1,mount=nfs','rootfs'=>'local-lvm:vm-200-disk-0,size=8G','mp0'=>'/host/secret-path,mp=/data','net0'=>'name=eth0,bridge=vmbr0,hwaddr=02:00:00:00:00:02,ip=192.0.2.2/24,ip6=auto,type=veth','lxc.mount.entry'=>'must-not-leak'],
        str_ends_with($path,'/qemu/100/status/current')=>['vmid'=>$mode==='wrongid'?101:100,'status'=>'running','uptime'=>1000,'mem'=>4294967296,'maxmem'=>8589934592,'cpu'=>0.25],
        str_ends_with($path,'/lxc/200/status/current')=>['vmid'=>200,'status'=>'stopped','uptime'=>0,'mem'=>0],
        default=>throw new RuntimeException('unexpected path '.$path)
    }],JSON_THROW_ON_ERROR);
});
$runtime=new CollectionRuntimeService(CollectorRegistry::forConnectors(new ConnectorRegistry($driver)));$plan=new CollectionPlan('proxmox.guests','sync');$run=fn()=>$runtime->collect($plan,$target,'proxmox_api',$secret);
$ok(in_array('proxmox.guests',array_column($runtime->catalog(),'name'),true),'executable registration');
$r=$run();$s=$r->connection['guest_discovery'];$g=array_column($s['guests'],null,'external_id');$vm=$g['qemu:100'];$ct=$g['lxc:200'];
$ok(!$r->complete&&count($r->items)===4&&$s['details_complete'],'partial topology with full details');
$ok($vm['config']['memory']===8192&&$vm['config']['cores']===4&&$vm['config']['cpu_model']==='host','CPU RAM');
$ok($vm['config']['disks'][1]['volume_id']==='local-lvm:vm-100-disk-0'&&$vm['config']['disks'][1]['size']==='32G','disk mapping');
$ok($vm['config']['nics'][0]['tag']===20&&$vm['config']['nics'][0]['trunks']===[30,40],'VLAN and NIC');
$ok($vm['config']['bios']==='ovmf'&&$vm['config']['machine']==='q35'&&$vm['config']['boot_order']===['scsi0','net0'],'boot metadata');
$ok($vm['pool']==='prod'&&$ct['template']&&$ct['config']['unprivileged']&&$ct['config']['features']['nesting'],'pool template LXC');
$ok($ct['config']['disks'][0]['source']==='host_or_unresolved'&&$ct['config']['nics'][0]['ip']==='192.0.2.2/24','LXC mount and NIC');
$ok($vm['runtime']['maxmem']==='8589934592'&&$vm['runtime']['cpu']===0.25,'runtime numeric data');
$j=json_encode($r);foreach(['must-not-leak','fixture-secret','secret-serial','/host/secret-path','11111111-1111-4111-8111-111111111111'] as $secretPart)$ok(!str_contains($j,$secretPart),'excluded '.$secretPart);
$ok(strlen($vm['config']['identity_fingerprint'])===64,'hashed identity evidence');
$prior=ProxmoxGuestStore::merge($s,null,'2026-11-13T10:00:00Z');
$mode='denied';$next=$run()->connection['guest_discovery'];$merged=ProxmoxGuestStore::merge($next,$prior,'2026-11-13T10:01:00Z');$v=array_column($merged['guests'],null,'external_id')['qemu:100'];
$ok($v['config']===null&&$v['config_state']==='permission_denied'&&$v['runtime']!==null,'independent partial config');
$ok($v['last_config']['memory']===8192&&$v['last_config_observed_at']==='2026-11-13T10:00:00Z','last-known timestamp');
$mode='moved';$m=$run();$items=array_column($m->items,null,'external_id');$ok($items['qemu:100']['parent_external_id']==='node:pve-b','stable external ID after migration');
$ok(!ProxmoxGuestStore::identityConflict($vm,['guest_type'=>'qemu','fingerprint'=>$vm['config']['identity_fingerprint']]),'same generation');
$ok(ProxmoxGuestStore::identityConflict($vm,['guest_type'=>'qemu','fingerprint'=>str_repeat('b',64)]),'changed fingerprint conflict');
$ok(ProxmoxGuestStore::identityConflict($vm,['guest_type'=>'lxc','fingerprint'=>null]),'type reuse conflict');
$mode='rate';$r=$run();$ok(!$r->connection['guest_discovery']['details_complete'],'rate limit partial');
foreach(['auth'=>'connector_auth_failed','trust'=>'connector_trust_failed','race'=>'connector_protocol_error','wrongid'=>'connector_protocol_error','duplicate'=>'connector_protocol_error'] as $m=>$code){$mode=$m;$reject($run,$code);}
$legacy=$target;unset($legacy['proxmox_profile']);$reject(fn()=>$runtime->collect($plan,$legacy,'proxmox_api',$secret),'collection_invalid');
$reject(fn()=>ProxmoxGuestDiscovery::config((object)['net0'=>'virtio=02:00:00:00:00:01,tag=20,tag=30'],'qemu'),'connector_protocol_error');
$ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0039_proxmox_guest_discovery.sql')))===2,'migration parser');
putenv('APP_ENV=local');$app=(new \Cajeer\Servers\Kernel\Application(dirname(__DIR__)))->boot();try{$response=$app->handle(new \Cajeer\Servers\Runtime\HttpRequest('GET','/api/v1/connectors/targets/11111111-1111-4111-8111-111111111111/proxmox-guests'));$ok(in_array($response->status,[401,503],true),'anonymous denied');}finally{$app->terminate();}
echo "Proxmox guests smoke: $n PASS\n";
