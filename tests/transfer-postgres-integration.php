#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Real isolated PostgreSQL 17+/18 integration, never runs against production databases. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\CredentialBindingService;
use Cajeer\Servers\Credentials\CredentialStore;
use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Credentials\CredentialsVault;
use Cajeer\Servers\Inventory\HistoryService;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\InventoryService;
use Cajeer\Servers\Inventory\TransferService;
use Cajeer\Servers\Queue\PostgresQueueDriver;
use Cajeer\Servers\Queue\JobProcessor;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;
use Cajeer\Servers\Storage\LocalDisk;
use Cajeer\Servers\Security\Rbac;
if(getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')){
    fwrite(STDERR,"REFUSED: CAJEER_PG_TEST=1 and isolated cajeer_test_* database required.\n");exit(2);
}
if(!extension_loaded('pdo_pgsql')){fwrite(STDERR,"FAIL: pdo_pgsql required.\n");exit(1);}
$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/cajeer-transfer-'.bin2hex(random_bytes(8));
$schema='cajeer_transfer_'.bin2hex(random_bytes(8));$pdo=null;$created=false;$failed=false;$checks=0;
mkdir($dir.'/migrations',0700,true);
$ok=static function(bool $value,string $label)use(&$checks):void{if(!$value)throw new RuntimeException('FAIL: '.$label);$checks++;};
$reject=static function(int $expected,callable $call,string $label)use($ok):void{
    try{$call();}catch(HttpException $e){$ok($e->status===$expected,$label);return;}
    throw new RuntimeException('Expected HTTP '.$expected.': '.$label);
};
try{
    $db=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),[
        'driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1','port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432',
        'database'=>getenv('CAJEER_PG_TEST_DATABASE'),'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer',
        'password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'','sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer',
    ]);
    $pdo=$db->connection();$ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;
    $pdo->exec('SET search_path TO "'.$schema.'", public');
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file)copy($file,$dir.'/migrations/'.basename($file));
    $runner=new MigrationRunner($db,$dir.'/migrations');
    $ok(count($runner->run()['applied'])===count(glob($root.'/migrations/pgsql/*.sql')),'all migrations applied');
    $ok($runner->run()['applied']===[]&&$runner->validate()['valid'],'migration replay and checksums');
    $seed=$pdo->prepare('INSERT INTO users(email,name,password_hash) VALUES(:email,:name,:hash) RETURNING id::text');
    $user=static function(string $name)use($seed):string{
        $seed->execute(['email'=>$name.bin2hex(random_bytes(4)).'@example.invalid','name'=>$name,
            'hash'=>password_hash('test-only-password#64',PASSWORD_ARGON2ID)]);
        return (string)$seed->fetchColumn();
    };
    $admin=$user('admin');$outsider=$user('outsider');
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$admin]);
    $ws=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:actor,'owner')")->execute(['ws'=>$ws,'actor'=>$admin]);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$inventory=new InventoryService($db,$repo,$audit);
    $rbac=new Rbac($db,$audit);$history=new HistoryService($db,$repo,$inventory,$audit);
    $queue=new PostgresQueueDriver($db);
    $transfer=new TransferService($db,$repo,$history,$queue,$audit,$rbac);
    $creds=new CredentialBindingService($db,$repo,$audit);
    $rows=[
        ['external_id'=>'node-1','type'=>'server','fields'=>['name'=>'=2+2','status'=>'online','tags'=>['production']]],
        ['external_id'=>'router-1','type'=>'router','fields'=>['name'=>'Edge router','status'=>'maintenance']],
    ];
    $content=json_encode($rows,JSON_THROW_ON_ERROR);
    $preview=$transfer->preview($admin,$ws,'json',$content);
    $ok($preview['total']===2&&$preview['summary']['create']===2,'preview reads actual identity and reports creates');
    $reject(404,fn()=>$transfer->preview($outsider,$ws,'json',$content),'cross-workspace preview hidden');
    $first=$transfer->submit($admin,$ws,'json',$content,'transfer-test-001');
    $ok($first['status']==='enqueued'&&$first['row_count']===2,'batch durably queued');
    $dup=$transfer->submit($admin,$ws,'json',$content,'transfer-test-001');
    $ok($dup['id']===$first['id'],'Idempotency-Key reuses batch');
    $reject(409,fn()=>$transfer->submit($admin,$ws,'json',json_encode([$rows[0]]),'transfer-test-001'),'mismatched idempotency payload refused');
    $reservation=$queue->reserve('default');$ok($reservation!==null&&$reservation->job->type==='inventory.import','real queue driver leased import job');
    $processor=new JobProcessor(new RuntimeDiagnostics($root,new ConfigRepository($root.'/config')),
        new LocalDisk($dir.'/storage'),$queue,$transfer);
    $result=$processor->process($reservation->job);
    $queue->succeed($reservation,$result);
    $ok($result['status']==='completed'&&($result['summary']['created']??0)===2,'worker reconciled both assets');
    $batch=$transfer->batch($admin,$ws,$first['id']);
    $ok($batch['processed_rows']===2&&count($batch['results'])===2,'checkpoint and per-row results recorded');
    $ok($transfer->processBatch($first['id'])['already_complete']===true,'completed batch is retry-safe');
    $page=$inventory->list($admin,$ws,10,0,null,null,null,false);
    $ok(count($page['data'])===2,'imported assets persisted in CMDB');
    $asset=$page['data'][0];
    $export=$transfer->export($admin,$ws,'csv');
    $ok(str_contains($export,"'=2+2")&&!str_contains($export,'encrypted_payload'),'CSV export neutralizes formulas');
    $json=$transfer->export($admin,$ws,'json');
    $ok(count(json_decode($json,true,16,JSON_THROW_ON_ERROR))===2,'JSON export returns two records');
    $reject(404,fn()=>$transfer->export($outsider,$ws,'json'),'cross-workspace export denied');
    // Old unsafely scoped legacy rows cannot be enrolled or attached after Vault is enabled.
    $legacy=$pdo->prepare("INSERT INTO credentials(name,type,encrypted_payload) VALUES('legacy unverified','api',decode(:bytes,'hex')) RETURNING id::text");
    $legacy->execute(['bytes'=>bin2hex(random_bytes(32))]);$oldCredential=$legacy->fetchColumn();
    $reject(404,fn()=>$creds->scope($admin,$ws,$oldCredential),'legacy credential ownership cannot be assumed');
    $reject(403,fn()=>$creds->attach($admin,$asset['id'],$oldCredential,'api'),'legacy credential cannot bind');
    $key=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));
    $crypto=new VaultCrypto($key);
    $vault=new CredentialsVault($db,$crypto,$key);$vault->initialize();
    $store=new CredentialStore($db,$repo,$vault,$crypto,$audit);
    $created=$store->create($admin,['workspace_id'=>$ws,'name'=>'Current API key','type'=>'api_token','secret'=>'test-only-key-value']);
    $credential=$created['id'];
    $scoped=$creds->scope($admin,$ws,$credential);$ok($scoped['credential_id']===$credential,'new credential is automatically scoped and scoped again safely');
    $binding=$creds->attach($admin,$asset['id'],$credential,'api');
    $ok($binding['credential_id']===$credential,'binding inserted with FK and workspace');
    $list=$creds->list($admin,$asset['id']);
    $ok(count($list)===1&&!isset($list[0]['encrypted_payload'])&&!isset($list[0]['metadata']),'secret payload never returned');
    $reject(404,fn()=>$creds->list($outsider,$asset['id']),'outsider binding access denied');
    $reject(409,fn()=>$creds->attach($admin,$asset['id'],$credential,'api'),'duplicate binding rejected');
    $creds->detach($admin,$asset['id'],$binding['id']);
    $ok($creds->list($admin,$asset['id'])===[],'binding safely removed');
    $ok((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action LIKE 'inventory.import.%' OR action LIKE 'inventory.credential.%'")->fetchColumn()>=3,'actual transaction audit entries');
    echo "PASS: $checks PostgreSQL Inventory Transfer/Credential Bindings integration assertions.\n";
}catch(Throwable $error){$failed=true;fwrite(STDERR,'FAIL: transfer-postgres-integration: '.$error::class.': '.$error->getMessage()."\n");}
finally{
    if($pdo!==null&&$created){try{$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
        catch(Throwable $e){$failed=true;fwrite(STDERR,'Cleanup failed: '.$e->getMessage()."\n");}}
    foreach(glob($dir.'/migrations/*')?:[] as $file)unlink($file);
    @rmdir($dir.'/migrations');@rmdir($dir.'/storage');@rmdir($dir);
}
exit($failed?1:0);
