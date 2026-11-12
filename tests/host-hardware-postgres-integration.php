#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Disposable PostgreSQL integration: migration, hardware sync, manual metadata and rollback. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Connector\{ConnectorBindingService,HostObservation,HostTransport,EndpointPolicy,LinuxConnector,ReconciliationService};
use Cajeer\Servers\Database\{ConnectionFactory,DatabaseManager,MigrationRunner};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Inventory\{InventoryRepository,InventoryService,HistoryService};
use Cajeer\Servers\Http\Exception\HttpException;
if(getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')){fwrite(STDERR,"REFUSED: disposable cajeer_test_* PostgreSQL required.\n");exit(2);}
if(!extension_loaded('pdo_pgsql')){fwrite(STDERR,"FAIL: pdo_pgsql required.\n");exit(1);}
$root=dirname(__DIR__);$schema='cajeer_host_'.bin2hex(random_bytes(8));$dir=sys_get_temp_dir().'/'.$schema;
mkdir($dir,0700);$pdo=null;$created=false;$failed=false;$n=0;
$ok=static function(bool $v,string $label)use(&$n):void{if(!$v)throw new RuntimeException('FAIL: '.$label);$n++;};
try{
    $db=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),['driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1','port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432','database'=>getenv('CAJEER_PG_TEST_DATABASE'),'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer','password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'','sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer']);
    $pdo=$db->connection();$ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PG17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');$pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;$pdo->exec('SET search_path TO "'.$schema.'", public');
    foreach(glob($root.'/migrations/pgsql/*.sql') as $file)if(!str_starts_with(basename($file),'0025_'))copy($file,$dir.'/'.basename($file));
    $runner=new MigrationRunner($db,$dir);$runner->run();
    $actor=(string)$pdo->query("INSERT INTO users(email,name,password_hash) VALUES('host@example.invalid','Host','test-only') RETURNING id::text")->fetchColumn();
    $ws=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default'")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:id,'owner')")->execute(['ws'=>$ws,'id'=>$actor]);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$inventory=new InventoryService($db,$repo,$audit);$history=new HistoryService($db,$repo,$inventory,$audit);
    $manual=$inventory->create($actor,['workspace_id'=>$ws,'type'=>'server','name'=>'existing-linux','attributes'=>['owner_note'=>'retain me']]);
    $protected=$inventory->create($actor,['workspace_id'=>$ws,'type'=>'server','name'=>'manual-hardware','attributes'=>['host_hardware'=>['cpu_count'=>99],'owner_note'=>'keep']]);
    copy($root.'/migrations/pgsql/0025_connector_host_hardware.sql',$dir.'/0025_connector_host_hardware.sql');
    $ok(count($runner->run()['applied'])===1&&$runner->run()['applied']===[],'0025 migration idempotent');
    $driver=new LinuxConnector(new HostTransport(new EndpointPolicy()));$bindings=new ConnectorBindingService($db,$repo,$audit,new ConnectorRegistry($driver));
    $target=$bindings->create($actor,['workspace_id'=>$ws,'provider'=>'ssh','name'=>'host source','endpoint_host'=>'linux.test','endpoint_port'=>22]);
    $target+=['credential_id'=>'11111111-1111-4111-8111-111111111111','credential_version'=>1,'purpose'=>'ssh'];
    $service=new ReconciliationService($repo,$history,$audit);
    $data=['identity'=>'aabbccddeeff00112233445566778899','name'=>'new-linux','os_family'=>'debian','os_name'=>'Debian','os_version'=>'12','cpu_count'=>4,'memory_bytes'=>8589934592,'disks'=>[['name'=>'sda','size_bytes'=>1000000000]],'interfaces'=>[['name'=>'eth0','addresses'=>['192.0.2.10']]]];
    $snapshot=fn(array $d)=>HostObservation::result($d,'ssh','sync')->validated($driver->manifest());
    $now=fn()=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    $apply=function(array $d)use($db,$pdo,$repo,$service,$actor,$target,$snapshot,$now):array{
        $gen=$service->generation($pdo);$when=$now();return $db->transaction(function(PDO $pdo)use($repo,$service,$actor,$target,$snapshot,$d,$gen,$when):array{$repo->lockWorkspace($pdo,$target['workspace_id']);return $service->apply($pdo,$actor,$target,$snapshot($d),$gen,$when,null);});
    };
    $rows=fn()=>array_column($service->resources($pdo,$actor,$target,100,0),null,'external_id');
    $external=fn(array $d)=>$snapshot($d)->items[0]['external_id'];
    $resolve=function(array $d,string $asset)use($db,$repo,$service,$actor,$target,$rows,$external):void{$r=$rows()[$external($d)];$db->transaction(function(PDO $pdo)use($repo,$service,$actor,$target,$r,$asset):void{$repo->lockWorkspace($pdo,$target['workspace_id']);$service->resolve($pdo,$actor,$target,$r['external_id'],$r['last_generation'],$asset,null);});};
    $apply($data);$id=$rows()[$external($data)]['asset_id'];$asset=$repo->find($pdo,$actor,$id);
    $ok($asset['attributes']['host_hardware']['cpu_count']===4&&$asset['ip_addresses']===['192.0.2.10'],'new host persisted');
    $data['cpu_count']=8;$data['name']='renamed-linux';$apply($data);$asset=$repo->find($pdo,$actor,$id);
    $ok($rows()[$external($data)]['asset_id']===$id&&$asset['attributes']['host_hardware']['cpu_count']===8,'same UUID hardware update');
    $repeat=$apply($data);$ok($repeat['outcomes']['unchanged']===1&&$repeat['outcomes']['created']===0,'JSONB order no repeated updates');
    $link=array_replace($data,['identity'=>'11111111222233334444555566667777','name'=>'existing-linux']);$apply($link);$resolve($link,$manual['id']);$apply($link);
    $asset=$repo->find($pdo,$actor,$manual['id']);$ok($asset['attributes']['owner_note']==='retain me'&&$asset['attributes']['host_hardware']['cpu_count']===8,'merge preserves other manual attributes');
    $link['cpu_count']=12;$apply($link);$ok($repo->find($pdo,$actor,$manual['id'])['attributes']['host_hardware']['cpu_count']===12,'linked hardware updates');
    $link2=array_replace($data,['identity'=>'99999999222233334444555566667777','name'=>'manual-hardware']);$apply($link2);$resolve($link2,$protected['id']);$apply($link2);
    $asset=$repo->find($pdo,$actor,$protected['id']);$ok($asset['attributes']['host_hardware']['cpu_count']===99&&$asset['attributes']['owner_note']==='keep','manual hardware untouched');
    $conflicts=$history->conflicts($actor,$protected['id'])['data'];$ok(in_array('attributes',array_column($conflicts,'field'),true),'hardware conflict recorded');
    try{$history->observe($actor,['workspace_id'=>$ws,'external_id'=>'untrusted','event_key'=>'untrusted','type'=>'server','fields'=>$snapshot($data)->items[0]['fields']]);throw new RuntimeException('public attributes accepted');}catch(HttpException $e){$ok($e->status===422,'public observation cannot submit hardware');}
    $gen=$service->generation($pdo);$when=$now();$data['cpu_count']=16;
    try{$db->transaction(function(PDO $pdo)use($repo,$service,$actor,$target,$snapshot,$data,$gen,$when):void{$repo->lockWorkspace($pdo,$target['workspace_id']);$service->apply($pdo,$actor,$target,$snapshot($data),$gen,$when,null);throw new RuntimeException('rollback');});}catch(RuntimeException $e){if($e->getMessage()!=='rollback')throw $e;}
    $ok($repo->find($pdo,$actor,$id)['attributes']['host_hardware']['cpu_count']===8,'rollback preserves hardware');
    $ok($runner->validate()['valid'],'migration checksums');echo "Host hardware PostgreSQL: $n PASS\n";
}catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");}
finally{if($created&&$pdo instanceof PDO){$pdo->exec('SET search_path TO public');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}foreach(glob($dir.'/*.sql') as $f)unlink($f);rmdir($dir);}
exit($failed?1:0);
