#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Collector\{CollectionRuntimeService,CollectorRegistry,CollectionPlan};
use Cajeer\Servers\Connector\{ProxmoxConnector,ConnectorTransportException};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;
$n=0;$ok=static function(bool $v,string $m)use(&$n):void{if(!$v)throw new RuntimeException('FAIL '.$m);$n++;};
$mode='ok';$lists=0;$calls=[];
$driver=new ProxmoxConnector(static function(string $url,string $auth,?string $ca)use(&$mode,&$lists,&$calls):string{
    $p=parse_url($url,PHP_URL_PATH);$calls[]=$url;
    if($auth!=='PVEAPIToken=svc@pve!read=fixture-secret')throw new RuntimeException('auth');
    if(str_ends_with($p,'/network')){if($mode==='denied')throw ConnectorTransportException::forStatus(403);if($mode==='auth')throw ConnectorTransportException::forStatus(401);if($mode==='rate')throw ConnectorTransportException::forStatus(429);}
    $rows=[['type'=>'qemu','vmid'=>100,'node'=>'pve-a','status'=>'running'],['type'=>'lxc','vmid'=>200,'node'=>'pve-b','status'=>'stopped']];
    if($p==='/api2/json/cluster/resources'){++$lists;if($mode==='race'&&$lists%2===0)$rows[0]['node']='pve-b';}
    $data=match(true){
      $p==='/api2/json/version'=>['version'=>$mode==='pve8'?'8.4.1':'9.0.3'],
      $p==='/api2/json/cluster/status'=>[['type'=>'cluster','name'=>'prod','quorate'=>1],['type'=>'node','name'=>'pve-a','nodeid'=>1,'local'=>1,'online'=>1],['type'=>'node','name'=>'pve-b','nodeid'=>2,'local'=>0,'online'=>1]],
      $p==='/api2/json/nodes'=>[['node'=>'pve-a','status'=>'online'],['node'=>'pve-b','status'=>$mode==='offline'?'offline':'online']],
      $p==='/api2/json/cluster/resources'=>$rows,
      $p==='/api2/json/storage'=>[['storage'=>'local','type'=>'dir','content'=>'images,iso','path'=>'/secret/path'],['storage'=>'pbs','type'=>'pbs','shared'=>1,'password'=>'must-not-leak']],
      str_ends_with($p,'/storage')=>[['storage'=>'local','type'=>'dir','shared'=>0,'active'=>1,'enabled'=>1,'total'=>'9007199254740993','used'=>100,'avail'=>200,'content'=>'images,iso'],['storage'=>'pbs','type'=>'pbs','shared'=>1,'active'=>1,'enabled'=>1]],
      str_ends_with($p,'/storage/local/content')=>[['volid'=>$mode==='bad'?'other:vm-100-disk-0':'local:vm-100-disk-0','content'=>'images','format'=>'raw','size'=>34359738368,'vmid'=>100,'notes'=>'must-not-leak']],
      str_ends_with($p,'/storage/pbs/content')=>[['volid'=>'pbs:backup/vm/100/2026-11-13T10:00:00Z','content'=>'backup','size'=>1234]],
      str_ends_with($p,'/network')=>[['iface'=>'vmbr0','type'=>'bridge','bridge_ports'=>'bond0','bridge_vlan_aware'=>1,'bridge_vids'=>'2 4 100-200','cidr'=>'192.0.2.1/24','gateway'=>'192.0.2.254','options'=>['must-not-leak']],['iface'=>'bond0','type'=>'bond','slaves'=>'eno1 eno2','bond_mode'=>'802.3ad'],['iface'=>'eno1','type'=>'eth'],['iface'=>'eno2','type'=>'eth'],['iface'=>'vlan20','type'=>'vlan','vlan-raw-device'=>'bond0','vlan-id'=>20,'address6'=>'2001:db8::1','netmask6'=>64],['iface'=>'vmbr1','type'=>'bridge','bridge_ports'=>'none']],
      str_ends_with($p,'/qemu/100/config')=>['scsi0'=>'local:vm-100-disk-0,size=32G','net0'=>'virtio=02:00:00:00:00:01,bridge=vmbr0,tag=20','cipassword'=>'must-not-leak'],
      str_ends_with($p,'/lxc/200/config')=>['rootfs'=>'local:missing,size=8G','mp0'=>'/host/secret-path,mp=/data','net0'=>'name=eth0,bridge=missing,ip=192.0.2.2/24'],
      default=>throw new RuntimeException('Unexpected '.$p)};
    return json_encode(['data'=>$data],JSON_THROW_ON_ERROR);
});
$target=['provider'=>'proxmox','endpoint_host'=>'pve-a.test','endpoint_port'=>8006,'credential_type'=>'proxmox_api','purpose'=>'api','proxmox_profile'=>['cluster'=>'prod','anchor_node'=>'pve-a','anchor_id'=>1,'primary_pin'=>str_repeat('a',64),'fallbacks'=>[]]];
$secret='{"token_id":"svc@pve!read","token_secret":"fixture-secret"}';$runtime=new CollectionRuntimeService(CollectorRegistry::forConnectors(new ConnectorRegistry($driver)));$plan=new CollectionPlan('proxmox.storage-network','sync');$run=fn()=>$runtime->collect($plan,$target,'proxmox_api',$secret);
$ok(in_array('proxmox.storage-network',array_column($runtime->catalog(),'name'),true),'registered collector');
$r=$run();$s=$r->connection['storage_network_discovery'];$a=$s['nodes'][0];$b=$s['nodes'][1];$st=array_column($a['storages'],null,'storage');$net=array_column($a['interfaces'],null,'iface');$g=array_column($s['guests'],null,'guest_id');
$ok(!$r->complete&&$s['details_complete']&&count($r->items)===4,'full visible details, never authoritative absence');
$ok($st['local']['total']==='9007199254740993'&&$st['local']['volumes'][0]['size']==='34359738368','exact capacity');
$ok($st['local']['storage_key']!==array_column($b['storages'],null,'storage')['local']['storage_key'],'local storage identity');
$ok($st['pbs']['storage_key']===array_column($b['storages'],null,'storage')['pbs']['storage_key'],'shared storage identity');
$ok($st['pbs']['volumes'][0]['volume_id']==='pbs:backup/vm/100/2026-11-13T10:00:00Z','PBS timestamp volume');
$ok($net['vmbr0']['links'][0]['resolved']&&$net['vmbr0']['links'][0]['iface']==='bond0'&&count($net['bond0']['links'])===2,'bridge to bond to NIC');
$ok($net['vlan20']['vlan_id']===20&&$net['vlan20']['netmask6']===64&&$net['vlan20']['links'][0]['iface']==='bond0','actual PVE VLAN keys');
$ok($net['vmbr1']['links']===[],'none is not a physical port');
$ok($g['qemu:100']['disks'][0]['volume_resolved']&&$g['qemu:100']['nics'][0]['bridge_resolved'],'guest links resolved');
$ok(!$g['lxc:200']['disks'][1]['volume_resolved']&&!$g['lxc:200']['nics'][0]['bridge_resolved'],'unresolved links');
$ok(!str_contains(json_encode($r),'must-not-leak')&&!str_contains(json_encode($r),'secret-path')&&!str_contains(json_encode($r),'/secret/path'),'safe projection');
$mode='pve8';$calls=[];$s=$run()->connection['storage_network_discovery'];$ok($s['details_complete']&&count(array_filter($calls,fn($url)=>str_contains($url,'?type=any_bridge')))===2&&!array_filter($calls,fn($url)=>str_contains($url,'?type=include_sdn')),'PVE 8 compatibility');
$mode='denied';$s=$run()->connection['storage_network_discovery'];$ok(!$s['details_complete']&&$s['nodes'][0]['network_state']==='permission_denied'&&$s['nodes'][0]['storage_state']==='available','independent ACL partial');
$mode='rate';$s=$run()->connection['storage_network_discovery'];$ok(!$s['details_complete']&&$s['nodes'][0]['network_state']==='rate_limited','rate limited partial');
$mode='offline';$s=$run()->connection['storage_network_discovery'];$ok($s['nodes'][1]['storage_state']==='offline'&&$s['guests'][1]['state']==='offline','offline node skipped');
foreach(['auth'=>'connector_auth_failed','race'=>'connector_protocol_error','bad'=>'connector_protocol_error'] as $m=>$code){$mode=$m;try{$run();throw new LogicException('expected failure');}catch(HttpException $e){$ok($e->errorCode===$code,$m.' '.$e->errorCode);}}
$ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0040_proxmox_storage_network_discovery.sql')))===1,'migration parsed');
putenv('APP_ENV=local');$app=(new \Cajeer\Servers\Kernel\Application(dirname(__DIR__)))->boot();try{$response=$app->handle(new \Cajeer\Servers\Runtime\HttpRequest('GET','/api/v1/connectors/targets/11111111-1111-4111-8111-111111111111/proxmox-storage-network'));$ok(in_array($response->status,[401,503],true),'anonymous denied');}finally{$app->terminate();}
echo "Proxmox storage/network smoke: $n PASS\n";
