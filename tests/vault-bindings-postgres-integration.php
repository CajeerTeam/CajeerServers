#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Destructive tests run ONLY in a separate disposable schema in an explicitly allowed test DB. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Connector\ConnectorBindingService;
use Cajeer\Servers\Credentials\CredentialStore;
use Cajeer\Servers\Credentials\CredentialsVault;
use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\CredentialBindingService;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\InventoryService;
use Cajeer\Servers\Security\Rbac;
if(getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')){
    fwrite(STDERR,"REFUSED: CAJEER_PG_TEST=1 and disposable cajeer_test_* database required.\n");exit(2);
}
if(!extension_loaded('pdo_pgsql')||!extension_loaded('sodium')){fwrite(STDERR,"FAIL: pdo_pgsql and sodium required.\n");exit(1);}
$root=dirname(__DIR__);$schema='cajeer_binding_'.bin2hex(random_bytes(8));
$dir=sys_get_temp_dir().'/cajeer-binding-'.bin2hex(random_bytes(8));
mkdir($dir.'/migrations',0700,true);
$pdo=null;$created=false;$failed=false;$checks=0;
$ok=static function(bool $value,string $label)use(&$checks):void {if(!$value)throw new RuntimeException('FAIL: '.$label);$checks++;};
$reject=static function(int $status,callable $call,string $label)use($ok):void{
    try{$call();}catch(HttpException $e){$ok($e->status===$status,$label);return;}
    throw new RuntimeException('Expected HTTP '.$status.': '.$label);
};
$sqlReject=static function(callable $call,string $label)use($ok):void {
    try{$call();}catch(PDOException){$ok(true,$label);return;}
    throw new RuntimeException('Expected PostgreSQL rejection: '.$label);
};
try {
    $db=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),[
        'driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1',
        'port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432',
        'database'=>getenv('CAJEER_PG_TEST_DATABASE'),'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer',
        'password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'','sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer',
    ]);
    $pdo=$db->connection();$ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;$pdo->exec('SET search_path TO "'.$schema.'", public');
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $source)copy($source,$dir.'/migrations/'.basename($source));
    $migrations=new MigrationRunner($db,$dir.'/migrations');
    $ok(count($migrations->run()['applied'])===count(glob($root.'/migrations/pgsql/*.sql')),'applies real migrations');
    $ok($migrations->run()['applied']===[] && $migrations->validate()['valid']===true,'migration replay is safe');
    $seed=$pdo->prepare('INSERT INTO users(email,name,password_hash) VALUES(:email,:name,:hash) RETURNING id::text');
    $user=static function(string $label)use($seed):string{
        $seed->execute(['email'=>$label.bin2hex(random_bytes(3)).'@example.invalid','name'=>$label,
            'hash'=>password_hash('test-only-not-production-123!',PASSWORD_ARGON2ID)]);
        return (string)$seed->fetchColumn();
    };
    $admin=$user('owner');$other=$user('other');$viewer=$user('viewer');
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$admin]);
    $ws=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $ws2=(string)$pdo->query("INSERT INTO workspaces(organization_id,slug,name) SELECT organization_id,'binding-second','Binding second' FROM workspaces WHERE id='".$ws."'::uuid RETURNING id::text")->fetchColumn();
    $member=$pdo->prepare('INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(CAST(:ws AS uuid),CAST(:id AS uuid),:role)');
    $member->execute(['ws'=>$ws,'id'=>$admin,'role'=>'owner']);
    $member->execute(['ws'=>$ws,'id'=>$viewer,'role'=>'viewer']);
    $member->execute(['ws'=>$ws2,'id'=>$other,'role'=>'owner']);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();
    $inventory=new InventoryService($db,$repo,$audit);
    $asset=$inventory->create($admin,['workspace_id'=>$ws,'type'=>'server','name'=>'Target server']);
    $key=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));
    $crypto=new VaultCrypto($key);$vault=new CredentialsVault($db,$crypto,$key);$vault->initialize();
    $store=new CredentialStore($db,$repo,$vault,$crypto,$audit);
    $token=$store->create($admin,['workspace_id'=>$ws,'name'=>'Scoped Proxmox','type'=>'proxmox_api',
        'secret'=>['token_id'=>'root@pam!sync','token_secret'=>'test-secret-value-123456']]);
    $ssh=$store->create($admin,['workspace_id'=>$ws,'name'=>'SSH access','type'=>'ssh_password',
        'secret'=>['username'=>'testuser','password'=>'test-only-password']]);
    $bindingSvc=new CredentialBindingService($db,$repo,$audit);
    $connector=new ConnectorBindingService($db,$repo,$audit);
    $rbac=new Rbac($db,$audit);
    $rbac->requirePermission(['mode'=>'session','user'=>['id'=>$admin]],'connectors.targets.manage');
    $reject(403,fn()=>$rbac->requirePermission(['mode'=>'session','user'=>['id'=>$viewer]],'connectors.targets.manage'),'viewer has no global management permission');
    $createdTarget=$connector->create($admin,['workspace_id'=>$ws,'provider'=>'proxmox','name'=>'PVE-01','endpoint_host'=>'pve.internal','endpoint_port'=>8006,'asset_id'=>$asset['id']]);
    $id=$createdTarget['id'];
    $ok($createdTarget['version']===1 && $createdTarget['asset_id']===$asset['id'],'real target persisted with inventory relation');
    $ok(count($connector->list($admin,$ws))===1,'workspace target listed');
    $reject(404,fn()=>$connector->show($other,$id),'other workspace target hidden');
    $reject(403,fn()=>$connector->create($viewer,['workspace_id'=>$ws,'provider'=>'proxmox','name'=>'no','endpoint_host'=>'host','endpoint_port'=>8006]),'viewer cannot create');
    $reject(404,fn()=>$connector->create($admin,['workspace_id'=>$ws2,'provider'=>'ssh','name'=>'no','endpoint_host'=>'host','endpoint_port'=>22]),'owner cannot create in other workspace');
    $reject(403,fn()=>$connector->bind($admin,$id,$ssh['id'],'ssh',1),'provider mismatch refused');
    $reject(403,fn()=>$connector->bind($admin,$id,$token['id'],'ssh',1),'purpose mismatch refused');
    $link=$connector->bind($admin,$id,$token['id'],'api',1);
    $ok($link['credential_id']===$token['id'],'real connector binding inserted');
    $ok(count($connector->listBindings($admin,$id))===1,'bindings query returns row');
    $ok($connector->show($admin,$id)['version']===2,'binding increments optimistic target version');
    $reject(412,fn()=>$connector->unbind($admin,$id,$link['id'],1),'stale ETag cannot detach');
    $reject(409,fn()=>$connector->bind($admin,$id,$token['id'],'api',2),'duplicate purpose forbidden');
    $reject(409,fn()=>$connector->delete($admin,$id,2),'target with bindings cannot be deleted');
    $reject(409,fn()=>$store->archive($admin,$token['id'],$token['version']),'credential cannot be archived while bound to connector');
    $metadata=$connector->resolveActive($admin,$id,'api');
    $ok($metadata['credential_id']===$token['id'] && !isset($metadata['encrypted_payload'],$metadata['secret']),'trusted resolution is metadata-only');
    $disabled=$store->update($admin,$token['id'],$token['version'],['status'=>'disabled']);
    $reject(404,fn()=>$connector->resolveActive($admin,$id,'api'),'revoked secret unusable');
    $sqlReject(fn()=>$pdo->prepare("INSERT INTO connector_credential_bindings(workspace_id,connector_id,credential_id,purpose) VALUES(:ws,:id,:cred,'api')")
        ->execute(['ws'=>$ws,'id'=>$id,'cred'=>$ssh['id']]),'direct SQL cannot link wrong provider or inactive credential');
    $enabled=$store->update($admin,$token['id'],$disabled['version'],['status'=>'active']);
    $ok($connector->resolveActive($admin,$id,'api')['credential_id']===$token['id'],'re-enabled secret can be resolved');
    $reject(409,fn()=>$inventory->delete($admin,$asset['id'],$asset['version']),'linked connector blocks asset soft-delete');
    $connector->unbind($admin,$id,$link['id'],2);
    $ok($connector->listBindings($admin,$id)===[],'bind detached and audited');
    $updated=$connector->update($admin,$id,3,['state'=>'disabled']);
    $ok($updated['version']===4 && $updated['state']==='disabled','connector disable persisted');
    $reject(409,fn()=>$connector->bind($admin,$id,$token['id'],'api',4),'disabled target cannot receive new binding');
    $connector->delete($admin,$id,4);
    $ok($connector->list($admin,$ws)===[],'connector target deleted after detaching');
    $ok((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action LIKE 'connector.%'")->fetchColumn()>=4,'audit records real target and binding events');
    echo "PASS: $checks PostgreSQL Vault workspace/Inventory/Connector bindings integration assertions.\n";
}catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL vault-bindings-postgres: '.$e::class.': '.$e->getMessage()."\n");}
finally{
    if($pdo instanceof PDO&&$created){try{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
        catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL schema cleanup: '.$e->getMessage()."\n");}}
    foreach(glob($dir.'/migrations/*')?:[] as $file)@unlink($file);
    @rmdir($dir.'/migrations');@rmdir($dir);
}
exit($failed?1:0);
