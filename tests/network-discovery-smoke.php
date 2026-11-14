#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Inventory\{NetworkObservation as O,NetworkIpamReconciler as R,NetworkDiscoveryService};
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Http\Router;
$n=0;function ok(bool $v,string $msg):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$msg);$n++;}
function target(string $p,string $id):array{return ['id'=>$id,'provider'=>$p,'name'=>$p];}
function project(string $p,array $s,string $id='t'):array{return O::project(target($p,$id),$s,'2026-11-14T12:00:00Z');}
ok(O::prefix('192.0.2.123',24)==='192.0.2.0/24','IPv4 host bits masked');
ok(O::prefix('2001:db8:1:2::1234',64)==='2001:db8:1:2::/64','IPv6 host bits masked');
ok(O::prefix('2001:db8::123',127)==='2001:db8::122/127','partial byte prefix');
ok(O::prefix('192.0.2.1',33)===null,'invalid prefix');
ok(O::prefix('not-ip',24)===null,'invalid IP');
ok(O::mac('AA-BB-CC-DD-EE-FF')==='aa:bb:cc:dd:ee:ff','Windows MAC normalized');
ok(O::mac('00:00:00:00:00:00')===null,'zero MAC rejected');
foreach(['127.0.0.1','169.254.1.2','0.0.0.0','224.0.0.1','::','::1','fe80::1','ff02::1','::ffff:192.0.2.1'] as $ip)ok(!R::assignable($ip),'non-assignable '.$ip);
foreach(['10.1.2.3','192.168.1.1','2001:db8::1','fd00::1'] as $ip)ok(R::assignable($ip),'assignable '.$ip);
$linux=['complete'=>true,'sections'=>['interfaces'=>[['name'=>'eth0','mac'=>'02:00:00:00:00:01','master'=>'br0'],['name'=>'br0','mac'=>'02:00:00:00:00:02','master'=>null]],'addresses'=>[['interface'=>'eth0','address'=>'10.1.2.3','prefix'=>'24'],['interface'=>'br0','address'=>'2001:db8::12','prefix'=>'64'],['interface'=>'missing','address'=>'10.1.3.4','prefix'=>'24']]]];
$l=project('ssh',$linux);ok(count($l['interfaces'])===2&&count($l['links'])===1,'Linux master link');ok(count($l['networks'])===3&&$l['addresses'][2]['issue']==='unresolved_interface','unresolved address retained');ok($l===project('ssh',$linux),'deterministic IDs');ok($l['networks'][0]['id']!==project('ssh',$linux,'other')['networks'][0]['id'],'target scoped subnet');
$w=project('winrm',['complete'=>true,'sections'=>['interfaces'=>[['index'=>'2','name'=>'Ethernet','mac'=>'AA-BB-CC-DD-EE-FF']],'addresses'=>[['index'=>'2','address'=>'192.0.2.4','prefix'=>'24','state'=>'Preferred'],['index'=>'2','address'=>'192.0.2.5','prefix'=>'24','state'=>'Tentative']]]]);
ok($w['addresses'][0]['interface_id']===$w['interfaces'][0]['id']&&$w['addresses'][1]['issue']==='inactive_address','Windows index and address state');
$r=project('routeros',['complete'=>true,'sections'=>['interfaces'=>[['.id'=>'*1','name'=>'ether1','mac-address'=>'02:00:00:00:00:01'],['.id'=>'*2','name'=>'bridge','mac-address'=>'02:00:00:00:00:02'],['.id'=>'*3','name'=>'vlan10','mac-address'=>'02:00:00:00:00:03']], 'ipv4'=>[['interface-id'=>'*3','address'=>'10.2.3.4/24','disabled'=>'false'],['interface-id'=>'*1','address'=>'10.2.4.1/24','disabled'=>'true']], 'bridge_ports'=>[['interface-id'=>'*1','bridge-id'=>'*2']], 'vlans'=>[['name'=>'vlan10','interface-id'=>'*2','vlan-id'=>'10']], 'neighbors'=>[['interface-id'=>'*1','identity'=>'neighbor','mac-address'=>'02:00:00:00:00:04','address'=>'10.1.2.4']]]]);
ok(count($r['links'])===2&&$r['networks'][0]['vlan_id']===10,'RouterOS bridge and VLAN parent');ok($r['addresses'][1]['issue']==='inactive_address','disabled address not importable');ok($r['neighbors'][0]['evidence']==='routeros_neighbor','neighbor is not physical proof');
function snmp(string $id,string $local,string $remote,string $port='eth1'):array{return project('snmp',['complete'=>false,'sections'=>['interfaces'=>[['if_index'=>'42','name'=>'eth1','mac'=>$local]],'lldp_local'=>[['chassis_subtype'=>'4','chassis_id'=>$local]],'lldp_ports'=>[['if_index'=>'42','port_id'=>'eth1','port_subtype'=>'5']], 'neighbors'=>[['if_index'=>'42','chassis_id'=>$remote,'chassis_subtype'=>'4','port_id'=>$port,'port_subtype'=>'5','system_name'=>'same-name']], 'addresses'=>[['if_index'=>'42','address'=>'192.0.2.7']]]],$id);}
$a=snmp('a','02:00:00:00:00:01','02:00:00:00:00:02');$b=snmp('b','02:00:00:00:00:02','02:00:00:00:00:01');
ok(count(O::physicalLinks([$a,$b]))===1,'reciprocal LLDP exact chassis/port');ok(O::physicalLinks([$a])===[],'one-sided LLDP is not physical proof');ok(O::physicalLinks([$a,snmp('b','02:00:00:00:00:02','02:00:00:00:00:01','other')])===[],'mismatched port not connected');ok(O::physicalLinks([$a,$b,snmp('c','02:00:00:00:00:02','02:00:00:00:00:01')])===[],'duplicate chassis ambiguous');ok($a['addresses'][0]['network_id']===null,'SNMP IP without prefix does not invent subnet');
$p=project('proxmox',['details_complete'=>false,'nodes'=>[['node'=>'n1','interfaces'=>[['iface'=>'vmbr0','links'=>[['iface'=>'eth0','kind'=>'bridge_ports']],'cidr'=>'192.0.2.1/24'],['iface'=>'eth0','links'=>[]]]]],'guests'=>[['guest_id'=>'qemu:101','node'=>'n1','nics'=>[['device'=>'net0','bridge'=>'vmbr0','mac'=>'02:00:00:00:00:11']]]]]);
ok(count($p['devices'])===2&&count($p['links'])===2&&$p['links'][1]['kind']==='guest_bridge','PVE guest bridge topology');
$v=project('vmmanager',['complete'=>true,'networks'=>[['id'=>1,'cidr'=>'10.1.0.0/16','vlan_id'=>null,'userspace'=>1],['id'=>2,'cidr'=>'10.1.0.0/16','vlan_id'=>null,'userspace'=>2]],'interfaces'=>[['key'=>'host:1:nic:1','external_id'=>'host:1','name'=>'eth0','mac'=>'02:00:00:00:00:11']],'addresses'=>[['id'=>1,'address'=>'10.1.0.1','nic_key'=>'host:1:nic:1','network_id'=>1,'issue'=>null],['id'=>2,'address'=>'10.1.0.1','nic_key'=>null,'network_id'=>2,'issue'=>'duplicate_address']]]);
ok($v['networks'][0]['id']!==$v['networks'][1]['id']&&$v['networks'][0]['scope']!==$v['networks'][1]['scope'],'VMmanager userspace not merged');ok($v['addresses'][1]['issue']==='duplicate_address','VMmanager issue retained');
ok(R::networkOrBroadcast('192.0.2.255','192.0.2.0/24'),'IPv4 broadcast excluded');
ok(R::networkOrBroadcast('192.0.2.0','192.0.2.0/24'),'IPv4 network excluded');
ok(!R::networkOrBroadcast('192.0.2.0','192.0.2.0/31'),'RFC3021 /31 remains assignable');
ok(!R::networkOrBroadcast('2001:db8::','2001:db8::/64'),'IPv6 has no broadcast');
$vrf=project('ssh',['complete'=>true,'sections'=>['interfaces'=>[['name'=>'eth0','mac'=>'02:00:00:00:00:01'],['name'=>'eth1','mac'=>'02:00:00:00:00:02']], 'addresses'=>[['interface'=>'eth0','address'=>'10.1.2.3','prefix'=>'24'],['interface'=>'eth1','address'=>'10.1.2.4','prefix'=>'24']]]]);
ok(count($vrf['networks'])===2&&$vrf['networks'][0]['scope']!==$vrf['networks'][1]['scope'],'equal prefixes on different interfaces are not merged');
$nested=project('ssh',['complete'=>true,'sections'=>['interfaces'=>[['name'=>'eth0','mac'=>'02:00:00:00:00:01']], 'addresses'=>[['interface'=>'eth0','address'=>'10.1.2.3','prefix'=>'16'],['interface'=>'eth0','address'=>'10.1.2.4','prefix'=>'24']]]],'nested');
ok(in_array('overlapping_prefix',array_column(O::conflicts([$l,$nested])['items'],'code'),true),'nested CIDR diagnostic');
ok(in_array('multiple_observations',array_column(O::conflicts([$l,$nested])['items'],'code'),true),'duplicate cross-source diagnostic');
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');$app=(new Application(dirname(__DIR__)))->boot();$router=$app->container()->get(Router::class);ok($app->container()->get(NetworkDiscoveryService::class) instanceof NetworkDiscoveryService,'DI registration');
foreach(['GET'=>'/api/v1/inventory/network-discovery','POST'=>'/api/v1/inventory/network-discovery/reconcile'] as $method=>$path)ok(count(array_filter($router->routes(),fn($r)=>$r['path']===$path&&in_array($method,$r['methods'],true)))===1,'route '.$method);
$id='00000000-0000-4000-8000-000000000001';
$res=$app->handle(new HttpRequest('GET','/api/v1/inventory/network-discovery',[],'',['workspace_id'=>$id]));ok(in_array($res->status,[401,503],true),'anonymous topology denied');
$res=$app->handle(new HttpRequest('POST','/api/v1/inventory/network-discovery/reconcile',['Content-Type'=>'application/json'],json_encode(['workspace_id'=>$id,'target_id'=>$id,'asset_id'=>$id,'observed_at'=>'2026-11-14T12:00:00Z','snapshot'=>[]])));ok($res->status===422,'client supplied snapshot rejected');
$app->terminate();echo "$n PASS\n";
