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
 $job=QueuedJob::create('runtime.diagnostics',['test'=>true],'infra_test',2);$ids[]=$job->id;
 $queue->push($job);
 $ok($queue->push($job)===$job->id,'idempotent PostgreSQL push');
 $r=$queue->reserve('infra_test',15);
 $ok($r!==null&&$r->attempt===1,'SKIP LOCKED reserve');
 $ok($queue->reserve('infra_test',15)===null,'leased job unavailable');
 $queue->release($r,'first failure',0);
 $r2=$queue->reserve('infra_test',15);
 $ok($r2!==null&&$r2->attempt===2,'retry');
 $expect(fn()=>$queue->succeed($r,[]),'stale receipt denied');
 $queue->release($r2,'terminal failure',0);
 $ok($queue->find($job->id)['status']==='failed','failure persisted');
 $ok($queue->retry($job->id),'manual failed retry');
 $r3=$queue->reserve('infra_test',15);
 $queue->succeed($r3,['ok'=>true]);
 $ok($queue->find($job->id)['result']['ok']===true,'success persisted');
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
 echo "PASS: $checks assertions against disposable PostgreSQL.\n";
} catch(Throwable $error) {fwrite(STDERR,'[FAIL] PostgreSQL integration: '.$error::class.': '.$error->getMessage()."\n");exit(1);}
finally {
 try {if(isset($pdo)) {
  foreach($taskNames as $name){$s=$pdo->prepare('DELETE FROM scheduler_tasks WHERE name=:name');$s->execute(['name'=>$name]);}
  foreach($ids as $id){$s=$pdo->prepare('DELETE FROM queue_jobs WHERE id=:id');$s->execute(['id'=>$id]);}
 }}catch(Throwable){}
 foreach($old as $k=>$v){if($v===false)putenv($k);else putenv($k.'='.$v);}
}
