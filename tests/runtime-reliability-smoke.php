#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Queue\{JobWorker,JobProcessor,QueuedJob,QueueReservation,QueueDriverInterface,LeaseLost,RedisQueueDriver};
use Cajeer\Servers\Cache\RedisClient;
use Cajeer\Servers\Logging\JsonLogger;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Storage\LocalDisk;
use Cajeer\Servers\Database\SqlStatementParser;

final class RecoveryQueue implements QueueDriverInterface {
    public ?QueueReservation $next=null;
    public string $fault='';
    public int $renewed=0,$acked=0,$released=0,$reserves=0;
    public function push(QueuedJob $job,int $delaySeconds=0):string{return $job->id;}
    public function reserve(string $queue='default',int $leaseSeconds=60):?QueueReservation {
        $this->reserves++;
        if ($this->fault==='hang') sleep(15);
        if ($this->fault==='signal') posix_kill(getmypid(),SIGTERM);
        $r=$this->next;$this->next=null;return $r;
    }
    public function renew(QueueReservation $r,int $seconds):void{$this->renewed++;if($this->fault==='renew')throw new LeaseLost('stale');}
    public function succeed(QueueReservation $r,array $result):void{$this->acked++;if($this->fault==='ack')throw new LeaseLost('stale');}
    public function release(QueueReservation $r,string $reason,int $delaySeconds):void{$this->released++;if($this->fault==='release')throw new LeaseLost('stale');}
    public function find(string $id):?array{return null;}
    public function failed(int $limit=50):array{return [];}
    public function retry(string $id):bool{return false;}
    public function prune(int $days=7,string $queue='default',int $limit=500):int{return 0;}
}
$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/cajeer-runtime-recovery-'.bin2hex(random_bytes(8));
mkdir($dir,0700,true);
$rm=static function(string $path)use(&$rm):void{foreach(new FilesystemIterator($path)as $f){if($f->isDir()&&!$f->isLink())$rm($f->getPathname());else unlink($f->getPathname());}rmdir($path);};
$n=0;$ok=static function(bool $v,string $s)use(&$n):void{if(!$v)throw new RuntimeException('FAIL '.$s);$n++;echo "PASS $s\n";};
$reject=static function(callable $f,string $s)use($ok):void{try{$f();}catch(Throwable){$ok(true,$s);return;}throw new RuntimeException('Expected rejection '.$s);};
try {
    $queue=new RecoveryQueue();$disk=new LocalDisk($dir.'/files');$processor=new JobProcessor(new RuntimeDiagnostics($root,new ConfigRepository($root.'/config')),$disk,$queue);
    $worker=new JobWorker($queue,$processor,new JsonLogger($dir.'/logs'));
    $job=QueuedJob::create('storage.probe');$reserve=static fn(QueuedJob $j)=>new QueueReservation($j,'receipt',1);
    $queue->next=$reserve($job);$queue->fault='renew';
    $ok($worker->once()['status']==='lease_lost'&&$queue->acked===0&&$queue->released===0,'stale heartbeat never invokes handler or retry');
    $queue->next=$reserve($job);$queue->fault='ack';
    $ok($worker->once()['status']==='lease_lost'&&$queue->acked===1&&$queue->released===0,'stale completion never reschedules completed work');
    $queue->next=$reserve(QueuedJob::create('unregistered.handler'));$queue->fault='release';
    $ok($worker->once()['status']==='lease_lost'&&$queue->released===1,'lost retry receipt returns explicit lease_lost');
    $queue->next=$reserve($job);$queue->fault='';
    $ok($worker->once()['status']==='completed'&&$queue->renewed===4,'subsequent job continues after stale deliveries');
    $queue->next=$reserve($job);$worker->stop();$before=$queue->reserves;
    $ok($worker->once()===null&&$queue->reserves===$before,'draining worker stops claiming new jobs');
    $ok(QueuedJob::samePayload(['b'=>['z'=>2,'x'=>1],'a'=>3],['a'=>3,'b'=>['x'=>1,'z'=>2]]),'object ordering ignored recursively');
    $ok(!QueuedJob::samePayload(['a'=>[1,2]],['a'=>[2,1]]),'array ordering preserved');
    $ok(!QueuedJob::samePayload(['a'=>9007199254740993],['a'=>9007199254740992]),'large integers remain distinct');
    $ok(!QueuedJob::samePayload(['a'=>1],['a'=>'1']),'number and string remain distinct');
    $redis=new class extends RedisClient {
        public array $calls=[];
        public mixed $answer=null;
        public function command(string ...$args):mixed{$this->calls[]=$args;return $this->answer;}
    };
    $driver=new RedisQueueDriver($redis,'recovery:');
    $reject(fn()=>$driver->push(QueuedJob::create('connector.execute',[],'connectors')),'Redis prohibits connector execution');
    $reject(fn()=>$driver->reserve('connectors'),'Redis prohibits connector reservations');
    $reject(fn()=>$driver->prune(7,'connectors'),'Redis prohibits connector pruning');
    $ok($redis->calls===[],'reserved queue rejected before network');
    $redis->answer=json_encode(['id'=>$job->id,'payload'=>['number'=>9007199254740992],'payload_json'=>'{"number":9007199254740993}','result'=>['number'=>9007199254740992],'result_json'=>'{"number":9007199254740993}'],JSON_THROW_ON_ERROR);
    $row=$driver->find($job->id);
    $ok($row['payload']['number']===9007199254740993&&$row['result']['number']===9007199254740993&&!isset($row['payload_json']),'raw JSON restores exact numeric payload and result');
    $redis->answer=0;$reject(fn()=>$driver->renew($reserve($job),60),'Redis rejects stale renewal result');
    $sql=file_get_contents($root.'/migrations/pgsql/0063_runtime_reliability.sql');
    $ok(count((new SqlStatementParser())->split($sql))===4,'runtime migration parsed by production migration runner');
    // Real child process exercises the production watchdog, not a simulated timer.
    if (!function_exists('pcntl_fork')||!function_exists('pcntl_alarm')) throw new RuntimeException('pcntl required for watchdog regression');
    $pid=pcntl_fork();if($pid===-1)throw new RuntimeException('fork failed');
    if($pid===0){$queue->fault='hang';(new JobWorker($queue,$processor,new JsonLogger($dir.'/logs')))->once('default',5,true);exit(9);}
    $start=hrtime(true);pcntl_waitpid($pid,$status);
    $ok(pcntl_wifsignaled($status)&&pcntl_wtermsig($status)===SIGALRM&&(hrtime(true)-$start)<10_000_000_000,'hard watchdog terminates hung child before lease window');
    $drain=new JobWorker($queue,$processor,new JsonLogger($dir.'/logs'));
    $queue->next=$reserve($job);$queue->fault='signal';$before=$queue->acked;
    $ok($drain->once('default',60,true)['status']==='completed'&&$queue->acked===$before+1,'actual SIGTERM drains the in-flight reservation');
    $queue->next=$reserve($job);$ok($drain->once()===null,'SIGTERM prevents another reservation');
    echo "Runtime reliability: $n PASS\n";
} finally {$rm($dir);}
