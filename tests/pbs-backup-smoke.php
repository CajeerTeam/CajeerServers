<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{PbsDiscovery,PbsConnector,HttpsTransport,ConnectorTransportException};
use Cajeer\Servers\Collector\CollectorRegistry;
use Cajeer\Servers\Integration\ConnectorRegistry;
$n=0;function ok(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);$n++;}
function reject(callable $fn,string $category):void{try{$fn();}catch(ConnectorTransportException $e){ok($e->category===$category,'wrong error');return;}throw new RuntimeException('expected error');}
$rows=static fn(array $a):array=>array_map(static fn($r)=>(object)$r,$a);
$stores=$rows([['store'=>'backup']]);$paths=[];
$get=static function(string $path)use(&$paths,$rows):mixed{$paths[]=$path;return match($path){
 '/admin/sync'=>$rows([['id'=>'sync1','store'=>'backup','remote'=>'other','remote-store'=>'source','last-run-state'=>'OK']]),
 '/admin/verify'=>$rows([['id'=>'verify1','store'=>'backup','ns'=>'prod/eu','last-run-state'=>'WARNINGS: 1']]),
 '/admin/prune'=>$rows([['id'=>'prune1','store'=>'backup','keep-last'=>5,'disable'=>true]]),
 '/admin/datastore/backup/status'=>(object)['total'=>'18446744073709551615','used'=>100,'avail'=>200],
 '/admin/datastore/backup/gc'=>(object)['store'=>'backup','schedule'=>'daily','upid'=>'UPID:pbs:1','removed-bytes'=>50,'last-run-state'=>'OK'],
 '/admin/datastore/backup/namespace?max-depth=7'=>$rows([['ns'=>''],['ns'=>'prod/eu']]),
 '/admin/datastore/backup/groups','/admin/datastore/backup/groups?ns=prod%2Feu'=>$rows([['backup-type'=>'vm','backup-id'=>'101','last-backup'=>1700000000,'backup-count'=>1]]),
 '/admin/datastore/backup/snapshots','/admin/datastore/backup/snapshots?ns=prod%2Feu'=>$rows([['backup-type'=>'vm','backup-id'=>'101','backup-time'=>1700000000,'size'=>'9007199254740993','protected'=>true,'verification'=>(object)['state'=>'ok'],'comment'=>'secret fixture','files'=>[['filename'=>'sensitive']]]]),
 default=>throw new LogicException('Unexpected '.$path),};};
$s=PbsDiscovery::collect($get,$stores,'4.1.1');
ok($s['complete']&&$s['scope']==='token_visible','complete visible scope');ok($s['counts']===['datastores'=>1,'namespaces'=>2,'groups'=>2,'snapshots'=>2,'jobs'=>4],'counts');
ok($s['datastores'][0]['total']==='18446744073709551615','uint64 preserved');ok($s['snapshots'][0]['size']==='9007199254740993','snapshot precision');
ok($s['snapshots'][1]['namespace']==='prod/eu'&&$s['snapshots'][0]['namespace']==='','namespace identity');
ok($s['jobs'][0]['last_outcome']==='ok'&&$s['jobs'][1]['last_outcome']==='warning'&&$s['jobs'][2]['disabled']===true&&$s['jobs'][3]['gc_removed_bytes']==='50','job status');
ok(!str_contains(json_encode($s),'secret fixture')&&!str_contains(json_encode($s),'sensitive'),'no raw notes/files');
ok(in_array('/admin/datastore/backup/snapshots?ns=prod%2Feu',$paths,true),'encoded path');
$denied=PbsDiscovery::collect(static function($p)use($get){if(str_contains($p,'/groups'))throw ConnectorTransportException::forStatus(403);return $get($p);},$stores,'3.4.1');
ok(!$denied['complete']&&count($denied['issues'])===2&&$denied['counts']['snapshots']===2,'partial ACL retains accessible data');
reject(fn()=>PbsDiscovery::collect(static fn($p)=>throw ConnectorTransportException::forStatus(401),$stores,'4.0'),'connector_auth_failed');
reject(fn()=>PbsDiscovery::collect(static fn($p)=>null,$stores,'4.0'),'connector_protocol_error');
reject(fn()=>PbsDiscovery::collect($get,$rows([['store'=>'../bad']]),'4.0'),'connector_protocol_error');
reject(fn()=>PbsDiscovery::collect($get,$rows([['store'=>'backup'],['store'=>'backup']]),'4.0'),'connector_protocol_error');
reject(fn()=>PbsDiscovery::collect(static function($p)use($get,$rows){return str_contains($p,'/snapshots')?$rows([['backup-type'=>'vm','backup-id'=>'1','backup-time'=>1],['backup-type'=>'vm','backup-id'=>'1','backup-time'=>1]]):$get($p);},$stores,'4.0'),'connector_protocol_error');
$big=PbsDiscovery::collect(static function($p)use($get,$rows){if($p==='/admin/sync')return $rows(array_map(fn($i)=>['id'=>'job-'.$i,'store'=>'backup'],range(1,3000)));return $get($p);},$stores,'4.0');
ok(!$big['complete']&&$big['counts']['jobs']<=2500,'bounded result');
ok($big['counts']['jobs']===131&&$big['counts']['snapshots']===2&&$big['datastores'][0]['capacity_visible'],'job cap preserves backup and capacity discovery');
$many=PbsDiscovery::collect(static function($p)use($get,$rows){
    if($p==='/admin/datastore/second/status')return (object)['total'=>100,'used'=>1,'avail'=>99];
    if($p==='/admin/datastore/second/gc')return (object)['store'=>'second'];
    if(str_contains($p,'/snapshots'))return $rows(array_map(fn($i)=>['backup-type'=>'vm','backup-id'=>(string)$i,'backup-time'=>1700000000],range(1,3000)));
    return $get($p);
},$rows([['store'=>'backup'],['store'=>'second']]),'4.0');
ok(!$many['complete']&&$many['counts']['datastores']===2&&$many['datastores'][1]['capacity_visible']&&$many['datastores'][1]['available']==='99','large first backup namespace preserves second datastore capacity');

ok(PbsConnector::authorization('svc@pbs!read:fixture-secret')==='PBSAPIToken=svc@pbs!read:fixture-secret','PBS auth');
foreach(['svc@pbs!read=secret','svc@pbs!read:bad secret',"svc@pbs!read:bad\r\nheader",'root@pam:secret'] as $v)reject(fn()=>PbsConnector::authorization($v),'connector_auth_failed');
$r=CollectorRegistry::forConnectors(new ConnectorRegistry(new PbsConnector(new HttpsTransport())));ok($r->catalog()[0]['name']==='pbs.backups'&&$r->catalog()[0]['operations']===['inspect'],'registered inspect-only');
try{$r->require('pbs.backups','pbs','sync');throw new LogicException('sync allowed');}catch(\Cajeer\Servers\Http\Exception\HttpException $e){ok($e->status===422,'sync rejected');}
$parser=new \Cajeer\Servers\Database\SqlStatementParser();$sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0044_pbs_backup_integration.sql');ok(count($parser->split($sql))===5,'migration statements');
echo "PBS backup discovery: $n PASS\n";
