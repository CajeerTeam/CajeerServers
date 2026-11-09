#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Cache\RedisClient;
use Cajeer\Servers\Cache\RedisCacheStore;
use Cajeer\Servers\Queue\QueuedJob;
use Cajeer\Servers\Queue\RedisQueueDriver;
use Cajeer\Servers\Queue\QueueDriverInterface;
use Cajeer\Servers\Queue\QueueReservation;
use Cajeer\Servers\Queue\JobProcessor;
use Cajeer\Servers\Queue\JobWorker;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;
use Cajeer\Servers\Logging\JsonLogger;
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Scheduler\ScheduledTask;
use Cajeer\Servers\Storage\LocalDisk;
use Cajeer\Servers\Storage\S3CompatibleDisk;

$count=0;
$check=static function(bool $value,string $title)use(&$count):void{if(!$value)throw new RuntimeException('FAIL: '.$title);$count++;};
$reject=static function(callable $operation,string $title)use($check):void{
 try{$operation();}catch(Throwable){$check(true,$title);return;}throw new RuntimeException('Expected rejection: '.$title);
};
$dir=sys_get_temp_dir().'/cajeer-infra-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);
$rm=static function(string $path)use(&$rm):void{
 foreach(new FilesystemIterator($path)as $file){if($file->isDir()&&!$file->isLink())$rm($file->getPathname());else unlink($file->getPathname());}rmdir($path);
};
try {
 $disk=new LocalDisk($dir.'/store');
 $raw="\0\xff\x80hello\n";
 $disk->put('folder/deep/file.bin',$raw);
 $check($disk->exists('folder/deep/file.bin'),'local store write/exists');
 $check(hash_equals($raw,$disk->get('folder/deep/file.bin')),'local binary read');
 $disk->put('folder/deep/file.bin','replaced');
 $check($disk->get('folder/deep/file.bin')==='replaced','atomic overwrite');
 $check($disk->delete('folder/deep/file.bin'),'local delete');
 $check(!$disk->delete('folder/deep/file.bin'),'repeat delete false');
 foreach(['../escape','/root','folder/../escape','foo//bar','foo\\bar','foo/./bar','foo/'.chr(0)] as $unsafe) {
  $reject(fn()=>$disk->put($unsafe,'hello'),'traversal blocked');
 }
 symlink($dir,$dir.'/store/linked');
 $reject(fn()=>$disk->put('linked/escape','no'),'symlink directory refused');
 symlink($dir.'/outside',$dir.'/store/symlink.txt');
 $reject(fn()=>$disk->put('symlink.txt','no'),'symlink file refused');
 $reject(fn()=>$disk->get('symlink.txt'),'symlink read refused');
 $check(!file_exists($dir.'/outside'),'outside storage unaffected');
 $reject(fn()=>$disk->put('giant',str_repeat('a',16*1024*1024+1)),'storage size limited');

 $schedule=new ScheduledTask('*/15 9-17 * * 1-5','Europe/Amsterdam');
 $next=$schedule->next(new DateTimeImmutable('2026-11-09T08:58:00+01:00'));
 $check($next->format('Y-m-d H:i')==='2026-11-09 09:00','cron next in correct timezone');
 $check($schedule->matches($next),'cron matches calculated next');
 $check(!(new ScheduledTask('0 10 * * 7'))->matches(new DateTimeImmutable('2026-11-09 10:00:00+00:00')),'Sunday-only rejects Monday');
 $check((new ScheduledTask('0 10 * * 7'))->matches(new DateTimeImmutable('2026-11-08 10:00:00+00:00')),'Sunday field 7 supported');
 $check((new ScheduledTask('0 9 15 * 1'))->matches(new DateTimeImmutable('2026-11-09 09:00:00+00:00')),'DOM/DOW OR semantics');
 $check((new ScheduledTask('0 2 * * *','Europe/Amsterdam'))->next(new DateTimeImmutable('2026-03-29T01:59:00+01:00'))->format('Y-m-d H:i P')==='2026-03-30 02:00 +02:00','spring DST skip');
 $reject(fn()=>new ScheduledTask('bad * * * *'),'invalid cron rejected');
 $reject(fn()=>new ScheduledTask('* * * * *','Invalid/Zone'),'invalid timezone rejected');
 $reject(fn()=>new ScheduledTask('*/0 * * * *'),'zero step rejected');
 $reject(fn()=>new ScheduledTask('60 * * * *'),'out of range rejected');

 $job=QueuedJob::create('runtime.diagnostics',['a'=>'b'],'fast',2);
 $check(strlen($job->id)===36 && $job->maxAttempts===2,'UUID queue job created');
 $reject(fn()=>QueuedJob::create('any',[], 'bad queue'),'queue naming rejected');
 $reject(fn()=>QueuedJob::create('any',['secret'=>str_repeat('a',65536)]),'oversized queue payload rejected');

 $mock=new class extends RedisClient {
  public array $calls=[];
  public array $items=[];
  public function __construct(){}
  public function command(string ...$args):mixed {
   $this->calls[]=$args;
   return match($args[0]) {
    'PING'=>'PONG','GET'=>$this->items[$args[1]]??null,
    'SET'=>($this->items[$args[1]]=$args[2])!==null?'OK':'FAIL',
    'DEL'=>isset($this->items[$args[1]])?(function()use($args){unset($this->items[$args[1]]);return 1;})():0,
    'EXISTS'=>isset($this->items[$args[1]])?1:0,
    'EVAL'=>(function()use($args){$key=$args[3];$this->items[$key]=(string)((int)($this->items[$key]??0)+(int)$args[4]);return(int)$this->items[$key];})(),
    default=>throw new RuntimeException('Unknown fake Redis command'),
   };
  }
 };
 $cache=new RedisCacheStore($mock,'test:');
 $check($cache->get('missing')===null,'cache miss');
 $cache->put('mykey',"binary\0value",120);
 $check($cache->get('mykey')==="binary\0value",'Redis cache binary value');
 $check($cache->has('mykey'),'cache exists');
 $check($cache->increment('hits',2,120)===2,'atomic cache increment');
 $check($cache->delete('mykey') && !$cache->has('mykey'),'cache delete');
 $reject(fn()=>$cache->put('bad key','x'),'cache invalid key denied');
 $reject(fn()=>$cache->put('x','a',0),'cache invalid TTL denied');
 $check($cache->ping(),'Redis ping');
 $check($mock->calls[1][1]==='test:mykey','cache uses namespace');

 $queueFake=new class implements QueueDriverInterface {
  public ?QueueReservation $next=null;
  public ?array $completed=null;
  public array $failures=[];
  public function push(QueuedJob $job,int $delaySeconds=0):string {return $job->id;}
  public function reserve(string $queue='default',int $leaseSeconds=60):?QueueReservation {$r=$this->next;$this->next=null;return $r;}
  public function succeed(QueueReservation $r,array $result):void {$this->completed=$result;}
  public function release(QueueReservation $r,string $reason,int $delaySeconds):void {$this->failures[]=[$reason,$delaySeconds];}
  public function find(string $id):?array {return null;}
  public function failed(int $limit=50):array {return [];}
  public function retry(string $id):bool {return false;}
  public function prune(int $days=7,string $queue='default',int $limit=500):int {return 9;}
 };
 $project=dirname(__DIR__);
 $processor=new JobProcessor(new RuntimeDiagnostics($project,new ConfigRepository($project.'/config')),$disk,$queueFake);
 $workerMetrics = new \Cajeer\Servers\Observability\PrometheusExporter($dir.'/worker-metrics.json');
 $worker=new JobWorker($queueFake,$processor,new JsonLogger($dir.'/logs'),$workerMetrics);
 $probe=QueuedJob::create('storage.probe',[], 'default',2);
 $queueFake->next=new QueueReservation($probe,'receipt',1);
 $r=$worker->once();
 $check($r['status']==='completed' && $queueFake->completed['ok']===true,'worker executes storage read/write/delete');
 $unknown=QueuedJob::create('unknown.handler',[], 'default',2);
 $queueFake->next=new QueueReservation($unknown,'receipt2',1);
 $r=$worker->once();
 $check($r['status']==='retry_scheduled'&&count($queueFake->failures)===1,'unknown handler schedules real retry');
 $queueFake->next=new QueueReservation($unknown,'receipt3',2);
 $r=$worker->once();
 $check($r['status']==='failed','job reaches terminal attempt');
 $collected=$workerMetrics->render();
 $check(str_contains($collected, 'cajeer_queue_jobs_processed_total{type="storage.probe",outcome="completed"} 1'), 'real worker success metric');
 $check(str_contains($collected, 'cajeer_queue_jobs_processed_total{type="other",outcome="failed"} 2'), 'real worker retry/failed attempts metric');
 $check($processor->supports('queue.prune'),'queue retention job registered');
 $check($processor->process(QueuedJob::create('queue.prune',['days'=>7]))['deleted']===9,'queue retention processor executes');
 $reject(fn()=> $processor->process(QueuedJob::create('queue.prune',['days'=>'seven'])),'invalid prune payload denied');
 $parse=(new SqlStatementParser())->split((string)file_get_contents($project.'/migrations/pgsql/0006_queue_scheduler.sql'));
 $check(count($parse)===8,'all migration SQL statements parsed');

 // Live TCP exchange against a tiny RESP2 fixture validates framing, binary payloads and auth.
 $tcpPortSocket=stream_socket_server('tcp://127.0.0.1:0',$eno,$emsg);
 $tcpAddress=stream_socket_get_name($tcpPortSocket,false);fclose($tcpPortSocket);
 $tcpPort=(int)substr(strrchr($tcpAddress,':'),1);
 $tcpLog=$dir.'/redisserver.log';
 $tcpProc=proc_open([PHP_BINARY,dirname(__FILE__).'/fixtures/redis-resp-mock.php',(string)$tcpPort],
  [0=>['pipe','r'],1=>['file',$tcpLog,'w'],2=>['file',$tcpLog,'a']],$tcpPipes,dirname(__DIR__));
 if(!is_resource($tcpProc))throw new RuntimeException('Cannot start Redis protocol fixture.');
 fclose($tcpPipes[0]);usleep(180000);
 try {
  $tcpClient=new RedisClient('127.0.0.1',$tcpPort,'','fixture-secret',2,2);
  $check($tcpClient->command('PING')==='PONG','binary RESP connection AUTH/SELECT/PING');
  $check($tcpClient->command('GET','missing')===null,'RESP nil parsing');
  $check($tcpClient->command('SET','binary',"\0\xff\x80") === 'OK','RESP binary SET');
  $check($tcpClient->command('GET','binary')==="\0\xff\x80",'RESP binary GET');
  $check($tcpClient->command('DEL','binary')===1,'RESP integer response');
  $tcpClient->close();
 }finally{proc_terminate($tcpProc);proc_close($tcpProc);}

 $reject(fn()=>new S3CompatibleDisk('http://example.com','test-bucket','us-east-1','testaccess','testsecret'),'S3 denies external HTTP');
 $reject(fn()=>new S3CompatibleDisk('https://user@localhost','test-bucket','us-east-1','testaccess','testsecret'),'S3 rejects userinfo');
 // A real PHP HTTP server validates SigV4 in a separate test (below).
 $portSocket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
 $check(is_resource($portSocket),'free loopback port selected');
 $address=stream_socket_get_name($portSocket,false);fclose($portSocket);
 $port=(int)substr(strrchr($address,':'),1);
 $file=dirname(__FILE__).'/fixtures/s3-mock.php';
 $log=$dir.'/s3server.log';
 $des=[0=>['pipe','r'],1=>['file',$log,'w'],2=>['file',$log,'a']];
 $proc=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,$file],$des,$pipes,dirname(__DIR__),['CAJEER_S3_FIXTURE_ROOT'=>$dir.'/s3objects']);
 if (!is_resource($proc))throw new RuntimeException('Could not start S3 fixture server.');
 fclose($pipes[0]);
 try {
  $online=false;
  for($i=0;$i<40;$i++){
   $sock=@stream_socket_client('tcp://127.0.0.1:'.$port,$e,$msg,0.1);
   if(is_resource($sock)){fclose($sock);$online=true;break;}usleep(50000);
  }
  $check($online,'S3 mock server started');
  $s3=new S3CompatibleDisk('http://127.0.0.1:'.$port,'test-bucket','us-east-1','testaccess','testsecret','',true);
  $check(!$s3->exists('one/test space.txt'),'S3 signed HEAD returns 404');
  $s3->put('one/test space.txt',$raw);
  $check($s3->exists('one/test space.txt'),'S3 signed PUT and HEAD');
  $check(hash_equals($raw,$s3->get('one/test space.txt')),'S3 SigV4 GET binary bytes');
  $check($s3->delete('one/test space.txt'),'S3 signed DELETE');
  $check(!$s3->exists('one/test space.txt'),'S3 object removed');
 } finally {proc_terminate($proc);proc_close($proc);}
 echo "PASS: $count infrastructure smoke checks (local storage, cron, cache mock, signed S3 fixture).\n";
} catch(Throwable $e) {fwrite(STDERR,'[FAIL] '.$e::class.': '.$e->getMessage()."\n");exit(1);}
finally{$rm($dir);}
