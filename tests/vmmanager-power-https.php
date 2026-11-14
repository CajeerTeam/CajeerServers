<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorActionService,ConnectorActionNotSentException,EndpointPolicy,HttpsTransport,VmmanagerConnector,VmmanagerPowerPolicy};
use Cajeer\Servers\Integration\ConnectorRegistry;
$n=0;$ok=static function(bool $v,string $s)use(&$n):void{if(!$v)throw new RuntimeException($s);$n++;};
$reject=static function(callable $fn,string $label,?string $class=null)use($ok):void{try{$fn();}catch(Throwable $e){$ok($class===null||$e instanceof $class,$label.' wrong error '.get_class($e));return;}throw new RuntimeException('Expected rejection: '.$label);};
$transport=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn()=>['127.0.0.1']),3,65536);
$driver=new VmmanagerConnector($transport,$argv[2]);$profile=['primary_pin'=>openssl_x509_fingerprint(file_get_contents($argv[2]),'sha256'),'anchor_id'=>1,'anchor_node'=>'node-1'];
$ctx=new ConnectorContext('vmmanager','vmmanager.test',(int)$argv[1],'api_token',null,$profile);
$ok((new ConnectorRegistry($driver))->actions('vmmanager')===VmmanagerPowerPolicy::ACTIONS,'registered executable actions');
foreach([21,22] as $id){
    $token='fixture-power-'.$id;$external='host:'.$id;
    foreach(['start','reboot','stop'] as $op){
        $action='vmmanager.host.'.$op;ConnectorActionService::validate(['action'=>$action,'external_id'=>$external,'confirm'=>$action.' '.$external],'fixture-key-123456');
        $r=$driver->prepareAction($ctx,$action,$external,$token);$ok($r['guest']['virtualization_type']===($id===21?'kvm':'lxd'),'guest type');
        $task=$driver->dispatchAction($ctx,$action,$r,$token);$receipt=VmmanagerPowerPolicy::parse($task);$ok(count($receipt['tasks'])===($op==='reboot'?2:1),'correct Consul receipt');
        $ok($driver->actionStatus($ctx,$r['node'],$task,$token)==='submitted','running is not success');
        $ok($driver->actionStatus($ctx,$r['node'],$task,$token)==='succeeded','terminal task plus live state');
    }
}
foreach(['acl','scoped','maintenance','ha','rescue','locked','pending','unknown-pending','active-short'] as $mode)$reject(fn()=>$driver->prepareAction($ctx,'vmmanager.host.start','host:21','fixture-power-'.$mode),$mode);
$reject(fn()=>$driver->prepareAction($ctx,'vmmanager.host.stop','host:21','fixture-power-wrong-state'),'wrong power state');
$reject(fn()=>$driver->prepareAction($ctx,'vmmanager.host.start','host:21/../../','fixture-power-invalid'),'path injection');
$reject(fn()=>$driver->prepareAction(new ConnectorContext('vmmanager','vmmanager.test',(int)$argv[1],'api_token'),'vmmanager.host.start','host:21','fixture-power-no-pin'),'missing pin');
$bad=$profile;$bad['primary_pin']=str_repeat('0',64);
$reject(fn()=>$driver->prepareAction(new ConnectorContext('vmmanager','vmmanager.test',(int)$argv[1],'api_token',null,$bad),'vmmanager.host.start','host:21','fixture-power-wrong-pin'),'wrong pin');
$token='fixture-power-drift';$r=$driver->prepareAction($ctx,'vmmanager.host.start','host:21',$token);$reject(fn()=>$driver->dispatchAction($ctx,'vmmanager.host.start',$r,$token),'guest changed before POST',ConnectorActionNotSentException::class);
foreach(['lost','bad-receipt'] as $mode){$token='fixture-power-'.$mode;$r=$driver->prepareAction($ctx,'vmmanager.host.start','host:21',$token);$reject(fn()=>$driver->dispatchAction($ctx,'vmmanager.host.start',$r,$token),$mode);}
foreach(['failed','bad-task','missing-task','state-mismatch'] as $mode){
    $token='fixture-power-'.$mode;$r=$driver->prepareAction($ctx,'vmmanager.host.start','host:21',$token);$task=$driver->dispatchAction($ctx,'vmmanager.host.start',$r,$token);
    if($mode==='failed'){$ok($driver->actionStatus($ctx,$r['node'],$task,$token)==='submitted','failed pending');$ok($driver->actionStatus($ctx,$r['node'],$task,$token)==='failed','remote task failure');}
    elseif($mode==='state-mismatch'){$ok($driver->actionStatus($ctx,$r['node'],$task,$token)==='submitted','mismatch pending');$reject(fn()=>$driver->actionStatus($ctx,$r['node'],$task,$token),'terminal but wrong live state');}
    else $reject(fn()=>$driver->actionStatus($ctx,$r['node'],$task,$token),$mode);
}
$token='fixture-power-missing-second';$r=$driver->prepareAction($ctx,'vmmanager.host.start','host:21',$token);$task=$driver->dispatchAction($ctx,'vmmanager.host.start',$r,$token);$driver->actionStatus($ctx,$r['node'],$task,$token);$driver->actionStatus($ctx,$r['node'],$task,$token);
$r=$driver->prepareAction($ctx,'vmmanager.host.reboot','host:21',$token);$reject(fn()=>$driver->dispatchAction($ctx,'vmmanager.host.reboot',$r,$token),'missing start task');
$token='fixture-power-worker';$r=$driver->prepareAction($ctx,'vmmanager.host.start','host:21',$token);$task=$driver->dispatchAction($ctx,'vmmanager.host.start',$r,$token);
$prev=['actions'=>[['id'=>'action-fixture','node'=>$r['node'],'remote_task'=>$task]]];$first=$driver->tasks($ctx,$token,$prev)->connection['vmmanager_task_discovery'];$ok($first['action_checks'][0]['status']==='submitted','worker pending');
$prev+=['revision'=>1,'cursor'=>$first['cursor']];$next=$driver->tasks($ctx,$token,$prev)->connection['vmmanager_task_discovery'];$ok($next['action_checks'][0]['status']==='succeeded','worker settles from actual task and state');
echo "VMmanager power HTTPS integration: $n PASS\n";
