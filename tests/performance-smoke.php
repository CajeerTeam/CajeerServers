<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Events\EventStore;
use Cajeer\Servers\Metrics\{MetricBatch,MetricStore};
use Cajeer\Servers\Database\SqlStatementParser;
final class PerfPdo extends PDO {
    public array $calls=[],$rows=[];public int $missing=0,$eventCount=0;
    public function __construct(){}
    public function inTransaction():bool{return true;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new PerfStatement($this,$query);}
}
final class PerfStatement extends PDOStatement {
    private array $args=[];private int $added=0;
    public function __construct(private PerfPdo $pdo,private string $sql){}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool{$this->args[ltrim((string)$param,':')]=$value;return true;}
    public function execute(?array $params=null):bool{
        $this->args=$params??$this->args;$this->pdo->calls[]=[$this->sql,$this->args];
        if(str_starts_with($this->sql,'INSERT INTO system_events'))$this->added=count(array_unique(array_column(json_decode($this->args['batch'],true,16,JSON_THROW_ON_ERROR),'key')));
        return true;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->pdo->rows;}
    public function fetchColumn(int $column=0):mixed{
        if(str_contains($this->sql,'count(*) FROM system_events'))return $this->pdo->eventCount;
        if(str_contains($this->sql,'WHERE NOT EXISTS(SELECT 1 FROM metric_series'))return $this->pdo->missing;
        if(str_contains($this->sql,'count(*) FROM inventory_assets'))return 3;
        if(str_contains($this->sql,'count(*)'))return 0;
        return 1;
    }
    public function rowCount():int{return $this->added;}
}
$n=0;$ok=static function(bool $b,string $label)use(&$n){if(!$b)throw new RuntimeException('FAIL '.$label);$n++;};
$ws='11111111-1111-4111-8111-111111111111';$id='22222222-2222-4222-8222-222222222222';$actor='33333333-3333-4333-8333-333333333333';
$p=new PerfPdo();$repo=new InventoryRepository();$p->rows=[];
for($i=1;$i<=3;$i++)$p->rows[]=['id'=>sprintf('00000000-0000-4000-8000-%012d',$i),'created_at'=>'2026-01-01 00:00:00+00','ip_addresses'=>'[]','tags'=>'[]','attributes'=>'{}','version'=>1];
$r=$repo->page($p,$actor,$ws,2,0,null,null,null,false,[],false);
$ok(count($p->calls)===1&&$r['total']===null&&$r['has_more']&&count($r['data'])===2,'count-free page executes one query');
$ok(InventoryRepository::decodeCursor($r['next_cursor'])[1]===$p->rows[1]['id'],'cursor from final returned row');
$p->calls=[];$r=$repo->page($p,$actor,$ws,2,0,null,null,'%_!',false,['cursor'=>$r['next_cursor']]);
$ok(count($p->calls)===2&&$r['total']===3,'legacy exact count remains');
$ok(!isset($p->calls[0][1]['cursor_id'])&&isset($p->calls[1][1]['cursor_id']),'count covers all filtered rows');
$ok($p->calls[1][1]['ip_workspace']===$ws&&$p->calls[1][1]['ip_like']==='%!%!_!!%','IP search scoped and escaped');
$ok(str_contains($p->calls[1][0],'a.id IN (SELECT ni.asset_id'),'set-based IP search');
$p->rows=[];$p->calls=[];$r=$repo->page($p,$actor,$ws,2,0,null,null,null,false,[],false);$ok(!$r['has_more']&&$r['next_cursor']===null,'empty page');
$target=['id'=>$id,'workspace_id'=>$ws,'asset_id'=>$id,'version'=>1,'provider'=>'ssh'];
$event=['key'=>'one','channel'=>'journal','severity'=>5,'message'=>'first'];$p->calls=[];
$rows=[];for($i=0;$i<200;$i++)$rows[]=array_replace($event,['key'=>'event-'.$i,'message'=>'message '.$i]);
$ok(EventStore::append($p,$target,'ssh',$rows)===200&&count($p->calls)===3,'200 events, three SQL executions');
$batch=json_decode($p->calls[2][1]['batch'],true);$ok($batch[0]['key']===hash('sha256','event-0')&&$batch[199]['message']==='message 199'&&str_contains($p->calls[2][0],'ORDER BY b.ord'),'ordered normalized batch');
$p->calls=[];$ok(EventStore::append($p,$target,'ssh',[$event,$event])===1,'deduplicated row count');
$p->calls=[];try{EventStore::append($p,$target,'ssh',[$event,array_replace($event,['severity'=>9])]);throw new RuntimeException('invalid accepted');}catch(InvalidArgumentException){$ok(count($p->calls)===2,'invalid batch no insert');}
$p->eventCount=100000;try{EventStore::append($p,$target,'ssh',[$event]);throw new RuntimeException('quota bypass');}catch(\Cajeer\Servers\Events\EventRejected){$ok(true,'quota preserved');}
$at=gmdate('Y-m-d\TH:i:s\Z');$p->calls=[];MetricStore::ingest($p,$target,[MetricBatch::sample('cpu',1)],$at);
$quota=static fn(array $call):bool=>str_contains($call[0],'SELECT count(*) FROM metric_series WHERE workspace_id');
$ok(count(array_filter($p->calls,$quota))===0,'steady state no quota count');
$p->missing=1;$p->calls=[];MetricStore::ingest($p,$target,[MetricBatch::sample('cpu',1)],$at);$ok(count(array_filter($p->calls,$quota))===1,'new series checks quota');
$ok(count((new SqlStatementParser())->split(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0064_performance_scalability.sql')))===9,'nine indexes parsed');
echo "Performance smoke: $n PASS (PDO double; real PostgreSQL benchmark separate).\n";
