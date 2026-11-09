#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Real PostgreSQL 17+ CRUD/revision/tenant integration, isolated schema only. */
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\InventoryService;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Workspace\WorkspaceService;

if (getenv('CAJEER_PG_TEST')!=='1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')) {
    fwrite(STDERR,"REFUSED: CAJEER_PG_TEST=1 with CAJEER_PG_TEST_DATABASE=cajeer_test_* is required.\n"); exit(2);
}
if (!extension_loaded('pdo_pgsql')) {fwrite(STDERR,"FAIL: ext-pdo_pgsql is required.\n");exit(1);}
$root=dirname(__DIR__);
$dir=sys_get_temp_dir().'/cajeer-assets-pg-'.bin2hex(random_bytes(7));
$schema='cajeer_assets_'.bin2hex(random_bytes(8));
$created=false;$pdo=null;$checks=0;$failed=false;
mkdir($dir.'/migrations',0700,true);
$assert=static function(bool $ok,string $label)use(&$checks):void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;};
$reject=static function(int $status,callable $run,string $label)use($assert):void {
    try {$run();}catch(HttpException $e) {$assert($e->status===$status,$label);return;}
    throw new RuntimeException('Expected HTTP '.$status.': '.$label);
};
try {
    $db=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),[
      'driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1','port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432',
      'database'=>getenv('CAJEER_PG_TEST_DATABASE'),'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer',
      'password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'','sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer',
    ]);
    $pdo=$db->connection();
    $assert((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;
    $pdo->exec('SET search_path TO "'.$schema.'", public');
    foreach (glob($root.'/migrations/pgsql/000[1-8]_*.sql') as $file) copy($file,$dir.'/migrations/'.basename($file));
    $runner=new MigrationRunner($db,$dir.'/migrations');$runner->run();
    $add=$pdo->prepare('INSERT INTO users(email,name,password_hash) VALUES (:email,:name,:hash) RETURNING id::text');
    $seed=static function(string $name)use($add):string {
        $add->execute(['email'=>$name.'-'.bin2hex(random_bytes(4)).'@example.invalid','name'=>$name,'hash'=>password_hash('integration_secret_not_real',PASSWORD_ARGON2ID)]);
        return (string)$add->fetchColumn();
    };
    $admin=$seed('admin');$viewer=$seed('viewer');$stranger=$seed('stranger');
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$admin]);
    $pdo->exec("INSERT INTO inventory_assets(type,source,external_id,name,status,attributes) VALUES ('server','manual','legacy-node','Existing host','online','{}'::jsonb)");
    $oldId=(string)$pdo->query("SELECT id::text FROM inventory_assets WHERE external_id='legacy-node'")->fetchColumn();
    copy($root.'/migrations/pgsql/0009_organizations_workspaces.sql',$dir.'/migrations/0009_organizations_workspaces.sql');
    copy($root.'/migrations/pgsql/0010_inventory_assets_crud.sql',$dir.'/migrations/0010_inventory_assets_crud.sql');
    $assert(count($runner->run()['applied'])===2,'both upgrade migrations applied');
    $assert($runner->run()['applied']===[],'repeat migration does nothing');
    $assert($runner->validate()['valid'],'checksums valid');
    $default=(string)$pdo->query("SELECT w.id::text FROM workspaces w JOIN organizations o ON o.id=w.organization_id WHERE o.slug='default' AND w.slug='default'")->fetchColumn();
    $assert($default!=='' && (string)$pdo->query("SELECT id::text FROM inventory_assets WHERE external_id='legacy-node'")->fetchColumn()===$oldId,'legacy UUID preserved');
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$svc=new InventoryService($db,$repo,$audit);
    $ws=new WorkspaceService($db,$audit);$rbac=new Rbac($db,$audit);
    $adminPrincipal=['mode'=>'session','user'=>['id'=>$admin]];
    foreach (['inventory.assets.read','inventory.assets.create','inventory.assets.update','inventory.assets.delete'] as $scope) {
        $rbac->requirePermission($adminPrincipal,$scope);$assert(true,'administrator granted '.$scope);
    }
    $ws->setMember($admin,$default,$viewer,'viewer');
    // Membership is not global RBAC: a viewer with global scopes still cannot mutate.
    $pdo->exec("INSERT INTO roles(slug,name) VALUES('inventory-tester','Inventory tester')");
    $pdo->exec("INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='inventory-tester' AND p.scope IN ('inventory.assets.read','inventory.assets.create')");
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='inventory-tester'")->execute(['id'=>$viewer]);
    $rbac->requirePermission(['mode'=>'session','user'=>['id'=>$viewer]],'inventory.assets.create');$assert(true,'viewer has global create scope');
    $org=(string)$pdo->query("SELECT id::text FROM organizations WHERE slug='default'")->fetchColumn();
    $private=$ws->createWorkspace($admin,$org,'private-assets','Private assets');
    $reject(404,fn()=>$svc->list($viewer,$private['id'],20,0,null,null,null,false),'cross-workspace list denied');
    $created=$svc->create($admin,['workspace_id'=>$default,'type'=>'server','name'=>'Node 01','status'=>'online','ip_addresses'=>['192.0.2.11'],'tags'=>['prod'],'attributes'=>['rack'=>'B4']]);
    $id=$created['id'];
    $assert($created['source']==='manual' && $created['version']===1 && $created['name']==='Node 01','persisted create');
    $assert($created['attributes']['rack']==='B4' && $created['ip_addresses']===['192.0.2.11'],'JSONB roundtrip');
    $assert(count($svc->list($admin,$default,10,0,null,null,null,false)['data'])===2,'legacy and new visible');
    $assert($svc->list($admin,$default,10,0,'server','online','Node',false)['total']===1,'combined filters');
    $assert($svc->read($viewer,$id)['id']===$id,'viewer may read own workspace');
    $reject(403,fn()=>$svc->create($viewer,['workspace_id'=>$default,'type'=>'router','name'=>'Unauthorized']),'viewer cannot create');
    $reject(404,fn()=>$svc->read($stranger,$id),'non-member cannot enumerate UUID');
    $changed=$svc->update($admin,$id,1,['status'=>'maintenance','tags'=>['prod','maintenance'],'attributes'=>[]]);
    $assert($changed['status']==='maintenance' && $changed['version']===2 && $changed['attributes']===[],'update and empty JSON object');
    $reject(412,fn()=>$svc->update($admin,$id,1,['name'=>'Stale write']),'stale If-Match rejected');
    $reject(422,fn()=>$svc->update($admin,$id,2,['source'=>'proxmox']),'system field immutable');
    $reject(404,fn()=>$svc->update($admin,$id,2,['owner_user_id'=>$stranger]),'owner must be member');
    $owned=$svc->update($admin,$id,2,['owner_user_id'=>$viewer]);
    $assert($owned['owner_user_id']===$viewer && $owned['version']===3,'assigned valid owner');
    $deleted=$svc->delete($admin,$id,3);
    $assert($deleted['deleted_at']!==null && $deleted['version']===4,'soft delete increments version');
    $assert($svc->list($admin,$default,20,0,null,null,null,false)['total']===1,'deleted hidden from normal listing');
    $reject(404,fn()=>$svc->read($admin,$id),'deleted object hidden');
    $assert($svc->read($admin,$id,true)['deleted_at']!==null,'tombstone readable with includeDeleted');
    $reject(412,fn()=>$svc->restore($admin,$id,3),'stale restore rejected');
    $restored=$svc->restore($admin,$id,4);
    $assert($restored['deleted_at']===null && $restored['version']===5,'restore increments version');
    $repo->workspaceRole($pdo,$default,$admin,true);$assert(true,'owner write scope');
    $reject(403,fn()=>$svc->delete($viewer,$id,5),'viewer delete denied');
    $assert((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action LIKE 'inventory.asset.%'")->fetchColumn()>=5,'atomic inventory audit persisted');
    $ws->assignOwner($admin,$default,$oldId,$viewer);
    $assert($svc->read($admin,$oldId)['version']===2,'legacy ownership update bumps version');
    $ws->transferAsset($admin,$default,$oldId,$private['id']);
    $assert($svc->read($admin,$oldId)['version']===3,'legacy transfer bumps version');
    $reject(404,fn()=>$svc->read($viewer,$oldId),'transfer hides asset from former workspace');
    echo "PASS: $checks PostgreSQL Inventory CRUD integration assertions.\n";
} catch(Throwable $e) {
    $failed=true;fwrite(STDERR,'FAIL: inventory postgres CRUD: '.$e::class.': '.$e->getMessage()."\n");
} finally {
    if ($created && $pdo instanceof PDO) {
        try{$pdo->exec('SET search_path TO public');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
        catch(Throwable){fwrite(STDERR,"WARNING: test schema cleanup failed.\n");$failed=true;}
    }
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file) @unlink($file);
    @rmdir($dir.'/migrations');@rmdir($dir);
}
exit($failed?1:0);
