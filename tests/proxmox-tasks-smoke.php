#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ProxmoxTaskDiscovery as D,ProxmoxConnector,ConnectorTransportException};
use Cajeer\Servers\Collector\{CollectorRegistry,CollectionRuntimeService,CollectionPlan};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;
$n=0;$ok=static function(bool $v,string $m)use(&$n){if(!$v)throw new RuntimeException('FAIL '.$m);$n++;};
$now=time();$node='pve-a';$make=static function(int $i,int $start,string $status='OK',string $node='pve-a'):array{
    return ['upid'=>sprintf('UPID:%s:%08X:00000002:%08X:qmstart:100:root@pam!svc:',$node,$i,$start),'node'=>$node,'type'=>'qmstart','starttime'=>$start,'status'=>$status,...($status==='RUNNING'?[]:['endtime'=>$start+1]),'secret'=>'must-not-leak'];
};
$active=$make(999,$now-100000,'RUNNING');$arch=[];for($i=1;$i<=140;$i++)$arch[]=$make($i,$now-140+$i);$arch=array_reverse($arch);$mode='ok';$calls=[];$nodes=[['node'=>$node,'status'=>'online']];
$get=static function(string $path)use(&$mode,&$calls,&$arch,&$active,&$nodes):mixed{
    $calls[]=$path;if($path==='/api2/json/nodes')return json_decode(json_encode($nodes));
    if(str_ends_with($path,'/status')){if($mode==='missing')throw ConnectorTransportException::forStatus(400);return (object)['status'=>'stopped','exitstatus'=>'OK','upid'=>$active['upid'],'starttime'=>$active['starttime']];}
    parse_str(parse_url($path,PHP_URL_QUERY)??'',$q);if($mode==='denied')throw ConnectorTransportException::forStatus(403);if($mode==='auth')throw ConnectorTransportException::forStatus(401);if($mode==='rate')throw ConnectorTransportException::forStatus(429);
    $r=$q['source']==='active'?[$active]:$arch;if(isset($q['since']))$r=array_values(array_filter($r,fn($t)=>$t['starttime']>=(int)$q['since']&&$t['starttime']<=(int)$q['until']));
    return json_decode(json_encode(array_slice($r,(int)$q['start'],(int)$q['limit'])));
};
$run=fn($previous=[],?int $time=null)=>D::collect($get,[],$previous,$time??$now,hrtime(true)+60_000_000_000)->connection['task_discovery'];
$r=$run();$ok($r['observed_count']===101&&$r['nodes']->{$node}['archive']==='catching_up','bounded first page, split dense window');$ok($r['cursor']['nodes'][$node]['until']<$now,'time window shrunk');$ok(!str_contains(json_encode($r),'must-not-leak'),'allowlist projection');
$prev=['cursor'=>$r['cursor'],'revision'=>1,'pending'=>[['upid'=>$active['upid']]]];$r=$run($prev);$task=array_column($r['tasks'],null,'upid')[$active['upid']];$ok($task['state']==='succeeded'&&$task['ended_at']===null,'poll stopped, stale active cannot regress');$ok($r['expected_revision']===1&&$r['checked'][$active['upid']]==='available','fenced previous revision');
$late=$make(888,$now-200000);array_unshift($arch,$late);$r=$run(['cursor'=>$r['cursor'],'revision'=>2]);$ok(isset(array_column($r['tasks'],null,'upid')[$late['upid']]),'late completion captured by archive head');
$cursor=$r['cursor'];$mode='denied';$r=$run(['cursor'=>$cursor,'revision'=>3]);$ok($r['nodes']->{$node}['archive']==='permission_denied'&&$r['cursor']['nodes'][$node]===$cursor['nodes'][$node],'ACL does not advance cursor');
$mode='rate';$r=$run(['cursor'=>$cursor]);$ok($r['rate_limited']&&$r['nodes']->{$node}['archive']==='budget_exhausted','rate limited explicitly');
$mode='auth';try{$run();throw new RuntimeException('accepted 401');}catch(ConnectorTransportException $e){$ok($e->httpStatus===401,'401 fatal');}
$mode='missing';$r=$run($prev);$ok($r['checked'][$active['upid']]==='unavailable'&&array_column($r['tasks'],null,'upid')[$active['upid']]['state']==='running','missing status never implies completion');
$mode='ok';$bad=$make(2,$now-2);$bad['node']='other';try{D::row($bad,$node,false);throw new RuntimeException('accepted mismatch');}catch(ConnectorTransportException){$ok(true,'node mismatch rejected');}
$warning=$make(2,$now-2,'WARNINGS: 2');$ok(D::row($warning,$node,false)['state']==='warning','warning classification');$ok(D::row($make(3,$now-3,'password=secret'),$node,false)['state']==='failed','arbitrary exit text not persisted');
// Test actual registry -> verified client -> allowlisted GET, rather than only direct projector.
$driver=new ProxmoxConnector(static function(string $url,string $auth,?string $ca)use($get):string{
    if($auth!=='PVEAPIToken=svc@pve!read=fixture-secret')throw new RuntimeException('auth');$path=parse_url($url,PHP_URL_PATH);$q=parse_url($url,PHP_URL_QUERY);$raw=match($path){'/api2/json/version'=>(object)['version'=>'9.0.3'],'/api2/json/cluster/status'=>[(object)['type'=>'cluster','name'=>'prod','quorate'=>1],(object)['type'=>'node','name'=>'pve-a','nodeid'=>1,'local'=>1,'online'=>1]],default=>$get($path.($q?'?'.$q:''))};return json_encode(['data'=>$raw],JSON_THROW_ON_ERROR);
});
$runtime=new CollectionRuntimeService(CollectorRegistry::forConnectors(new ConnectorRegistry($driver)));$target=['provider'=>'proxmox','endpoint_host'=>'pve-a.test','endpoint_port'=>8006,'credential_type'=>'proxmox_api','purpose'=>'api','proxmox_profile'=>['cluster'=>'prod','anchor_node'=>'pve-a','anchor_id'=>1,'primary_pin'=>str_repeat('a',64),'fallbacks'=>[]]];$secret='{"token_id":"svc@pve!read","token_secret":"fixture-secret"}';
$catalog=array_column($runtime->catalog(),null,'name');$ok($catalog['proxmox.tasks']['operations']===['inspect'],'inspect only');$result=$runtime->collect(new CollectionPlan('proxmox.tasks','inspect'),$target,'proxmox_api',$secret,$prev);$ok($result->items===[]&&$result->connection['task_discovery']['observed_count']>0,'verified runtime with encoded UPID and list paths');
try{$runtime->validate(new CollectionPlan('proxmox.tasks','sync'),$target);throw new RuntimeException('sync allowed');}catch(HttpException){$ok(true,'sync rejected');}
// Head continuation across runs: an earlier anchor is beyond the first two pages.
$mode='ok';$anchor=$arch[0]['upid'];$c=['nodes'=>[$node=>['head'=>$anchor]],'next_node'=>$node];
for($i=2000;$i<2250;$i++)array_unshift($arch,$make($i,$now-1));
$seen=[];for($i=0;$i<4;$i++){$r=$run(['cursor'=>$c]);$c=$r['cursor'];foreach($r['tasks'] as $t)$seen[$t['upid']]=true;if(!isset($c['nodes'][$node]['head_scan']))break;}
$ok(!isset($c['nodes'][$node]['head_scan'])&&$c['nodes'][$node]['head']===$arch[0]['upid']&&count($seen)>=250,'head pagination resumes and reaches anchor');
$nodes=[];$r=$run();$ok($r['observed_count']===0&&(array)$r['nodes']===[],'empty visibility safe');
$ok(count((new Cajeer\Servers\Database\SqlStatementParser())->split(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0041_proxmox_task_history.sql')))===6,'migration statements');
$identity=D::identity($active['upid']);
$ok(D::status((object)['status'=>'stopped','exitstatus'=>'unknown'],$identity)['state']==='unknown','unknown is not success');
$ok(D::status((object)['status'=>'stopped','exitstatus'=>'WARNINGS: 1'],$identity)['state']==='warning','status warning');
$ok(D::status((object)['status'=>'running'],$identity)['state']==='running','running status');
foreach([(object)['status'=>'stopped','upid'=>$arch[0]['upid']],(object)['status'=>'stopped','starttime'=>$identity['started_at']+1],(object)['status'=>'stopped','endtime'=>$identity['started_at']-1],(object)['status'=>'stopped','node'=>'other'],(object)['status'=>'stopped','type'=>'qmstop']] as $bad){try{D::status($bad,$identity);throw new RuntimeException('accepted wrong task evidence');}catch(ConnectorTransportException){$ok(true,'invalid task evidence rejected');}}
$ok(D::mergeState('unknown','running')==='unknown'&&D::mergeState('unknown','succeeded')==='succeeded','recover unknown without stale regression');
$ok(D::mergeState('succeeded','failed')==='succeeded'&&D::mergeState('failed','running')==='failed'&&D::mergeState('warning','unknown')==='warning','confirmed terminal evidence immutable');
$tilde=D::identity(str_replace('root@pam!svc','root@pam!svc~test',$active['upid']));$ok(str_contains(D::statusPath($tilde),'%7E')&&!str_contains(D::statusPath($tilde),'~'),'opaque UPID tilde encoded');
$expected=['vm.start'=>'qmstart','vm.shutdown'=>'qmshutdown','vm.stop'=>'qmstop','vm.reboot'=>'qmreboot','lxc.start'=>'vzstart','lxc.shutdown'=>'vzshutdown','lxc.stop'=>'vzstop','lxc.reboot'=>'vzreboot','vm.snapshot.create'=>'qmsnapshot','vm.snapshot.delete'=>'qmdelsnapshot','vm.snapshot.rollback'=>'qmrollback','lxc.snapshot.create'=>'vzsnapshot','lxc.snapshot.delete'=>'vzdelsnapshot','lxc.snapshot.rollback'=>'vzrollback'];
$workers=Cajeer\Servers\Connector\ProxmoxSnapshotPolicy::taskWorkers();$ok(count($workers)===14&&$workers==$expected,'all fourteen action workers match');
echo "Proxmox task history: $n PASS\n";
