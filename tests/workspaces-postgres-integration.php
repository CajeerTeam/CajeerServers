#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Only run against isolated PostgreSQL 17+ database; creates/drops a unique test schema. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Workspace\WorkspaceService;

if (getenv('CAJEER_PG_TEST')!=='1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')) {
    fwrite(STDERR,"REFUSED: CAJEER_PG_TEST=1 and CAJEER_PG_TEST_DATABASE=cajeer_test_* required.\n");exit(2);
}
if (!extension_loaded('pdo_pgsql')) {fwrite(STDERR,"FAIL: ext-pdo_pgsql is required.\n");exit(1);}
$root=dirname(__DIR__);
$dir=sys_get_temp_dir().'/cajeer-workspaces-pg-'.bin2hex(random_bytes(7));
$schema='cajeer_ws_'.bin2hex(random_bytes(8));
mkdir($dir.'/migrations',0700,true);
$created=false;$pdo=null;$checks=0;
$assert=static function(bool $ok,string $label)use(&$checks):void {if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;};
$reject=static function(int $status,callable $run,string $label)use($assert):void {
    try{$run();}catch(HttpException $e){$assert($e->status===$status,$label);return;}
    throw new RuntimeException('Expected HTTP '.$status.' '.$label);
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
    $pdo->exec('SET search_path TO "'.$schema.'",public');
    foreach(glob($root.'/migrations/pgsql/000[1-8]_*.sql') as $file) copy($file,$dir.'/migrations/'.basename($file));
    $runner=new MigrationRunner($db,$dir.'/migrations');$runner->run();
    $createUser=$pdo->prepare('INSERT INTO users(email,name,password_hash) VALUES (:email,:name,:hash) RETURNING id::text');
    $createUser->execute(['email'=>'ws-admin-'.bin2hex(random_bytes(5)).'@example.invalid','name'=>'Admin','hash'=>password_hash('test_password_for_integration',PASSWORD_ARGON2ID)]);
    $admin=(string)$createUser->fetchColumn();
    $createUser->execute(['email'=>'ws-member-'.bin2hex(random_bytes(5)).'@example.invalid','name'=>'Member','hash'=>password_hash('test_password_for_integration',PASSWORD_ARGON2ID)]);
    $member=(string)$createUser->fetchColumn();
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$admin]);
    $pdo->exec("INSERT INTO inventory_assets(type,source,external_id,name,status) VALUES ('server','manual','pre-existing','Existing node','unknown')");
    $asset=(string)$pdo->query("SELECT id::text FROM inventory_assets WHERE external_id='pre-existing'")->fetchColumn();
    copy($root.'/migrations/pgsql/0009_organizations_workspaces.sql',$dir.'/migrations/0009_organizations_workspaces.sql');
    $assert(count($runner->run()['applied'])===1,'schema upgraded once');
    $assert($runner->run()['applied']===[],'migration idempotent');
    $assert($runner->validate()['valid'],'migration checksums valid');
    $default=(string)$pdo->query("SELECT w.id::text FROM workspaces w JOIN organizations o ON o.id=w.organization_id WHERE o.slug='default' AND w.slug='default'")->fetchColumn();
    $assert($default!=='','default workspace migrated');
    $assert((string)$pdo->query("SELECT workspace_id::text FROM inventory_assets WHERE id='".$asset."'")->fetchColumn()===$default,'asset UUID and metadata preserved');
    $audit=new AuditLogger($db);$svc=new WorkspaceService($db,$audit);$rbac=new Rbac($db,$audit);
    $adminPrincipal=['mode'=>'session','user'=>['id'=>$admin]];
    $rbac->requirePermission($adminPrincipal,'organizations.manage');$assert(true,'admin granted workspace scopes');
    $assert(count($svc->organizations($admin))===1,'admin sees default org');
    $assert($svc->organizations($member)===[],'unassigned user sees no org');
    $assert(count($svc->workspaces($admin))===1,'admin seeded owner');
    $assert($svc->workspaces($member)===[],'unassigned user sees no workspace');
    $reject(404,fn()=>$svc->members($member,$default),'no cross-tenant member enumeration');
    $reject(404,fn()=>$svc->assets($member,$default),'no cross-tenant asset enumeration');
    $org=$svc->createOrganization($admin,'private-'.substr(bin2hex(random_bytes(3)),0,6),'Private');
    $assert(strlen($org['id'])===36 && strlen($org['default_workspace_id'])===36,'organization and owner workspace persisted');
    $assert(count($svc->workspaces($admin))===2,'creator belongs to new org');
    $createdOrg=$svc->renameOrganization($admin,$org['id'],'Renamed');$assert($createdOrg['name']==='Renamed','organization updated');
    $defaultOrg=(string)$pdo->query("SELECT id::text FROM organizations WHERE slug='default'")->fetchColumn();
    $new=$svc->createWorkspace($admin,$defaultOrg,'staging-zone','Staging zone');
    $assert($new['role']==='owner' && $new['organization_id']===$defaultOrg,'workspace created with owner');
    $assert($svc->renameWorkspace($admin,$new['id'],'Staging 2')['name']==='Staging 2','workspace update persisted');
    $svc->setMember($admin,$new['id'],$member,'viewer');
    $assert(count($svc->members($admin,$new['id']))===2,'workspace membership stored');
    $reject(403,fn()=>$svc->renameWorkspace($member,$new['id'],'Illegal'),'viewer cannot rename workspace');
    $reject(403,fn()=>$svc->setMember($member,$new['id'],$admin,null),'viewer cannot remove member');
    $reject(404,fn()=>$svc->members($member,$default),'member cannot see sibling workspace');
    $svc->setMember($admin,$default,$member,'viewer');
    $assigned=$svc->assignOwner($admin,$default,$asset,$member);
    $assert($assigned['owner_user_id']===$member,'assigned owner must be workspace member');
    $reject(409,fn()=>$svc->setMember($admin,$default,$member,null),'cannot remove owner of assigned asset');
    $reject(403,fn()=>$svc->assignOwner($member,$default,$asset,$admin),'viewer cannot assign owner');
    $transferred=$svc->transferAsset($admin,$default,$asset,$new['id']);
    $assert($transferred['workspace_id']===$new['id'] && $transferred['owner_user_id']===null,'asset transferred and owner cleared');
    $assert($svc->assets($admin,$default)===[],'transferred asset invisible in source');
    $assert(count($svc->assets($member,$new['id']))===1,'member sees only own workspace assets');
    $reject(403,fn()=>$svc->transferAsset($admin,$new['id'],$asset,$org['default_workspace_id']),'cross-org transfers are blocked');
    $reject(409,fn()=>$svc->setMember($admin,$new['id'],$admin,null),'cannot remove sole owner');
    $svc->setMember($admin,$new['id'],$member,'owner');
    $svc->setMember($admin,$new['id'],$admin,null);
    $assert(count($svc->members($member,$new['id']))===1,'owner handover succeeds');
    $reject(404,fn()=>$svc->assets($admin,$new['id']),'former member can no longer read workspace');
    $assert((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action LIKE 'workspace.%' OR action LIKE 'inventory.asset.%'")->fetchColumn()>=6,'audit persisted');
    echo "PASS: $checks PostgreSQL workspace/ownership integration assertions.\n";
} catch(Throwable $e) {
    fwrite(STDERR,'FAIL: workspaces postgres integration: '.$e::class.': '.$e->getMessage()."\n");exit(1);
} finally {
    if ($created && $pdo instanceof PDO) {
        try{$pdo->exec('SET search_path TO public');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
        catch(Throwable){fwrite(STDERR,"WARNING: test schema cleanup failed.\n");}
    }
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)@unlink($file);
    @rmdir($dir.'/migrations');@rmdir($dir);
}
