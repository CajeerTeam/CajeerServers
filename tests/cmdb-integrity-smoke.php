#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactoryInterface;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Inventory\CmdbIntegrity;
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Runtime\HttpRequest;

final class CmdbPdo extends PDO
{
    public bool $transaction = false;
    public array $operations = [];
    public array $tables = [];
    public array $counts = [];
    public function __construct() {}
    public function beginTransaction(): bool
    {
        if ($this->transaction) throw new RuntimeException('Nested transaction');
        $this->transaction=true;$this->operations[]='BEGIN';return true;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function rollBack(): bool
    {
        $this->operations[]='ROLLBACK';$this->transaction=false;return true;
    }
    public function exec(string $statement): int|false
    {
        if (!in_array($statement,[
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY',
            "SET LOCAL statement_timeout = '5000ms'",
        ],true))throw new RuntimeException('Unexpected write or session mutation');
        $this->operations[]=$statement;return 0;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (!$this->transaction) throw new RuntimeException('Query outside read-only snapshot');
        if (!str_contains($query,'SELECT ') && !str_contains($query,'WITH RECURSIVE ')) throw new RuntimeException('Non-read-only SQL');
        $this->operations[]='QUERY';return new CmdbStatement($this,$query);
    }
}
final class CmdbStatement extends PDOStatement
{
    public function __construct(private readonly CmdbPdo $pdo, private readonly string $sql) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if (!str_contains($this->sql,'FROM pg_class')) throw new RuntimeException('Unexpected fetchAll');
        return $this->pdo->tables;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        if (!str_contains($this->sql,'count(*)')) throw new RuntimeException('Unexpected fetchColumn');
        foreach ($this->pdo->counts as $needle => $value) {
            if (str_contains($this->sql, $needle)) return $value;
        }
        return '0';
    }
}

$checks=0;
$assert=static function(bool $value,string $description)use(&$checks):void{
    if(!$value)throw new RuntimeException('FAIL: '.$description);
    $checks++;
};
$throws=static function(callable $f,string $reason)use($assert):void{
    try{$f();}catch(Throwable){$assert(true,$reason);return;}
    throw new RuntimeException('FAIL: '.$reason);
};
$temp=sys_get_temp_dir().'/cajeer-cmdb-test-'.bin2hex(random_bytes(7));
mkdir($temp,0700,true);
try {
    file_put_contents($temp.'/database.php',"<?php return ['default'=>'pgsql','connections'=>['pgsql'=>['driver'=>'pgsql']]];");
    $pdo=new CmdbPdo();
    $factory=new class($pdo) implements ConnectionFactoryInterface {
        public function __construct(private readonly PDO $pdo) {}
        public function make(array $config): PDO{return $this->pdo;}
    };
    $database=new DatabaseManager(new ConfigRepository($temp),$factory);
    $service=new CmdbIntegrity($database);
    $throws(fn()=>$service->inspect(),'absent migration tables are rejected');
    $assert(!$pdo->inTransaction(),'missing schema always rolls back');
    $migration=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0016_cmdb_stability.sql');
    $statements=(new SqlStatementParser())->split($migration);
    $assert(count($statements)===5,'new migration parses five executable SQL statements');
    $assert(str_contains($migration,'inventory_manual_provenance')&&str_contains($migration,'inventory_cmdb_active_interface_guard'),'database provenance and IPAM guards are executable');
    $assert(!preg_match('/\b(?:DELETE|TRUNCATE|DROP)\s+(?:FROM\s+)?inventory_assets\b/i',$migration),'no destructive legacy-asset migration');
    $source=file_get_contents(dirname(__DIR__).'/core/Inventory/CmdbIntegrity.php');
    preg_match('/\\$required = \\[(.*?)\\];/s',$source,$match);
    preg_match_all('/\x27([a-z_]+)\x27/',$match[1]??'',$names);
    $pdo->tables=$names[1];
    $result=$service->inspect();
    $assert($result['ok']===true,'clean dataset passes');
    $assert(count($result['checks'])>=14,'all CMDB layers checked');
    $assert(isset($result['totals']['assets'],$result['totals']['relationships'],$result['totals']['import_batches']),'counts exist');
    $assert(!$pdo->inTransaction(),'read-only inspection ends with rollback');
    $assert(!in_array('COMMIT',$pdo->operations,true),'never commits transaction');
    $pdo->counts=['FROM inventory_assets a WHERE a.owner_user_id'=>3];
    $result=$service->inspect();
    $assert(!$result['ok'],'violated owner invariant blocks CMDB');
    $failures=array_filter($result['checks'],static fn($check)=>!$check['ok']);
    $assert(count($failures)===1,'only violated constraint fails');
    $assert(reset($failures)['count']===3,'database count preserved without resource contents');
    $json=json_encode($result,JSON_THROW_ON_ERROR);
    $assert(!str_contains($json,'192.0.2')&&!str_contains($json,'password'),'only aggregates exposed');
    $pdo->counts=[];
    $deep=$service->inspect(true);
    $assert($deep['ok'] && count($deep['checks'])>count($result['checks']),'optional cycle inspection executes');
    $pdo->counts=['FROM inventory_assets a WHERE a.owner_user_id'=>'oops'];
    $throws(fn()=>$service->inspect(),'non-numeric count is refused');
    $assert(!$pdo->inTransaction(),'invalid query result rolls back');
    $pdo->counts=[];
    $pdo->beginTransaction();
    $throws(fn()=>$service->inspect(),'nested inspection refused');
    $pdo->rollBack();
    $previous=getenv('APP_ENV');putenv('APP_ENV=local');
    $app=(new Application(dirname(__DIR__)))->boot();
    try{
        $routes=$app->container()->get(Router::class)->routes();
        $assert(in_array('/api/v1/admin/cmdb-integrity',array_column($routes,'path'),true),'admin API registered');
        $resp=$app->handle(new HttpRequest('GET','/api/v1/admin/cmdb-integrity'));
        $assert(in_array($resp->status,[401,503],true),'anonymous request cannot inspect CMDB');
        $response=$app->handle(new HttpRequest('POST','/api/v1/admin/cmdb-integrity'));
        $assert(in_array($response->status,[404,405,503],true),'CMDB endpoint remains read only');
    }finally{$app->terminate();if($previous===false)putenv('APP_ENV');else putenv('APP_ENV='.$previous);}
    echo "CMDB integrity smoke: $checks PASS\n";
}finally{@unlink($temp.'/database.php');@rmdir($temp);}
