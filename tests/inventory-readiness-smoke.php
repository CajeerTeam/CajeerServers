#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactoryInterface;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Inventory\InventoryReadiness;

final class ReadinessPdo extends PDO
{
    public bool $tableExists = true;
    public bool $badData = false;
    public bool $missingColumn = false;
    public array $constraints = [];
    public array $executed = [];
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new ReadinessStmt($this, $query); }
    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;
        if (preg_match('/^ALTER TABLE inventory_assets VALIDATE CONSTRAINT ([a-z_]+)$/D', $statement, $matches)) {
            if (!array_key_exists($matches[1], $this->constraints)) throw new RuntimeException('Unknown constraint');
            $this->constraints[$matches[1]] = true;
            return 0;
        }
        throw new RuntimeException('Unsafe SQL executed');
    }
}
final class ReadinessStmt extends PDOStatement
{
    public function __construct(private readonly ReadinessPdo $pdo, private readonly string $sql) {}
    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->sql, 'to_regclass')) return $this->pdo->tableExists ? 'inventory_assets' : null;
        throw new RuntimeException('Unexpected PDOStatement::fetchColumn');
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if (str_contains($this->sql, 'information_schema.columns')) {
            $cols = ['id'=>'uuid','type'=>'varchar','source'=>'varchar','external_id'=>'varchar','name'=>'varchar',
                'status'=>'varchar','os_family'=>'varchar','ip_addresses'=>'jsonb','tags'=>'jsonb','attributes'=>'jsonb',
                'created_at'=>'timestamptz','updated_at'=>'timestamptz'];
            if ($this->pdo->missingColumn) unset($cols['tags']);
            return $cols;
        }
        if (str_contains($this->sql, 'FROM pg_constraint')) {
            return array_map(static fn(string $key, bool $valid): array => ['conname'=>$key,'convalidated'=>$valid],
                array_keys($this->pdo->constraints), array_values($this->pdo->constraints));
        }
        throw new RuntimeException('Unexpected PDOStatement::fetchAll');
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'FROM inventory_assets')) {
            return ['total'=>2,'name_blank'=>$this->pdo->badData ? 1 : 0,
                'type_blank'=>0,'source_blank'=>0,'status_blank'=>0,'external_id_blank'=>0,
                'ip_not_array'=>0,'tags_not_array'=>0,'attributes_not_object'=>0];
        }
        throw new RuntimeException('Unexpected PDOStatement::fetch');
    }
}

$checkCount=0;
$check=static function(bool $ok,string $name) use (&$checkCount):void {
    if (!$ok) throw new RuntimeException('FAIL: '.$name);
    $checkCount++;
};
$throws=static function(callable $fn,string $name) use($check):void {
    try {$fn();} catch (Throwable) {$check(true,$name);return;}
    $check(false,$name);
};
$temp=sys_get_temp_dir().'/cajeer-inventory-readiness-'.bin2hex(random_bytes(5));
mkdir($temp,0700,true);
try {
    file_put_contents($temp.'/database.php', "<?php return ['default'=>'pgsql','connections'=>['pgsql'=>['driver'=>'pgsql']]];");
    $pdo=new ReadinessPdo();
    $factory=new class($pdo) implements ConnectionFactoryInterface {
        public function __construct(private readonly PDO $pdo) {}
        public function make(array $config): PDO {return $this->pdo;}
    };
    $db=new DatabaseManager(new ConfigRepository($temp),$factory);
    $service=new InventoryReadiness($db);
    $pdo->tableExists=false;
    $throws(fn()=>$service->inspect(),'missing inventory table is refused');
    $pdo->tableExists=true;
    $pdo->missingColumn=true;
    $report=$service->inspect();
    $check(!$report['ok'] && $report['rows']===0,'schema drift returns failure instead of scanning wrong table');
    $pdo->missingColumn=false;
    $report=$service->inspect();
    $check($report['ok'] && $report['rows']===2,'reads existing data without modifications');
    $check(!isset($report['rows'][0]) && !str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'secret'),'only aggregate counts are returned');
    $check(!$service->inspect(true)['ok'],'strict readiness needs real validated constraints');
    $pdo->badData=true;
    $check(!$service->inspect()['ok'],'legacy invalid values block readiness');
    $throws(fn()=>$service->validateConstraints(),'dirty records refuse validation');
    $check($pdo->executed===[],'dirty records cannot cause any ALTER TABLE');
    $pdo->badData=false;
    $throws(fn()=>$service->validateConstraints(),'missing SQL migration fails closed');
    $pdo->constraints=array_fill_keys([
        'inventory_assets_name_nonblank','inventory_assets_type_nonblank','inventory_assets_source_nonblank',
        'inventory_assets_status_nonblank','inventory_assets_external_id_nonblank','inventory_assets_ip_array',
        'inventory_assets_tags_array','inventory_assets_attributes_object',
    ],false);
    $result=$service->validateConstraints();
    $check(count($result['validated'])===8,'eight real SQL validation operations');
    $check($service->inspect(true)['ok'],'valid constraints and data satisfy strict gate');
    $check(count($pdo->executed)===8,'exact validation SQL count');
    foreach ($pdo->executed as $sql) $check(preg_match('/^ALTER TABLE inventory_assets VALIDATE CONSTRAINT inventory_assets_[a-z_]+$/D',$sql)===1,'whitelisted SQL identifiers only');
    $check($service->validateConstraints()['validated']===[],'revalidation is idempotent');
    $migration=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0008_inventory_readiness.sql');
    $sql=(new SqlStatementParser())->split($migration);
    $check(count($sql)===12,'migration SQL parses into eight guards and four indexes');
    $check(count(array_filter($sql,fn($s)=>str_contains($s,'NOT VALID')) )===8,'legacy rows protected by NOT VALID');
    $check(!preg_match('/\b(?:DELETE|UPDATE|TRUNCATE|DROP)\s+(?:FROM\s+)?inventory_assets\b/i',implode("\n",$sql)),'migration never rewrites or deletes legacy records');
    echo "Inventory readiness smoke: $checkCount PASS\n";
} finally {
    @unlink($temp.'/database.php');@rmdir($temp);
}
