#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Real Redis integration. Uses a random namespace, never FLUSHDB/FLUSHALL. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Cache\RedisClient;
use Cajeer\Servers\Cache\RedisCacheStore;
use Cajeer\Servers\Queue\QueuedJob;
use Cajeer\Servers\Queue\RedisQueueDriver;

if(getenv('CAJEER_REDIS_TEST')!=='1'){fwrite(STDERR,"SKIP: CAJEER_REDIS_TEST=1 is required.\n");exit(77);}
$prefix='cajeer:integration:'.bin2hex(random_bytes(8)).':';
$redis=new RedisClient(getenv('CAJEER_REDIS_HOST')?:'127.0.0.1',(int)(getenv('CAJEER_REDIS_PORT')?:6379),
 getenv('CAJEER_REDIS_USERNAME')?:'',getenv('CAJEER_REDIS_PASSWORD')?:'',(int)(getenv('CAJEER_REDIS_DB')?:0),3.0,getenv('CAJEER_REDIS_TLS')==='1');
$count=0;$keys=[];
$ok=static function(bool $valid,string $label)use(&$count):void{if(!$valid)throw new RuntimeException('FAIL: '.$label);$count++;};
$expectFail=static function(callable $fn,string $label)use($ok):void{try{$fn();}catch(Throwable){$ok(true,$label);return;}throw new RuntimeException('Expected failure: '.$label);};
try {
 $ok($redis->command('PING')==='PONG','Redis reachable');
 $cache=new RedisCacheStore($redis,$prefix.'cache:');
 $cache->put('binary',"\0\xfe\x81",5);
 $ok($cache->get('binary')==="\0\xfe\x81",'RESP binary safe');
 $ok($cache->increment('counter',3,10)===3 && $cache->increment('counter',4,10)===7,'atomic increment');
 $ok($cache->delete('binary')&&!$cache->has('binary'),'TTL cache deletion');
 $queue=new RedisQueueDriver($redis,$prefix.'queue:');
 $job=QueuedJob::create('runtime.diagnostics',['test'=>true], 'default',2);
 $id=$queue->push($job);
 $ok($id===$job->id && $queue->push($job)===$id,'idempotent enqueue');
 $reserved=$queue->reserve('default',15);
 $ok($reserved!==null&&$reserved->attempt===1&&$reserved->job->id===$id,'atomic reserve');
 $ok($queue->reserve('default',15)===null,'lease prevents double delivery');
 $queue->renew($reserved,60);
 $ok($queue->reserve('default',15)===null,'renew protects active receipt');
 $queue->release($reserved,'Simulated failure',0);
 $ok($queue->find($id)['status']==='queued','retry requeued');
 $again=$queue->reserve('default',15);
 $ok($again!==null && $again->attempt===2,'retry increments attempt');
 $expectFail(fn()=> $queue->succeed($reserved,[]),'stale receipt denied');
 $expectFail(fn()=> $queue->renew($reserved,60),'stale receipt cannot renew');
 $queue->release($again,'Simulated terminal failure',0);
 $ok($queue->find($id)['status']==='failed'&&count($queue->failedInQueue('default'))===1,'DLQ retains terminal failure');
 $ok($queue->retry($id),'retry failed job');
 $success=$queue->reserve('default',15);
 $queue->succeed($success,['ok'=>true]);
 $ok($queue->find($id)['status']==='completed','job completed');
 $expectFail(fn()=>$queue->succeed($again,[]),'completed job rejects stale receipt');
 $ok($queue->failedInQueue('default')===[],'failed list excludes retried job');
 $large=QueuedJob::create('runtime.diagnostics',['z'=>9007199254740993,'a'=>['list'=>[1,2]]],'default',2);$queue->push($large);
 $ok($queue->push(new QueuedJob($large->id,$large->type,['a'=>['list'=>[1,2]],'z'=>9007199254740993],'default',2))===$large->id,'idempotent reorder and exact integer');
 $redis->command('ZADD',$prefix.'queue:ready:default','0','missing-job');
 $first=$queue->reserve('default',5);
 $ok($first!==null&&$first->job->payload['z']===9007199254740993,'missing index entry skipped and integer preserved');
 $queue->renew($first,6);
 sleep(7);
 $expectFail(fn()=>$queue->renew($first,60),'expired lease cannot be renewed');
 $second=$queue->reserve('default',5);
 $ok($second!==null&&$second->attempt===2&&$second->receipt!==$first->receipt,'lease expiry redelivery');
 $expectFail(fn()=>$queue->succeed($first,[]),'old ACK cannot change replacement lease');
 $queue->succeed($second,['number'=>9007199254740993]);
 $ok($queue->find($large->id)['result']['number']===9007199254740993,'result retains exact integer');
 $expectFail(fn()=>$queue->push(QueuedJob::create('connector.execute',[],'connectors')),'Redis denies connector queue');
 $redis->command('DEL',$prefix.'queue:job:'.$large->id);
 // Explicit cleanup of every key from this private namespace only.
 $redis->command('DEL',$prefix.'cache:binary',$prefix.'cache:counter',$prefix.'queue:job:'.$id,
  $prefix.'queue:ready:default',$prefix.'queue:processing:default',$prefix.'queue:failed:default',$prefix.'queue:completed:default');
 echo "PASS: $count assertions against live Redis.\n";
} catch(Throwable $e) {fwrite(STDERR,'[FAIL] Redis integration: '.$e::class.': '.$e->getMessage()."\n");exit(1);}
finally {$redis->close();}
