#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Disposable, migrated PostgreSQL benchmark. No credentials or customer data in the report. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\{ConnectionFactory,DatabaseManager,MigrationRunner};
use Cajeer\Servers\Inventory\{InventoryRepository,InventoryService,HistoryService};
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Connector\ConnectorBindingService;
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Events\EventStore;
use Cajeer\Servers\Metrics\{MetricBatch,MetricStore};

function benchDb(): DatabaseManager {
    return new DatabaseManager(new ConfigRepository(dirname(__DIR__).'/config'),new ConnectionFactory(),[
        'driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1','port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432',
        'database'=>getenv('CAJEER_PG_TEST_DATABASE'),'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer',
        'password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'','sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer',
    ]);
}
function benchInt(string $value,int $min,int $max):int {
    if(!preg_match('/^[0-9]{1,8}$/D',$value)||($n=(int)$value)<$min||$n>$max)throw new InvalidArgumentException('Invalid benchmark numeric argument.');return $n;
}
function benchCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function benchScope(PDO $pdo,string $schema):void {
    if(!preg_match('/^cajeer_perf_[a-f0-9]{16}$/D',$schema))throw new InvalidArgumentException('Invalid benchmark schema.');
    $pdo->exec('SET search_path TO "'.$schema.'", public');
    $pdo->exec("SET statement_timeout='120s'");
}
function benchPct(array $values,float $p):float {sort($values,SORT_NUMERIC);return round($values[max(0,(int)ceil(count($values)*$p)-1)],3);}
function benchWorker(string $schema,int $iterations):array {
    $db=benchDb();$p=$db->connection();benchScope($p,$schema);$p->exec('SET default_transaction_read_only=on');
    $ws=$p->query("SELECT id::text FROM workspaces WHERE slug='default'")->fetchColumn();
    $actor=$p->query("SELECT id::text FROM users WHERE email='perf@example.invalid'")->fetchColumn();
    $target=$p->query("SELECT id::text,workspace_id::text FROM connector_targets ORDER BY name LIMIT 1")->fetch();
    $series=$p->query("SELECT id::text FROM metric_series WHERE target_id=".$p->quote($target['id'])."::uuid LIMIT 1")->fetchColumn();
    $repo=new InventoryRepository();$to=gmdate('Y-m-d\TH:i:s\Z');$from=gmdate('Y-m-d\TH:i:s\Z',time()-2*86400);
    $first=$repo->page($p,$actor,$ws,50,0,null,null,null,false,[],false);$cursor=$first['next_cursor'];
    benchCheck(is_string($cursor),'Fixture must have a second page.');
    $deep=$p->query('SELECT created_at::text,id::text FROM inventory_assets WHERE workspace_id='.$p->quote($ws).'::uuid ORDER BY created_at ASC,id ASC OFFSET 50 LIMIT 1')->fetch();
    $deepCursor=InventoryRepository::encodeCursor($deep['created_at'],$deep['id']);
    $audit=new AuditLogger($db);$history=new HistoryService($db,$repo,new InventoryService($db,$repo,$audit),$audit);
    $bindings=new ConnectorBindingService($db,$repo,$audit,new ConnectorRegistry());
    $asset=$first['data'][0]['id'];
    $cases=[
        'inventory_exact'=>fn()=>$repo->page($p,$actor,$ws,50,0,null,null,null,false),
        'inventory_no_count'=>fn()=>$repo->page($p,$actor,$ws,50,0,null,null,null,false,[],false),
        'inventory_deep_keyset'=>fn()=>$repo->page($p,$actor,$ws,50,0,null,null,null,false,['cursor'=>$deepCursor],false),
        'inventory_keyset'=>fn()=>$repo->page($p,$actor,$ws,50,0,null,null,null,false,['cursor'=>$cursor],false),
        'inventory_name'=>fn()=>$repo->page($p,$actor,$ws,50,0,null,null,null,false,['sort'=>'name_asc'],false),
        'inventory_search'=>fn()=>$repo->page($p,$actor,$ws,50,0,null,null,'perf-00000001',false,[],false),
        'dashboard_summary'=>fn()=>$bindings->summary($actor,$ws,null,'ssh'),
        'history_page'=>fn()=>$history->revisions($actor,$asset,30),
        'metrics_avg'=>fn()=>MetricStore::query($p,$target,$series,$from,$to,'avg',120),
        'events_page'=>fn()=>EventStore::read($p,$target,50,null,5,''),
    ];
    $samples=[];
    foreach($cases as $name=>$call){$call();$samples[$name]=[];} // warm up each read path
    $start=hrtime(true);
    for($i=0;$i<$iterations;$i++)foreach($cases as $name=>$call){$t=hrtime(true);$r=$call();$samples[$name][]=(hrtime(true)-$t)/1e6;benchCheck(is_array($r)&&($name==='metrics_avg'?count($r['points'])>0:($name==='events_page'?count($r['events'])>0:($name==='dashboard_summary'?$r['total']>0:count($r['data'])>0))),'Empty or invalid query result: '.$name);}
    return ['samples'=>$samples,'seconds'=>(hrtime(true)-$start)/1e9,'peak_php_bytes'=>memory_get_peak_usage(true)];
}
if(getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')||!extension_loaded('pdo_pgsql')){
    fwrite(STDERR,"REFUSED: disposable cajeer_test_* database, CAJEER_PG_TEST=1 and pdo_pgsql required.\n");exit(2);
}
$pdo=null;$schema=null;$created=false;$children=[];$tmp=null;$exit=0;
try{
    if(($argv[1]??null)==='--worker'){
        if(count($argv)!==4)throw new InvalidArgumentException('Invalid worker arguments.');
        echo json_encode(benchWorker($argv[2],benchInt($argv[3],1,10000)),JSON_THROW_ON_ERROR)."\n";exit(0);
    }
    $options=['assets'=>1000,'targets'=>10,'samples'=>10000,'events'=>1000,'clients'=>2,'iterations'=>10,'p95-ms'=>0];
    foreach(array_slice($argv,1) as $arg){if(!preg_match('/^--([a-z0-9-]+)=([0-9]+)$/D',$arg,$m)||!array_key_exists($m[1],$options))throw new InvalidArgumentException('Unknown benchmark option.');$options[$m[1]]=benchInt($m[2],$m[1]==='p95-ms'?0:1,match($m[1]){'assets'=>1000000,'targets'=>10000,'samples'=>5000000,'events'=>1000000,'clients'=>100,'iterations'=>10000,'p95-ms'=>60000});}
    ['assets'=>$assets,'targets'=>$targets,'samples'=>$samples,'events'=>$events,'clients'=>$clients,'iterations'=>$iterations,'p95-ms'=>$budget]=$options;
    benchCheck($clients*$iterations<=100000&&$assets>=101&&$targets<=$assets&&$samples>=$targets&&ceil($samples/$targets)<=86400&&$events>=$targets&&ceil($events/$targets)<=99000,'Invalid fixture cardinalities.');
    $db=benchDb();$pdo=$db->connection();benchCheck((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+ required.');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $schema='cajeer_perf_'.bin2hex(random_bytes(8));$pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;benchScope($pdo,$schema);
    (new MigrationRunner($db,dirname(__DIR__).'/migrations/pgsql'))->run();
    $ws=$pdo->query("SELECT id::text FROM workspaces WHERE slug='default'")->fetchColumn();$w=$pdo->quote($ws).'::uuid';
    $actor=$pdo->query("INSERT INTO users(email,name,password_hash) VALUES('perf@example.invalid','Benchmark','not-a-login') RETURNING id::text")->fetchColumn();
    $pdo->exec("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES($w,".$pdo->quote($actor)."::uuid,'owner')");
    // Real schema/triggers, no disabled integrity checks. Seed deterministic names in batches.
    $seedStart=hrtime(true);
    for($offset=0;$offset<$assets;$offset+=1000){$end=min($assets,$offset+1000);$pdo->exec("INSERT INTO inventory_assets(workspace_id,type,source,name,status) SELECT $w,'server','manual','perf-'||lpad(g::text,8,'0'),'online' FROM generate_series(".($offset+1).",$end) g");}
    $pdo->exec("INSERT INTO connector_targets(workspace_id,provider,name,endpoint_host,endpoint_port,asset_id) SELECT $w,'ssh','perf-target-'||name,'192.0.2.1',22,id FROM inventory_assets WHERE workspace_id=$w ORDER BY name LIMIT $targets");
    $identity=hash('sha256',json_encode(['perf_cpu','',[]],JSON_THROW_ON_ERROR));
    $pdo->exec("INSERT INTO metric_series(workspace_id,target_id,asset_id,target_version,identity,metric,labels,kind) SELECT workspace_id,id,asset_id,version,'$identity','perf_cpu','{}','gauge' FROM connector_targets");
    $per=intdiv($samples,$targets);$extra=$samples%$targets;$pdo->exec("INSERT INTO metric_samples(series_id,observed_at,value) SELECT s.id,statement_timestamp()-interval '1 hour'-g*interval '1 second',g%100 FROM (SELECT ms.id,row_number() OVER(ORDER BY t.name) AS rn FROM metric_series ms JOIN connector_targets t ON t.id=ms.target_id) s CROSS JOIN LATERAL generate_series(1,$per+CASE WHEN s.rn<=$extra THEN 1 ELSE 0 END) g");
    $perEvent=intdiv($events,$targets);$extraEvent=$events%$targets;$pdo->exec("INSERT INTO system_events(target_id,workspace_id,source,event_key,channel,severity,hostname,application,message) SELECT t.id,t.workspace_id,'ssh',encode(digest('seed-'||g::text,'sha256'),'hex'),'journal',5,'perf','benchmark','seed '||g FROM (SELECT id,workspace_id,row_number() OVER(ORDER BY name) AS rn FROM connector_targets) t CROSS JOIN LATERAL generate_series(1,$perEvent+CASE WHEN t.rn<=$extraEvent THEN 1 ELSE 0 END) g");
    $target=$pdo->query("SELECT id::text,workspace_id::text,asset_id::text,version,provider FROM connector_targets ORDER BY name LIMIT 1")->fetch();
    $at=gmdate('Y-m-d\TH:i:s\Z',time()-30);$metric=[MetricBatch::sample('perf_cpu',42)];
    $insert=$db->transaction(fn($p)=>MetricStore::ingest($p,$target,$metric,$at));
    $duplicate=$db->transaction(fn($p)=>MetricStore::ingest($p,$target,$metric,$at));
    benchCheck($insert['inserted']===1&&$duplicate['deduplicated']===1,'Metric ingestion idempotency failed.');
    $batch=[];for($i=0;$i<200;$i++)$batch[]=['key'=>'batch-'.$i,'channel'=>'journal','severity'=>5,'message'=>'batch '.$i];
    $t=hrtime(true);$added=$db->transaction(fn($p)=>EventStore::append($p,$target,'ssh',$batch));$batchMs=(hrtime(true)-$t)/1e6;
    benchCheck($added===200&&$db->transaction(fn($p)=>EventStore::append($p,$target,'ssh',$batch))===0,'Event batch idempotency failed.');
    $page=EventStore::read($pdo,$target,2,null,5,'batch ');benchCheck($page['events'][0]['message']==='batch 199'&&$page['events'][1]['message']==='batch 198','Event ordering failed.');
    $repo=new InventoryRepository();$a=$repo->page($pdo,$actor,$ws,50,0,null,null,null,false);$b=$repo->page($pdo,$actor,$ws,50,0,null,null,null,false,[],false);
    benchCheck($a['total']===$assets&&$b['total']===null&&$a['data']===$b['data'],'Inventory count-free equivalence failed.');
    $next=$repo->page($pdo,$actor,$ws,50,0,null,null,null,false,['cursor'=>$b['next_cursor']],false);
    benchCheck(count($next['data'])===50&&!array_intersect(array_column($a['data'],'id'),array_column($next['data'],'id')),'Keyset page overlaps.');
    foreach(['inventory_assets','connector_targets','metric_series','metric_samples','system_events'] as $table)$pdo->exec('ANALYZE '.$table);
    $seedSeconds=(hrtime(true)-$seedStart)/1e9;
    $settings=$pdo->query("SELECT name,setting,unit FROM pg_settings WHERE name IN ('server_version','shared_buffers','work_mem','max_connections','effective_cache_size') ORDER BY name")->fetchAll();
    $tmp=sys_get_temp_dir().'/'.$schema;benchCheck(mkdir($tmp,0700),'Cannot create benchmark report directory.');
    $start=hrtime(true);
    for($i=0;$i<$clients;$i++){
        $proc=proc_open([PHP_BINARY,__FILE__,'--worker',$schema,(string)$iterations],[0=>['file','/dev/null','r'],1=>['file',"$tmp/$i.json",'w'],2=>['file',"$tmp/$i.err",'w']],$pipes);
        benchCheck(is_resource($proc),'Cannot start benchmark worker.');$children[$i]=$proc;
    }
    $all=[];$peak=0;
    foreach($children as $i=>$proc){$status=proc_close($proc);unset($children[$i]);benchCheck($status===0,'Benchmark worker failed: '.$i);$r=json_decode(file_get_contents("$tmp/$i.json"),true,32,JSON_THROW_ON_ERROR);$peak=max($peak,$r['peak_php_bytes']);foreach($r['samples'] as $case=>$latencies)foreach($latencies as $v)$all[$case][]=$v;}
    $wall=(hrtime(true)-$start)/1e9;$stats=[];$total=0;$passed=true;
    foreach($all as $case=>$values){$total+=count($values);$p95=benchPct($values,.95);$stats[$case]=['requests'=>count($values),'p50_ms'=>benchPct($values,.50),'p95_ms'=>$p95,'p99_ms'=>benchPct($values,.99),'max_ms'=>round(max($values),3)];if($budget>0&&$p95>$budget)$passed=false;}
    echo json_encode(['version'=>trim(file_get_contents(dirname(__DIR__).'/VERSION')),'profile'=>$options,'seed_seconds'=>round($seedSeconds,3),'batch_200_ms'=>round($batchMs,3),'wall_seconds'=>round($wall,3),'queries_per_second'=>round($total/$wall,2),'peak_worker_php_bytes'=>$peak,'php'=>PHP_VERSION,'host'=>php_uname('s').' '.php_uname('m'),'logical_cpus'=>is_readable('/proc/cpuinfo')?preg_match_all('/^processor\s*:/m',file_get_contents('/proc/cpuinfo')):null,'host_memory_kib'=>is_readable('/proc/meminfo')&&preg_match('/^MemTotal:\s*(\d+)/m',file_get_contents('/proc/meminfo'),$memory)?(int)$memory[1]:null,'postgres'=>$settings,'cases'=>$stats,'passed'=>$passed],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    if(!$passed)$exit=1;
}catch(Throwable $e){fwrite(STDERR,'Benchmark failed: '.$e::class.' ('.$e->getCode().")\n");$exit=1;}
finally{
    foreach($children as $proc){proc_terminate($proc);proc_close($proc);}
    if($pdo!==null&&$schema!==null&&$created)try{$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable){$exit=1;fwrite(STDERR,"Benchmark schema cleanup failed.\n");}
    if($tmp!==null&&is_dir($tmp)){foreach(glob($tmp.'/*')?:[] as $file)unlink($file);rmdir($tmp);}
}
exit($exit);
