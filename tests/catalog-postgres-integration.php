#!/usr/bin/env php
<?php
declare(strict_types=1);
/** PostgreSQL 17+/18 integration using an isolated, automatically removed schema. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\CatalogService;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\InventoryService;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Workspace\WorkspaceService;
if(getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')){
    fwrite(STDERR,"REFUSED: requires CAJEER_PG_TEST=1 and an isolated cajeer_test_* database.\n");exit(2);
}
if(!extension_loaded('pdo_pgsql')){fwrite(STDERR,"FAIL: pdo_pgsql extension not installed.\n");exit(1);}
$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/cajeer-catalog-pg-'.bin2hex(random_bytes(8));
$schema='cajeer_catalog_'.bin2hex(random_bytes(8));$created=false;$pdo=null;$checks=0;$failed=false;
mkdir($dir.'/migrations',0700,true);
$assert=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;};
$reject=static function(int $status,callable $fn,string $label)use($assert):void{
    try{$fn();}catch(HttpException $e){$assert($e->status===$status,$label);return;}throw new RuntimeException('Expected HTTP '.$status.': '.$label);
};
try{
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
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file) {
        if(preg_match('/^(?:000[1-9]|001[012])_/',basename($file))===1)copy($file,$dir.'/migrations/'.basename($file));
    }
    $runner=new MigrationRunner($db,$dir.'/migrations');
    $applied=$runner->run();$assert(count($applied['applied'])===12,'initial migrations 0001..0012 applied');
    $pdo->prepare('INSERT INTO users(email,name,password_hash) VALUES (:email,:name,:hash)')->execute([
        'email'=>'catalog-'.bin2hex(random_bytes(4)).'@example.invalid','name'=>'catalog-admin',
        'hash'=>password_hash('test-password-not-used',PASSWORD_ARGON2ID),
    ]);
    $admin=(string)$pdo->query("SELECT id::text FROM users WHERE name='catalog-admin'")->fetchColumn();
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$admin]);
    $workspace=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:id,'owner')")->execute(['ws'=>$workspace,'id'=>$admin]);
    $audit=new AuditLogger($db);$inventory=new InventoryRepository();$assets=new InventoryService($db,$inventory,$audit);
    $legacy=$assets->create($admin,['workspace_id'=>$workspace,'type'=>'server','name'=>'Legacy Primary','tags'=>['prod','critical'],'ip_addresses'=>['192.0.2.10']]);
    $legacyId=$legacy['id'];
    $assert($legacy['version']===1,'legacy asset version before upgrade');
    copy($root.'/migrations/pgsql/0013_inventory_catalog_search.sql',$dir.'/migrations/0013_inventory_catalog_search.sql');
    $assert(count($runner->run()['applied'])===1,'catalog schema upgrade applied');
    $assert($runner->run()['applied']===[],'second migration run is idempotent');
    $assert($runner->validate()['valid'],'migration checksums valid');
    $assert($assets->read($admin,$legacyId)['id']===$legacyId,'legacy UUID preserved');
    $catalog=new CatalogService($db,$inventory,$audit);
    $tags=$catalog->list($admin,$workspace,'tags');
    $assert($tags['pagination']['total']===2,'valid legacy tags normalized');
    $prod=array_values(array_filter($tags['data'],static fn($tag):bool=>$tag['slug']==='prod'))[0];
    $assert($prod['asset_count']===1,'legacy tags linked');
    $assert($prod['version']===1,'tag optimistic version');
    $rbac=new Rbac($db,$audit);
    $rbac->requirePermission(['mode'=>'session','user'=>['id'=>$admin]],'inventory.catalog.manage');
    $assert(true,'catalog permissions assigned to administrator');
    $ops=$catalog->create($admin,'tags',['workspace_id'=>$workspace,'slug'=>'ops','name'=>'Operations']);
    $assert($ops['slug']==='ops','tag created in PostgreSQL');
    $reject(409,fn()=>$catalog->create($admin,'tags',['workspace_id'=>$workspace,'slug'=>'ops','name'=>'Duplicate']),'duplicate tag refused');
    $updated=$catalog->update($admin,'tags',$workspace,$ops['id'],1,['name'=>'Operations Team']);
    $assert($updated['version']===2,'tag update increments version');
    $reject(412,fn()=>$catalog->update($admin,'tags',$workspace,$ops['id'],1,['name'=>'Old']),'stale tag edit refused');
    $group=$catalog->create($admin,'groups',['workspace_id'=>$workspace,'slug'=>'frontend','name'=>'Frontend']);
    $assert($group['version']===1,'group created');
    $linked=$catalog->groupMembership($admin,$workspace,$group['id'],$legacyId,1,true);
    $assert($linked['version']===2,'membership persists and updates group ETag');
    $reject(412,fn()=>$catalog->groupMembership($admin,$workspace,$group['id'],$legacyId,1,false),'stale membership ETag rejected');
    $reject(409,fn()=>$catalog->delete($admin,'groups',$workspace,$group['id'],2),'group with members cannot be deleted');
    $reject(409,fn()=>$catalog->delete($admin,'tags',$workspace,$prod['id'],1),'assigned tag cannot be deleted');
    $assert(count($catalog->groupAssets($admin,$workspace,$group['id'])['data'])===1,'group members are queried from PostgreSQL');
    $new=$assets->create($admin,['workspace_id'=>$workspace,'type'=>'server','name'=>'API Worker','environment'=>'production',
        'owner_user_id'=>$admin,'tags'=>['ops','prod'],'status'=>'online','ip_addresses'=>['2001:db8::10']]);
    $id=$new['id'];
    $assert($new['environment']==='production','environment persisted');
    $assert($catalog->list($admin,$workspace,'tags')['pagination']['total']===3,'asset tags reuse existing catalog');
    $assert($assets->list($admin,$workspace,10,0,null,null,null,false,['tag'=>'ops'])['total']===1,'tag filter');
    $assert($assets->list($admin,$workspace,10,0,null,null,null,false,['group_id'=>$group['id']])['total']===1,'group filter');
    $assert($assets->list($admin,$workspace,10,0,null,null,null,false,['owner_user_id'=>$admin,'environment'=>'production'])['total']===1,'owner + environment filters');
    $assert($assets->list($admin,$workspace,10,0,null,null,null,false,['source'=>'manual','os_family'=>'windows'])['total']===0,'source + OS filters');
    $assert($assets->list($admin,$workspace,10,0,null,null,'API Worker',false)['total']===1,'full text search');
    $assert($assets->list($admin,$workspace,10,0,null,null,'192.0.2.10',false)['total']===1,'legacy IP search');
    $paged=$assets->list($admin,$workspace,1,0,null,null,null,false);
    $assert($paged['total']===2 && is_string($paged['next_cursor']),'keyset cursor issued');
    $page2=$assets->list($admin,$workspace,1,0,null,null,null,false,['cursor'=>$paged['next_cursor']]);
    $assert(count($page2['data'])===1 && $page2['data'][0]['id']!==$paged['data'][0]['id'],'keyset paging returns next asset');
    $assert($assets->list($admin,$workspace,10,0,null,null,null,false,['sort'=>'name_asc'])['data'][0]['name']==='API Worker','sort whitelist');
    $reject(422,fn()=>$assets->list($admin,$workspace,10,0,null,null,null,false,['sort'=>'a.name;DROP TABLE users']),'SQL order injection blocked');
    $reject(422,fn()=>$assets->list($admin,$workspace,10,0,null,null,null,false,['cursor'=>'bad']),'malformed cursor blocked');
    $changed=$assets->update($admin,$id,$new['version'],['tags'=>['ops']]);
    $assert($changed['version']===$new['version']+1,'tags update increments version once');
    $assert($assets->list($admin,$workspace,10,0,null,null,null,false,['tag'=>'prod'])['total']===1,'normalized associations updated on tag removal');
    $pdo->prepare("UPDATE inventory_assets SET tags='[\"audit\"]'::jsonb WHERE id=:id")->execute(['id'=>$id]);
    $assert($assets->list($admin,$workspace,10,0,null,null,null,false,['tag'=>'audit'])['total']===1,'direct SQL JSONB update syncs tag catalog');
    $viewerId=(string)$pdo->query("INSERT INTO users(email,name,password_hash) VALUES ('catalog-viewer-".bin2hex(random_bytes(4))."@example.invalid','catalog-viewer','test') RETURNING id::text")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:id,'viewer')")->execute(['ws'=>$workspace,'id'=>$viewerId]);
    $reject(403,fn()=>$catalog->create($viewerId,'groups',['workspace_id'=>$workspace,'slug'=>'not-allowed','name'=>'Denied']),'viewer cannot write catalog');
    $workspaces=new WorkspaceService($db,$audit);
    $org=(string)$pdo->query("SELECT organization_id::text FROM workspaces WHERE id='".$workspace."'")->fetchColumn();
    $private=$workspaces->createWorkspace($admin,$org,'private-catalog','Private catalog');
    $reject(404,fn()=>$catalog->list($viewerId,$private['id'],'tags'),'workspace catalog not visible to nonmember');
    $privateAsset=$assets->create($admin,['workspace_id'=>$private['id'],'type'=>'server','name'=>'Private node']);
    $reject(404,fn()=>$catalog->groupMembership($admin,$workspace,$group['id'],$privateAsset['id'],2,true),'cross-workspace group member denied');
    $workspaces->transferAsset($admin,$workspace,$legacyId,$private['id']);
    $assert(count($catalog->groupAssets($admin,$workspace,$group['id'])['data'])===0,'group associations cleared when asset moves');
    $assert($assets->list($admin,$private['id'],10,0,null,null,null,false,['tag'=>'prod'])['total']===1,'tags reindexed in destination workspace');
    $currentGroup=$catalog->read($admin,$workspace,'groups',$group['id']);
    $catalog->delete($admin,'groups',$workspace,$group['id'],$currentGroup['version']);
    $assert($catalog->list($admin,$workspace,'groups')['pagination']['total']===0,'empty group deleted');
    $assert((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action LIKE 'inventory.%'")->fetchColumn()>=9,'real audit entries persisted');
    echo "PASS: $checks PostgreSQL Catalog/Search integration assertions.\n";
}catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL: catalog PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally{
    if($created&&$pdo instanceof PDO){try{$pdo->exec('SET search_path TO public');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable){fwrite(STDERR,"WARNING: test schema cleanup failed.\n");$failed=true;}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)@unlink($file);
    @rmdir($dir.'/migrations');@rmdir($dir);
}
exit($failed?1:0);
