#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\ConnectorExecutionService;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Queue\{QueuedJob,PostgresQueueDriver,JobWorker};
use Cajeer\Servers\Database\{DatabaseManager,SqlStatementParser};
use Cajeer\Servers\Http\Exception\HttpException;
$n=0;
$ok=static function(bool $v,string $label)use(&$n):void {if(!$v)throw new RuntimeException('FAIL: '.$label);$n++;};
$reject=static function(callable $f,?int $status=null)use($ok):void {try{$f();}catch(Throwable $e){$ok($status===null||($e instanceof HttpException&&$e->status===$status),'expected rejection');return;}throw new RuntimeException('Expected rejection');};
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application(dirname(__DIR__)))->boot();
try {
    $service=$app->container()->get(ConnectorExecutionService::class);
    $ok($service instanceof ConnectorExecutionService,'DI execution service');
    $id='11111111-1111-4111-8111-111111111111';
    foreach(['vm.start','validate_rotation','custom'] as $op) $reject(fn()=>$service->issue([], $id,1,$op,3600),422);
    foreach([0,59,2592001] as $ttl) $reject(fn()=>$service->issue([], $id,1,'sync',$ttl),422);
    foreach([0,59,86401] as $interval) $reject(fn()=>$service->issue([], $id,1,'sync',3600,$interval),422);
    $reject(fn()=>$service->enqueue($id,$id,$id,''),422);
    $reject(fn()=>$service->enqueue($id,$id,$id,str_repeat('x',129)),422);
    $reject(fn()=>$service->tick(0));$reject(fn()=>$service->tick(201));
    $reject(fn()=>$service->runs($id,$id,0)); // permission/DB deny before any query if DB absent
    $queue=new PostgresQueueDriver($app->container()->get(DatabaseManager::class));
    $reject(fn()=>$queue->push(QueuedJob::create('runtime.diagnostics',[],'connectors')));
    $reject(fn()=>$queue->push(QueuedJob::create(ConnectorExecutionService::TYPE,['secret'=>'bad'],'connectors')));
    $reject(fn()=>$queue->prune(7,'connectors'));
    $reject(fn()=>$app->container()->get(JobWorker::class)->once('connectors'));
    $job=QueuedJob::create(ConnectorExecutionService::TYPE,[],ConnectorExecutionService::QUEUE,5);
    $ok($job->payload===[] && $job->queue==='connectors','no secret-bearing queue payload');
    $base='/api/v1/connectors/targets/'.$id;
    foreach ([['GET','/service-grants'],['POST','/service-grants'],['DELETE','/service-grants/'.$id],['GET','/runs'],['POST','/runs'],['GET','/runs/'.$id],['POST','/runs/'.$id.'/cancel']] as [$method,$path]) {
        $response=$app->handle(new HttpRequest($method,$base.$path));
        $ok(in_array($response->status,[400,401,415,422,503],true),'route registered and denies unauthorized request');
    }
    $sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0023_connector_execution.sql');
    $ok(count((new SqlStatementParser)->split($sql))===5,'migration parses');
    $ok(!str_contains($sql,'encrypted_payload')&&!str_contains($sql,'password'),'metadata-only grant/run tables');
} finally {$app->terminate();}
echo "Connector execution smoke: $n PASS\n";
