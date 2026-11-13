<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ProxmoxSnapshotPolicy as P,ConnectorActionService,ConnectorContext,HttpsTransport};
$n=0;function ok(bool $v,string $s):void{global $n;if(!$v)throw new RuntimeException($s);$n++;}function reject(callable $fn):void{try{$fn();}catch(Throwable){ok(true,'rejected');return;}throw new RuntimeException('Expected rejection');}
$rows=P::rows(json_decode('[{"name":"current","parent":"before"},{"name":"before","snaptime":123,"description":"before upgrade"}]'));
$rev=$rows[0]['revision'];ok($rows[0]['name']==='before'&&$rows[1]['name']==='current','normalized tree');
foreach(P::ACTIONS as $action){$ext=str_starts_with($action,'vm.')?'qemu:101':'lxc:102';$body=['action'=>$action,'external_id'=>$ext,'snapshot'=>'before','confirm'=>"$action $ext before"];if(!str_ends_with($action,'.create'))$body['snapshot_revision']=$rev;ConnectorActionService::validate($body,'1234567890123456');ok(true,'intent');}
$body=['action'=>'vm.snapshot.rollback','external_id'=>'qemu:101','snapshot'=>'before','confirm'=>'vm.snapshot.rollback qemu:101 before','snapshot_revision'=>$rev];
foreach([['snapshot'=>'current'],['snapshot'=>'../bad'],['snapshot'=>'vzdump'],['snapshot'=>'pending'],['snapshot_revision'=>'x'],['snapshot_revision'=>null],['confirm'=>'yes'],['force'=>true],['external_id'=>'lxc:101']] as $bad)reject(fn()=>ConnectorActionService::validate(array_replace($body,$bad),'1234567890123456'));
P::check('vm.snapshot.rollback','before',$rev,$rows,'stopped');ok(true,'rollback allowed');
reject(fn()=>P::check('vm.snapshot.rollback','before',$rev,$rows,'running'));
reject(fn()=>P::check('vm.snapshot.delete','before',str_repeat('a',64),$rows,'stopped'));
reject(fn()=>P::check('vm.snapshot.create','before',null,$rows,'stopped'));
reject(fn()=>P::rows(json_decode('[{"name":"a"},{"name":"a"}]')));
$ctx=new ConnectorContext('proxmox','2001:db8::1',8006,'proxmox_api');
$url=P::consoleUrl($ctx,'pve-a','qemu',101);ok($url==='https://[2001:db8::1]:8006/?console=kvm&vmid=101&node=pve-a&novnc=1','native qemu URL');
ok(str_contains(P::consoleUrl($ctx,'pve-a','lxc',102),'console=lxc&vmid=102&node=pve-a&xtermjs=1'),'native lxc URL');
reject(fn()=>P::consoleUrl($ctx,'pve-a&evil=1','qemu',101));
reject(fn()=>(new HttpsTransport())->snapshotWrite('pve.test',8006,'/api2/json/nodes/pve/qemu/101/snapshot','delete','../bad','test'));
reject(fn()=>(new HttpsTransport())->snapshotWrite('pve.test',8006,'/api2/json/nodes/pve/qemu/101/snapshot?force=1','delete','before','test'));
echo "Proxmox snapshots policy: $n PASS\n";
