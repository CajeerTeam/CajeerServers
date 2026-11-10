#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Real PostgreSQL 17+/18 test, isolated temporary schema, destructive only inside that schema. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\HistoryService;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\InventoryService;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Workspace\WorkspaceService;
if(getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')){
    fwrite(STDERR,"REFUSED: isolated CAJEER_PG_TEST_DATABASE=cajeer_test_* and CAJEER_PG_TEST=1 required.\n");exit(2);
}
if(!extension_loaded('pdo_pgsql')){fwrite(STDERR,"FAIL: pdo_pgsql is required.\n");exit(1);}
$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/cajeer-history-'.bin2hex(random_bytes(8));
$schema='cajeer_history_'.bin2hex(random_bytes(8));$pdo=null;$created=false;$failed=false;$checks=0;
mkdir($dir.'/migrations',0700,true);
$ok=static function(bool $v,string $label)use(&$checks):void{if(!$v)throw new RuntimeException('FAIL: '.$label);$checks++;};
$reject=static function(int $code,callable $fn,string $label)use($ok):void{
    try{$fn();}catch(HttpException $e){$ok($e->status===$code,$label);return;}
    throw new RuntimeException('Expected HTTP '.$code.': '.$label);
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
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file){
        if(preg_match('/^(?:000[1-9]|001[0-3])_/',basename($file)))copy($file,$dir.'/migrations/'.basename($file));
    }
    $runner=new MigrationRunner($db,$dir.'/migrations');
    $ok(count($runner->run()['applied'])===13,'migration baseline 0001-0013 applied');
    $seed=$pdo->prepare('INSERT INTO users(email,name,password_hash) VALUES(:email,:name,:hash) RETURNING id::text');
    $user=static function(string $name)use($seed):string{
        $seed->execute(['email'=>$name.bin2hex(random_bytes(4)).'@example.invalid','name'=>$name,
            'hash'=>password_hash('only_for_integration_test_#42',PASSWORD_ARGON2ID)]);
        return (string)$seed->fetchColumn();
    };
    $admin=$user('owner');$outsider=$user('outsider');
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$admin]);
    $ws=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:user,'owner')")->execute(['ws'=>$ws,'user'=>$admin]);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$assets=new InventoryService($db,$repo,$audit);
    $legacy=$assets->create($admin,['workspace_id'=>$ws,'type'=>'server','name'=>'Legacy-node','attributes'=>['rack'=>'A1']]);
    $legacyId=$legacy['id'];
    copy($root.'/migrations/pgsql/0014_inventory_history_provenance.sql',$dir.'/migrations/0014_inventory_history_provenance.sql');
    $ok(count($runner->run()['applied'])===1,'history schema upgrade');
    $ok($runner->run()['applied']===[],'repeat migration no-op');
    $ok($runner->validate()['valid'],'migration checksums consistent');
    $history=new HistoryService($db,$repo,$assets,$audit);
    $rbac=new Rbac($db,$audit);
    foreach(['inventory.history.read','inventory.reconcile.manage','inventory.history.restore'] as $scope){
        $rbac->requirePermission(['mode'=>'session','user'=>['id'=>$admin]],$scope);$ok(true,'RBAC '.$scope);
    }
    $h=$history->revisions($admin,$legacyId);$ok($h['pagination']['total']===1&&$h['data'][0]['operation']==='baseline','pre-existing asset baseline');
    $ok(!str_contains(json_encode($h,JSON_THROW_ON_ERROR),'A1'),'arbitrary attributes excluded from history');
    $changed=$assets->update($admin,$legacyId,1,['name'=>'Renamed-node']);
    $ok($changed['version']===2&&$history->revisions($admin,$legacyId)['pagination']['total']===2,'API update auto-history');
    $pdo->prepare('UPDATE inventory_assets SET attributes=CAST(:value AS jsonb) WHERE id=:id')->execute([
        'id'=>$legacyId,'value'=>'{"private_token":"must-never-appear-in-history"}',
    ]);
    $h=$history->revisions($admin,$legacyId);
    $ok($h['pagination']['total']===3,'direct SQL UPDATE auto-captured');
    $ok(!str_contains(json_encode($h,JSON_THROW_ON_ERROR),'must-never-appear-in-history'),'direct SQL attribute content redacted');
    $ok(isset($h['data'][0]['changes']['attributes']['redacted']),'redacted attribute diff marker');
    try{$pdo->exec('UPDATE inventory_asset_revisions SET operation=\'update\' WHERE asset_id=\''.$legacyId.'\'');throw new RuntimeException('History mutation unexpectedly allowed.');}
    catch(PDOException $e){$ok((string)($e->errorInfo[0]??$e->getCode())==='55000','history immutable via direct SQL');}
    $restored=$history->restoreRevision($admin,$legacyId,3,1);
    $ok($restored['version']===4&&$restored['name']==='Legacy-node','selective restore creates new version');
    $ok($restored['attributes']['private_token']==='must-never-appear-in-history','arbitrary attributes intentionally not restored');
    $reject(412,fn()=>$history->restoreRevision($admin,$legacyId,3,1),'stale restore denied');
    $reject(404,fn()=>$history->revisions($outsider,$legacyId),'cross-workspace history hidden');
    $now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $event=['workspace_id'=>$ws,'source'=>'import','external_id'=>'node-xyz','event_key'=>'e-001','type'=>'server','observed_at'=>$now,
        'fields'=>['name'=>'Imported-node','status'=>'online','tags'=>['prod']]];
    $created=$history->observe($admin,$event);
    $external=$created['asset_id'];
    $ok($created['outcome']==='created'&&$created['duplicate']===false,'real imported asset persisted');
    $replay=$history->observe($admin,$event);
    $ok($replay['duplicate']===true&&$replay['id']===$created['id'],'duplicate event returns stored receipt');
    $ok($history->revisions($admin,$external)['pagination']['total']===1,'duplicate did not bump asset version');
    $reject(409,fn()=>$history->observe($admin,[...$event,'fields'=>['name'=>'Reused key']]),'changed replay rejected');
    $override=$history->override($admin,$external,1,['fields'=>['name'=>'Operator label']]);
    $ok($override['version']===2&&$override['name']==='Operator label','manual field override saved');
    $next=[...$event,'event_key'=>'e-002','observed_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+1 second')->format('Y-m-d\TH:i:s\Z'),
        'fields'=>['name'=>'Imported-other','status'=>'maintenance']];
    // Client timestamps may not be more than five minutes into the future.
    $r=$history->observe($admin,$next);
    $ok($r['outcome']==='conflicted'&&$r['conflicted_fields']===['name']&&in_array('status',$r['applied_fields'],true),'field policy: only non-overridden field applies');
    $ok($assets->read($admin,$external)['name']==='Operator label','manual label wins conflict');
    $c=$history->conflicts($admin,$external);$ok(count($c['data'])===1&&$c['data'][0]['field']==='name','unresolved conflict listed');
    $current=$assets->read($admin,$external);
    $cleared=$history->clearOverride($admin,$external,$current['version'],'name');
    $ok($cleared['version']===$current['version']+1,'clearing override versioned');
    $r=$history->observe($admin,[...$next,'event_key'=>'e-003','observed_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+2 second')->format('Y-m-d\TH:i:s\Z')]);
    $ok($r['outcome']==='applied'&&$assets->read($admin,$external)['name']==='Imported-other','next observation applies cleared override');
    $ok(count($history->conflicts($admin,$external)['data'])===0,'resolved conflict excluded');
    $stale=$history->observe($admin,[...$event,'event_key'=>'e-old','observed_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-2 days')->format('Y-m-d\TH:i:s\Z')]);
    $ok($stale['outcome']==='stale','older observation cannot overwrite newer data');
    $reject(409,fn()=>$history->override($admin,$legacyId,4,['fields'=>['name'=>'Invalid']]),'manual asset cannot use import overrides');
    $reject(404,fn()=>$history->provenance($outsider,$external),'non-member denied provenance');
    $ok(count($history->observations($admin,$external)['data'])===4,'distinct receipts stored');
    $ok((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action LIKE 'inventory.%'")->fetchColumn()>=5,'transactional audit persisted');
    echo "PASS: $checks PostgreSQL Inventory History/Provenance/Reconciliation assertions.\n";
}catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL: history-postgres-integration: '.$e::class.': '.$e->getMessage()."\n");}
finally{
    if($pdo!==null&&$created){try{$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $error){$failed=true;fwrite(STDERR,'Cleanup failed: '.$error->getMessage()."\n");}}
    foreach(glob($dir.'/migrations/*')?:[] as $file)unlink($file);
    @rmdir($dir.'/migrations');@rmdir($dir);
}
exit($failed?1:0);
