#!/usr/bin/env php
<?php
declare(strict_types=1);
/** PostgreSQL 17/18 integration. Only operates inside a random disposable schema of cajeer_test_* DB. */
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Inventory\CmdbIntegrity;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\InventoryService;
use Cajeer\Servers\Inventory\IpamService;
use Cajeer\Servers\Http\Exception\HttpException;

if(getenv('CAJEER_PG_TEST')!=='1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')){
    fwrite(STDERR,"REFUSED: requires CAJEER_PG_TEST=1 and disposable CAJEER_PG_TEST_DATABASE=cajeer_test_*\n");exit(2);
}
if(!extension_loaded('pdo_pgsql')){fwrite(STDERR,"FAIL: ext-pdo_pgsql required.\n");exit(1);}

$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/cajeer-cmdb-integration-'.bin2hex(random_bytes(8));
$schema='cajeer_cmdb_'.bin2hex(random_bytes(8));$pdo=null;$created=false;$failed=false;$checks=0;
mkdir($dir.'/migrations',0700,true);
$ok=static function(bool $yes,string $why)use(&$checks):void{
    if(!$yes)throw new RuntimeException('FAIL: '.$why);
    $checks++;
};
try{
    $db=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),[
        'driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1',
        'port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432',
        'database'=>getenv('CAJEER_PG_TEST_DATABASE'),
        'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer',
        'password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'',
        'sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer',
    ]);
    $pdo=$db->connection();
    $ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;
    $pdo->exec('SET search_path TO "'.$schema.'", public');
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file)copy($file,$dir.'/migrations/'.basename($file));
    $migrator=new MigrationRunner($db,$dir.'/migrations');
    $ok(count($migrator->run()['applied'])===17,'all 17 migrations applied');
    $ok($migrator->run()['applied']===[]&&$migrator->validate()['valid'],'migration checksums/replay');

    $createUser=$pdo->prepare("INSERT INTO users(email,name,password_hash) VALUES(:email,'CMDB Owner',:hash) RETURNING id::text");
    $createUser->execute(['email'=>'cmdb-'.bin2hex(random_bytes(5)).'@example.invalid',
        'hash'=>password_hash('integration-only-#',PASSWORD_ARGON2ID)]);
    $actor=(string)$createUser->fetchColumn();
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :user,id FROM roles WHERE slug='administrator'")
        ->execute(['user'=>$actor]);
    $workspace=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:user,'owner')")
        ->execute(['ws'=>$workspace,'user'=>$actor]);

    $repo=new InventoryRepository();$audit=new AuditLogger($db);
    $inventory=new InventoryService($db,$repo,$audit);
    $ipam=new IpamService($db,$repo,$audit);
    $integrity=new CmdbIntegrity($db);
    $asset=$inventory->create($actor,['workspace_id'=>$workspace,'type'=>'server','name'=>'cmdb-test-node']);
    $checkFields=$pdo->prepare('SELECT count(*) FROM inventory_field_provenance WHERE asset_id=:id AND mode=\'manual\'');
    $checkFields->execute(['id'=>$asset['id']]);
    $ok((int)$checkFields->fetchColumn()===6,'manual creation records all six field provenance rows');
    $updated=$inventory->update($actor,$asset['id'],$asset['version'],['name'=>'renamed-cmdb-node']);
    $ok($updated['version']===$asset['version']+1,'versioned asset update');
    $initial=$integrity->inspect();
    $ok($initial['ok']===true,'all CMDB invariants initially consistent');
    $ok(($initial['totals']['assets']??-1)===1,'real asset count');
    $ok($integrity->inspect(true)['ok']===true,'deep cycle scan succeeds');

    $network=$ipam->create($actor,'network',$workspace,['name'=>'cmdb-net','cidr'=>'198.51.100.0/24']);
    $interface=$ipam->create($actor,'interface',$workspace,['name'=>'eth0','asset_id'=>$asset['id']]);
    try{
        $inventory->delete($actor,$asset['id'],$updated['version']);
        throw new RuntimeException('Soft-delete with active interface unexpectedly succeeded');
    }catch(HttpException $e){$ok($e->status===409,'IPAM interface blocks asset soft-delete');}
    $ok($integrity->inspect()['ok']===true,'IPAM references still consistent after rejected delete');
    $ipam->delete($actor,'interface',$workspace,$interface['id'],$interface['version']);
    $inventory->delete($actor,$asset['id'],$updated['version']);
    $ok($integrity->inspect()['ok']===true,'soft-deleted asset with history remains consistent');
    $pdo->prepare('DELETE FROM inventory_field_provenance WHERE asset_id=:id AND field=\'name\'')
        ->execute(['id'=>$asset['id']]);
    $broken=$integrity->inspect();
    $ok($broken['ok']===false,'deleted provenance is detected');
    $ok(in_array('history.missing_field_provenance',array_column(array_filter($broken['checks'],
        static fn(array $c):bool=>!$c['ok']),'name'),true),'provenance invariant reports failure');
    $pdo->prepare("INSERT INTO inventory_field_provenance(asset_id,field,mode,source) VALUES (:asset,'name','manual','manual')")
        ->execute(['asset'=>$asset['id']]);
    $ok($integrity->inspect()['ok']===true,'restoration clears violation');
    $ok($network['cidr']==='198.51.100.0/24','existing IPAM API preserved');
    echo "PASS: $checks PostgreSQL CMDB integration assertions.\n";
}catch(Throwable $error){$failed=true;fwrite(STDERR,'FAIL: CMDB integration: '.$error::class.': '.$error->getMessage()."\n");}
finally{
    if($pdo!==null&&$created){
        try{$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
        catch(Throwable $error){$failed=true;fwrite(STDERR,'Cleanup failed: '.$error->getMessage()."\n");}
    }
    foreach(glob($dir.'/migrations/*')?:[] as $file)unlink($file);
    @rmdir($dir.'/migrations');@rmdir($dir);
}
exit($failed?1:0);
