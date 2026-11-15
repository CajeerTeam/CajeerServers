#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\{ConnectionFactoryInterface,DatabaseManager,SqlStatementParser};
use Cajeer\Servers\Inventory\{CmdbRepair,ObservationInput};
use Cajeer\Servers\Http\Exception\HttpException;

// Real service against a transactional PDO double; SQL enforcement is covered by PostgreSQL CI.
final class RepairPdo extends PDO {
    public array $rows=[],$calls=[],$audit=[];
    public bool $transaction=false,$failAudit=false;
    private array $saved=[];
    public function __construct(){}
    public function beginTransaction():bool {$this->transaction=true;$this->saved=[$this->rows,$this->audit];$this->calls[]='BEGIN';return true;}
    public function commit():bool {$this->transaction=false;$this->calls[]='COMMIT';return true;}
    public function rollBack():bool {[$this->rows,$this->audit]=$this->saved;$this->transaction=false;$this->calls[]='ROLLBACK';return true;}
    public function inTransaction():bool{return $this->transaction;}
    public function exec(string $sql):int|false {$this->calls[]=$sql;return 0;}
    public function prepare(string $sql,array $options=[]):PDOStatement|false {return new RepairStatement($this,$sql);}
    public function query(string $sql,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {$s=new RepairStatement($this,$sql);$s->execute();return $s;}
}
final class RepairStatement extends PDOStatement {
    private array $params=[];private int $count=0;
    public function __construct(private RepairPdo $pdo,private string $sql){}
    public function execute(?array $params=null):bool {
        $this->params=$params??[];$this->pdo->calls[]=$this->sql;
        if(str_starts_with($this->sql,'INSERT INTO inventory_field_provenance')){
            foreach($this->pdo->rows as $i=>$row)if($row['asset_id']===$this->params['id']&&$row['field']===$this->params['field']){
                unset($this->pdo->rows[$i]);$this->pdo->rows=array_values($this->pdo->rows);$this->count=1;break;
            }
        }
        if(str_starts_with($this->sql,'INSERT INTO audit_log')){
            if($this->pdo->failAudit)throw new RuntimeException('fixture audit failure');
            $this->pdo->audit[]=$this->params;
        }
        return true;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {return $this->pdo->rows;}
    public function fetchColumn(int $column=0):mixed {
        if(str_contains($this->sql,'cajeer_installation'))return '11111111-1111-4111-8111-111111111111';
        if(str_contains($this->sql,'workspaces'))return 1;
        throw new RuntimeException('Unexpected query');
    }
    public function rowCount():int{return $this->count;}
}
$n=0;$ok=static function(bool $v,string $label)use(&$n):void{if(!$v)throw new RuntimeException('FAIL '.$label);$n++;};
$throws=static function(callable $f,string $label)use($ok):void{try{$f();}catch(Throwable){$ok(true,$label);return;}throw new RuntimeException('FAIL '.$label);};
$ws='11111111-1111-4111-8111-111111111112';$asset='11111111-1111-4111-8111-111111111113';
$metadata=['attributes'=>['host_hardware'=>['count'=>2,'disks'=>[['name'=>'a','size'=>1],['name'=>'b','size'=>2]]]]];
$ok(!ObservationInput::fieldChanged($metadata,'attributes',['host_hardware'=>['disks'=>[['size'=>1,'name'=>'a'],['size'=>2,'name'=>'b']],'count'=>2]]),'JSONB object key order ignored recursively');
$ok(ObservationInput::fieldChanged($metadata,'attributes',['host_hardware'=>['count'=>'2','disks'=>$metadata['attributes']['host_hardware']['disks']]]),'metadata scalar type change detected');
$ok(ObservationInput::fieldChanged($metadata,'attributes',['host_hardware'=>['count'=>2,'disks'=>array_reverse($metadata['attributes']['host_hardware']['disks'])]]),'list order preserved');
$ok(!ObservationInput::fieldChanged(['attributes'=>['host_hardware'=>['value'=>1]]],'attributes',['host_hardware'=>['value'=>1.0]]),'JSON numeric serialization does not cause repeated writes');
$ok(ObservationInput::fieldChanged(['status'=>'online'],'status','offline'),'regular field changes preserved');
$event=['workspace_id'=>$ws,'external_id'=>'test','event_key'=>'test','type'=>'server','fields'=>['name'=>'test'],'observed_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-').'32T00:00:00Z'];
try{ObservationInput::normalize($event);throw new RuntimeException('invalid timestamp accepted');}catch(HttpException $e){$ok($e->status===422,'invalid calendar date rejected');}
$root=dirname(__DIR__);$sql=file_get_contents($root.'/migrations/pgsql/0062_database_data_integrity.sql');
$statements=(new SqlStatementParser())->split($sql);
$ok(count($statements)>=12,'migration parsed including PL/pgSQL bodies');
$ok(str_contains($sql,'EXCLUDE USING gist')&&str_contains($sql,'DEFERRABLE INITIALLY DEFERRED'),'exclusion and deferred checkpoint constraints present');
$pdo=new RepairPdo();$factory=new class($pdo) implements ConnectionFactoryInterface {public function __construct(private PDO $pdo){} public function make(array $config):PDO{return $this->pdo;}};
$db=new DatabaseManager(new ConfigRepository($root.'/config'),$factory,['driver'=>'pgsql']);$repair=new CmdbRepair($db,new AuditLogger($db));
$pdo->rows=[['asset_id'=>$asset,'version'=>3,'field'=>'name']];
$preview=$repair->preview($ws);
$ok($preview['missing']===1&&$preview['applied']===false&&strlen($preview['confirmation'])===64,'preview returns count and confirmation');
$ok(count($pdo->rows)===1&&$pdo->audit===[],'preview does not write');
$ok(in_array('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY',$pdo->calls,true),'read-only snapshot');
$pdo->rows[0]['version']=4;
$throws(fn()=>$repair->apply($ws,$preview['confirmation']),'changed asset version rejects stale plan');
$ok(count($pdo->rows)===1&&$pdo->audit===[]&&!$pdo->inTransaction(),'stale plan leaves rows intact');
$fresh=$repair->preview($ws);$pdo->failAudit=true;
$throws(fn()=>$repair->apply($ws,$fresh['confirmation']),'audit failure rolls back repair');
$ok(count($pdo->rows)===1&&!$pdo->inTransaction(),'repair rolled back on audit failure');
$pdo->failAudit=false;$result=$repair->apply($ws,$fresh['confirmation']);
$ok($result['repaired']===1&&$result['applied']&&$pdo->rows===[],'confirmed repair commits exact row');
$ok(count($pdo->audit)===1&&$pdo->audit[0]['action']==='inventory.integrity.repaired','repair audit committed');
$ok(in_array('LOCK TABLE inventory_assets, inventory_field_provenance IN SHARE ROW EXCLUSIVE MODE',$pdo->calls,true),'repair excludes concurrent SQL writers');
$throws(fn()=>$repair->apply($ws,$fresh['confirmation']),'old confirmation not reusable');
$throws(fn()=>$repair->preview('invalid'),'invalid workspace rejected before DB');
$throws(fn()=>$repair->apply($ws,'invalid'),'invalid confirmation rejected before DB');
$ok($repair->preview($ws)['missing']===0,'repaired state has no missing rows');
echo "Data integrity smoke: $n PASS (PDO double; PostgreSQL integration separate).\n";
