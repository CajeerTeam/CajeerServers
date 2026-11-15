#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Execute exclusively on a disposable PostgreSQL database named cajeer_test_*. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Queue\PostgresQueueDriver;
use Cajeer\Servers\Queue\QueuedJob;
use Cajeer\Servers\Queue\JobProcessor;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;
use Cajeer\Servers\Scheduler\Scheduler;
use Cajeer\Servers\Storage\LocalDisk;

$dbname=getenv('CAJEER_PG_TEST_DATABASE')?:'';
if(getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',$dbname)) {
 fwrite(STDERR,"SKIP: set CAJEER_PG_TEST=1 and CAJEER_PG_TEST_DATABASE=cajeer_test_* .\n");exit(77);
}
if(!extension_loaded('pdo_pgsql')){fwrite(STDERR,"SKIP: ext-pdo_pgsql unavailable.\n");exit(77);}
$env=[
 'DB_CONNECTION'=>'pgsql','DB_HOST'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1',
 'DB_PORT'=>getenv('CAJEER_PG_TEST_PORT')?:'5432','DB_DATABASE'=>$dbname,
 'DB_USERNAME'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer',
 'DB_PASSWORD'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'',
 'DB_SSLMODE'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer',
];
$old=[];foreach($env as $k=>$v){$old[$k]=getenv($k);putenv($k.'='.$v);}
$root=dirname(__DIR__);$config=new ConfigRepository($root.'/config');
$db=new DatabaseManager($config,new ConnectionFactory());$ids=[];$taskNames=[];$checks=0;
$ok=static function(bool $value,string $label)use(&$checks):void{if(!$value)throw new RuntimeException('FAIL: '.$label);$checks++;};
$expect=static function(callable $callback,string $label)use($ok):void{try{$callback();}catch(Throwable){$ok(true,$label);return;}throw new RuntimeException('Expected error: '.$label);};
try {
 $pdo=$db->connection();
 $ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+');
 (new MigrationRunner($db,$root.'/migrations/pgsql'))->run();
 $ok((string)$pdo->query("SELECT to_regclass('queue_jobs')")->fetchColumn()==='queue_jobs','queue schema applied');
 $queue=new PostgresQueueDriver($db);
 $other=new PostgresQueueDriver(new DatabaseManager($config,new ConnectionFactory()));
 $job=QueuedJob::create('runtime.diagnostics',['test'=>true],'infra_test',2);$ids[]=$job->id;
 $queue->push($job);
 $ok($queue->push($job)===$job->id,'idempotent PostgreSQL push');
 $r=$queue->reserve('infra_test',15);
 $ok($r!==null&&$r->attempt===1,'SKIP LOCKED reserve');
 $ok($queue->reserve('infra_test',15)===null,'leased job unavailable');
 $queue->renew($r,60);
 $ok($other->reserve('infra_test',15)===null,'renewal protects against second connection');
 $queue->release($r,'first failure',0);
 $r2=$queue->reserve('infra_test',15);
 $ok($r2!==null&&$r2->attempt===2,'retry');
 $expect(fn()=>$queue->succeed($r,[]),'stale receipt denied');
 $expect(fn()=>$queue->renew($r,60),'stale receipt cannot renew');
 $queue->release($r2,'terminal failure',0);
 $ok($queue->find($job->id)['status']==='failed','failure persisted');
 $ok($queue->retry($job->id),'manual failed retry');
 $r3=$queue->reserve('infra_test',15);
 $queue->succeed($r3,['ok'=>true]);
 $ok($queue->find($job->id)['result']['ok']===true,'success persisted');
 $ordered=QueuedJob::create('runtime.diagnostics',['z'=>1,'a'=>['y'=>2,'x'=>3]],'infra_test',1);$ids[]=$ordered->id;
 $queue->push($ordered);
 $ok($queue->push(new QueuedJob($ordered->id,$ordered->type,['a'=>['x'=>3,'y'=>2],'z'=>1],'infra_test',1))===$ordered->id,'JSONB key order does not break idempotency');
 $crashed=$queue->reserve('infra_test',5);
 $pdo->prepare("UPDATE queue_jobs SET reserved_until=clock_timestamp()-interval '1 second' WHERE id=:id")->execute(['id'=>$ordered->id]);
 $expect(fn()=>$queue->renew($crashed,60),'expired receipt cannot be revived');
 $ok($other->reserve('infra_test',15)===null&&$queue->find($ordered->id)['status']==='failed','expired last attempt becomes failed');
 $ok($queue->retry($ordered->id),'operator redrive after crash');
 $recovered=$other->reserve('infra_test',15);$queue->succeed($recovered,['ok'=>true]);
 $dir=sys_get_temp_dir().'/cajeer-pg-scheduler-'.bin2hex(random_bytes(6));
 $scheduler=new Scheduler($db,$queue,new JobProcessor(new RuntimeDiagnostics($root,$config),new LocalDisk($dir),$queue));
 $name='infra-'.bin2hex(random_bytes(6));$taskNames[]=$name;
 $scheduler->add($name,'*/5 * * * *','UTC','runtime.diagnostics',[], 'infra_test',true);
 $tick=$scheduler->tick();
 $ok($tick['scheduled']===1&&$tick['dispatched']===1&&$tick['dispatch_errors']===0,'scheduler outbox dispatch');
 $tick2=$scheduler->tick();
 $ok($tick2['scheduled']===0&&$tick2['dispatched']===0,'same minute not dispatched twice');
 $task=$pdo->prepare('SELECT id FROM scheduler_tasks WHERE name=:name');$task->execute(['name'=>$name]);$taskId=$task->fetchColumn();
 $out=$pdo->prepare('SELECT id,status FROM scheduler_outbox WHERE task_id=:id');$out->execute(['id'=>$taskId]);$dispatched=$out->fetch(PDO::FETCH_ASSOC);
 $ok($dispatched['status']==='sent','outbox delivery committed');$ids[]=$dispatched['id'];
 $reserved=$queue->reserve('infra_test',15);
 $ok($reserved!==null&&$reserved->job->id===$dispatched['id'],'scheduled job is reservable');
 $queue->succeed($reserved,['ok'=>true]);
 $ok($queue->find($dispatched['id'])['status']==='completed','scheduled job completed');
 // One poison outbox row must not prevent another delivery, including a nested PostgreSQL push error.
 $bad=QueuedJob::create('runtime.diagnostics',['different'=>true],'infra_test');$ids[]=$bad->id;$queue->push($bad);
 $pdo->prepare("INSERT INTO scheduler_outbox(id,task_id,due_at,job_type,payload,queue,next_attempt_at) VALUES(:id,:task,clock_timestamp()-interval '1 day','runtime.diagnostics','[]','infra_test',clock_timestamp()-interval '1 day')")->execute(['id'=>$bad->id,'task'=>$taskId]);
 $pdo->prepare("UPDATE scheduler_tasks SET next_run_at=clock_timestamp()-interval '2 days' WHERE id=:id")->execute(['id'=>$taskId]);
 $healthyName='healthy-'.bin2hex(random_bytes(6));$taskNames[]=$healthyName;
 $healthy=$scheduler->add($healthyName,'*/5 * * * *','UTC','runtime.diagnostics',[],'infra_test',true);
 $badName='invalid-'.bin2hex(random_bytes(6));$taskNames[]=$badName;
 $pdo->prepare("INSERT INTO scheduler_tasks(name,cron_expression,job_type,next_run_at) VALUES(:name,'invalid','runtime.diagnostics',clock_timestamp()-interval '3 days')")->execute(['name'=>$badName]);
 $tick=$scheduler->tick();
 $ok($tick['schedule_errors']===1&&$tick['scheduled']===1&&$tick['coalesced']===1&&$tick['dispatch_errors']===1&&$tick['dispatched']===1,'poison schedule and outbox isolated; healthy delivery continues');
 $retry=$scheduler->pendingDeliveries();$ok(count($retry)===1&&(int)$retry[0]['dispatch_attempts']===1&&$retry[0]['last_error']==='dispatch_failed','durable retry metadata visible');
 $next=$scheduler->tick();$ok($next['scheduled']===0&&$next['dispatch_errors']===0,'coalesced downtime and no immediate retry storm');
 $q=$pdo->prepare('SELECT id FROM scheduler_outbox WHERE task_id=:id');$q->execute(['id'=>$taskId]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $outId)$ids[]=$outId;
 $q->execute(['id'=>$healthy['id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $outId)$ids[]=$outId;
 $ok($queue->find($bad->id)['payload']['different']===true,'conflict did not overwrite existing queue job');
 echo "PASS: $checks assertions against disposable PostgreSQL.\n";
} catch(Throwable $error) {fwrite(STDERR,'[FAIL] PostgreSQL integration: '.$error::class.': '.$error->getMessage()."\n");exit(1);}
finally {
 try {if(isset($pdo)) {
  foreach($taskNames as $name){$s=$pdo->prepare('DELETE FROM scheduler_tasks WHERE name=:name');$s->execute(['name'=>$name]);}
  foreach($ids as $id){$s=$pdo->prepare('DELETE FROM queue_jobs WHERE id=:id');$s->execute(['id'=>$id]);}
 }}catch(Throwable){}
 foreach($old as $k=>$v){if($v===false)putenv($k);else putenv($k.'='.$v);}
}
