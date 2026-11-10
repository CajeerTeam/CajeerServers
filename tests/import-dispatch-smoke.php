#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactoryInterface;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Inventory\HistoryService;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\TransferService;
use Cajeer\Servers\Queue\QueueDriverInterface;
use Cajeer\Servers\Queue\QueueReservation;
use Cajeer\Servers\Queue\QueuedJob;
use Cajeer\Servers\Security\Rbac;

final class DispatchPDO extends PDO
{
    public bool $tx=false;
    public int $commits=0;
    public ?string $job=null;
    public string $status='pending';
    public function __construct() {}
    public function beginTransaction():bool{$this->tx=true;return true;}
    public function commit():bool{$this->commits++;$this->tx=false;return true;}
    public function rollBack():bool{$this->tx=false;return true;}
    public function inTransaction():bool{return $this->tx;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new DispatchStmt($this,$query);}
}
final class DispatchStmt extends PDOStatement
{
    public function __construct(private readonly DispatchPDO $pdo,private readonly string $sql){}
    public function execute(?array $params=null):bool
    {
        if(str_contains($this->sql,'SET queue_job_id=')){$this->pdo->job=$params['job'];}
        if(str_contains($this->sql,"SET status='enqueued'")){
            if(in_array($this->pdo->status,['pending','enqueued'],true))$this->pdo->status='enqueued';
        }
        return true;
    }
    public function fetchColumn(int $column=0):mixed
    {
        if(str_contains($this->sql,'SELECT wm.role'))return 'owner';
        if(str_contains($this->sql,'SELECT status FROM inventory_import_batches'))return $this->pdo->status;
        throw new RuntimeException('Unexpected column read');
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed
    {
        if(str_contains($this->sql,'SELECT id::text,status,queue_job_id::text'))
            return ['id'=>'9df068ce-b377-40ab-85be-e71db79a9e1f','status'=>$this->pdo->status,'queue_job_id'=>$this->pdo->job];
        throw new RuntimeException('Unexpected row read');
    }
}
final class DispatchQueue implements QueueDriverInterface
{
    public array $jobs=[];
    public int $calls=0;
    public bool $fail=false;
    public function __construct(private readonly DispatchPDO $pdo){}
    public function push(QueuedJob $job,int $delaySeconds=0):string
    {
        if($this->pdo->inTransaction()||$this->pdo->commits<1||$this->pdo->job!==$job->id)
            throw new RuntimeException('External queue published before durable PostgreSQL commit');
        $this->calls++;
        if($this->fail)throw new RuntimeException('Simulated queue outage');
        $this->jobs[$job->id]=$job;
        return $job->id;
    }
    public function reserve(string $queue='default',int $leaseSeconds=60):?QueueReservation{return null;}
    public function succeed(QueueReservation $reservation,array $result):void{}
    public function release(QueueReservation $reservation,string $reason,int $delaySeconds):void{}
    public function find(string $id):?array{return null;}
    public function failed(int $limit=50):array{return [];}
    public function retry(string $id):bool{return false;}
    public function prune(int $days=7,string $queue='default',int $limit=500):int{return 0;}
}

$checks=0;
$ok=static function(bool $state,string $label)use(&$checks){if(!$state)throw new RuntimeException('FAIL: '.$label);$checks++;};
$tmp=sys_get_temp_dir().'/cajeer-import-dispatch-'.bin2hex(random_bytes(4));mkdir($tmp,0700);
try{
    file_put_contents($tmp.'/database.php',"<?php return ['default'=>'pgsql','connections'=>['pgsql'=>['driver'=>'pgsql']]];");
    $pdo=new DispatchPDO();
    $factory=new class($pdo) implements ConnectionFactoryInterface {
        public function __construct(private readonly PDO $pdo){}
        public function make(array $config):PDO{return $this->pdo;}
    };
    $db=new DatabaseManager(new ConfigRepository($tmp),$factory);
    $audit=new AuditLogger($db);
    $repo=new InventoryRepository();
    // HistoryService and Rbac are not invoked by dispatch; dependencies remain real typed classes.
    $inventory=new \Cajeer\Servers\Inventory\InventoryService($db,$repo,$audit);
    $history=new HistoryService($db,$repo,$inventory,$audit);
    $rbac=new Rbac($db,$audit);
    $queue=new DispatchQueue($pdo);
    $service=new TransferService($db,$repo,$history,$queue,$audit,$rbac);
    $id='9df068ce-b377-40ab-85be-e71db79a9e1f';
    $ws='fc31369d-c9f0-4d91-ad05-0547e2bde130';
    $actor='0c4aa0f9-4fe5-4e0f-aa40-03efdd5eb52a';
    $queue->fail=true;
    try{$service->dispatch($actor,$ws,$id);throw new RuntimeException('dispatch should fail');}catch(RuntimeException $e){
        $ok($e->getMessage()==='Simulated queue outage','queue outage propagated');
    }
    $persisted=$pdo->job;
    $ok($persisted!==null&&$pdo->commits===1,'durable job ID committed before failed queue call');
    $ok($pdo->status==='pending','failed queue push leaves batch pending');
    $queue->fail=false;
    $result=$service->dispatch($actor,$ws,$id);
    $ok($result['status']==='enqueued'&&$result['job_id']===$persisted,'retry reuses same job ID');
    $ok(count($queue->jobs)===1&&$queue->calls===2,'successful job published once');
    $result=$service->dispatch($actor,$ws,$id);
    $ok($result['job_id']===$persisted&&count($queue->jobs)===1,'repeated push deduplicates by ID');
    $pdo->status='completed';
    $result=$service->dispatch($actor,$ws,$id);
    $ok($result['status']==='completed'&&count($queue->jobs)===1,'completed batch never rescheduled');
    $ok(!$pdo->inTransaction(),'transactions closed after queue calls');
    echo "Import dispatch smoke: $checks PASS\n";
}finally{@unlink($tmp.'/database.php');@rmdir($tmp);}
