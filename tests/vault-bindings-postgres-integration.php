#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Destructive tests run ONLY in a separate disposable schema in an explicitly allowed test DB. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Connector\ConnectorBindingService;
use Cajeer\Servers\Connector\ConnectorRuntimeService;
use Cajeer\Servers\Connector\ProxmoxConnector;
use Cajeer\Servers\Credentials\SecretAccessService;
use Cajeer\Servers\Auth\TokenCodec;
use Cajeer\Servers\Security\SecretBox;
use Cajeer\Servers\Security\RateLimiter;
use Cajeer\Servers\Security\TwoFactorManager;
use Cajeer\Servers\Credentials\CredentialStore;
use Cajeer\Servers\Credentials\CredentialVersionService;
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
use Cajeer\Servers\Inventory\HistoryService;
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
    $reject(409,fn()=>$connector->runtimeContext($admin,$id,2),'remote execution requires matching Inventory binding');
    $bindingSvc->attach($admin,$asset['id'],$token['id'],'api');
    $context=$connector->runtimeContext($admin,$id,2);
    $ok($context['asset_id']===$asset['id'] && $context['credential_id']===$token['id'],'dual binding authorizes live execution');

    // 0.4.0: real PostgreSQL validation/activation gate. A staged Proxmox
    // secret is never activated merely on a client-provided confirmed=true.
    $versions=new CredentialVersionService($db,$repo,$vault,$crypto,$audit);
    $pending=$versions->stage($admin,$token['id'],$token['version'],[
        'token_id'=>'root@pam!sync','token_secret'=>'rotated-test-secret-value-123456']);
    $ok($pending['state']==='pending' && $pending['version']===2,'staging persists a pending encrypted version');
    $historyRows=$versions->history($admin,$token['id'])['data'];
    $pendingRow=array_values(array_filter($historyRows,static fn(array $r):bool=>$r['state']==='pending'))[0];
    $ok($pendingRow['required_targets']===1 && $pendingRow['validated_targets']===0 && !$pendingRow['activation_ready'],
        'real connector binding makes verified activation mandatory');
    $reject(409,fn()=>$versions->activate($admin,$token['id'],2,$pending['secret_version'],true),
        'manual confirmation cannot bypass missing remote validation');
    $reject(409,fn()=>$versions->markValidated($admin,$token['id'],$pending['secret_version'],1,$id,2),
        'stale credential revision cannot be recorded as proof');
    $reject(409,fn()=>$versions->markValidated($admin,$token['id'],$pending['secret_version'],2,$id,1),
        'stale connector revision cannot be recorded as proof');
    // Exercise the full trusted path: MFA -> one-use grant -> pending decrypt ->
    // Proxmox discovery adapter -> durable validation proof. The test transport
    // uses synthetic API responses; production transport is real verified HTTPS.
    $password='test-only-not-production-123!';
    $pdo->prepare('UPDATE users SET two_factor_enabled=true WHERE id=CAST(:actor AS uuid)')->execute(['actor'=>$admin]);
    $securityKey=new SecretBox('test-app-key-separate-from-vault-'.bin2hex(random_bytes(20)));
    $rateLimiter=new RateLimiter($securityKey);
    $mfa=new TwoFactorManager($db,$securityKey,$audit,$rateLimiter);
    $totpKey=random_bytes(20);
    $encryptedTotp=$securityKey->seal(TwoFactorManager::base32($totpKey),'mfa:'.$admin);
    $pdo->prepare("INSERT INTO user_mfa(user_id,secret_ciphertext,enabled_at)
        VALUES(CAST(:id AS uuid),decode(:cipher,'hex'),now())")
        ->execute(['id'=>$admin,'cipher'=>bin2hex($encryptedTotp)]);
    $session=TokenCodec::issue('cs1');
    $pdo->prepare("INSERT INTO auth_sessions(id,user_id,secret_hash,csrf_hash,expires_at,mfa_verified)
        VALUES(CAST(:session AS uuid),CAST(:actor AS uuid),:hash,:csrf,now()+interval '1 hour',true)")
        ->execute(['session'=>$session['id'],'actor'=>$admin,'hash'=>$session['hash'],'csrf'=>hash('sha256','test-csrf')]);
    $principal=['mode'=>'session','id'=>$session['id'],'user'=>['id'=>$admin],'mfa_verified'=>true];
    $access=new SecretAccessService($db,$repo,$rbac,$mfa,$rateLimiter,$audit,$vault,$crypto);
    $remoteCalls=0;
    $transport=static function(string $url,string $authorization,?string $caFile)use(&$remoteCalls,$ok):string {
        $ok(str_contains($authorization,'rotated-test-secret-value-123456'),
            'remote adapter receives staged plaintext, not the old active secret');
        $remoteCalls++;
        if (str_ends_with($url,'/api2/json/nodes'))return json_encode(['data'=>[['node'=>'pve-one','status'=>'online']]]);
        return json_encode(['data'=>[['type'=>'qemu','node'=>'pve-one','vmid'=>500,'status'=>'running','name'=>'vm-500']]]);
    };
    $history=new HistoryService($db,$repo,$inventory,$audit);
    $runtime=new ConnectorRuntimeService($db,$repo,$connector,$access,$history,$audit,
        new ProxmoxConnector($transport),null,$versions);
    $reject(403,fn()=>$runtime->validatePending($principal,$id,2,$pending['secret_version']),
        'remote validation cannot run without a fresh MFA grant');
    $totp=TwoFactorManager::totp($totpKey,time());
    $ok($access->authorize($principal,$asset['id'],$token['id'],'api',$password,$totp)['authorized'],
        'real password and TOTP issue scoped one-time grant');
    $countBefore=(int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn();
    $proof=$runtime->validatePending($principal,$id,2,$pending['secret_version']);
    $ok($proof['validated'] && $proof['validated_targets']===1 && $remoteCalls===2,
        'pending secret checked by real connector code and proof stored');
    $ok((int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn()===$countBefore,
        'validation does not write to inventory');
    $reject(403,fn()=>$runtime->validatePending($principal,$id,2,$pending['secret_version']),
        'validation cannot replay a consumed Vault grant');
    $historyRows=$versions->history($admin,$token['id'])['data'];
    $pendingRow=array_values(array_filter($historyRows,static fn(array $r):bool=>$r['state']==='pending'))[0];
    $ok($pendingRow['activation_ready']===true && $pendingRow['validated_targets']===1,'history reflects real server-side proof');
    // Ten-minute expiry is enforced by PostgreSQL, not by the browser UI.
    $pdo->exec("UPDATE vault_rotation_validations SET validated_at=clock_timestamp()-interval '11 minutes'");
    $reject(409,fn()=>$versions->activate($admin,$token['id'],2,$pending['secret_version'],true),
        'expired connector validation cannot activate pending credential');
    $expired=array_values(array_filter($versions->history($admin,$token['id'])['data'],
        static fn(array $r):bool=>$r['state']==='pending'))[0];
    $ok($expired['validated_targets']===0 && !$expired['activation_ready'],
        'API history shows expired proof as invalid');
    $pdo->exec('UPDATE vault_rotation_validations SET validated_at=clock_timestamp()');
    $ok($versions->activate($admin,$token['id'],2,$pending['secret_version'],true)['state']==='active',
        'validated pending secret atomically replaces active version');
    $token=$store->show($admin,$token['id']);
    $ok($token['version']===3 && $token['secret_version']===$pending['secret_version'],
        'active credential revision and active secret revision remain synchronized');
    $ok((int)$pdo->query('SELECT count(*) FROM vault_rotation_validations')->fetchColumn()===0,
        'proof metadata removed after atomic activation');
    $reject(412,fn()=>$connector->runtimeContext($admin,$id,1),'remote execution refuses stale target');
    $observation=static fn(string $external,string $type,string $name):array=>[
        'workspace_id'=>$ws,'external_id'=>'proxmox:'.$id.':'.$external,
        'event_key'=>'pve.'.bin2hex(random_bytes(16)),
        'type'=>$type,'fields'=>['name'=>$name,'status'=>'online']];
    $results=$history->observeMany($admin,[
        $observation('node:pve-one','hypervisor_node','pve-one'),
        $observation('qemu:500','virtual_machine','test-vm')]);
    $ok(count($results)===2 && $results[0]['outcome']==='created' && $results[1]['outcome']==='created',
        'atomic Proxmox discovery stored in Inventory using observation engine');
    $duplicate=$history->observeMany($admin,[$observation('qemu:500','virtual_machine','test-vm')]);
    $ok($duplicate[0]['outcome']==='unchanged' || $duplicate[0]['outcome']==='stale',
        'stable external identity prevents duplicate assets');
    $reject(409,fn()=>$history->observeMany($admin,[
        $observation('node:rollback','hypervisor_node','rollback'),
        $observation('node:rollback','container','should-not-exist')]),
        'type mismatch rolls back the entire discovered batch');
    $stmt=$pdo->prepare("SELECT count(*) FROM inventory_assets WHERE workspace_id=:ws AND external_id=:external");
    $stmt->execute(['ws'=>$ws,'external'=>'proxmox:'.$id.':node:rollback']);
    $ok((int)$stmt->fetchColumn()===0,'failed Proxmox import leaves no partial assets');

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
    $asset2=$inventory->create($admin,['workspace_id'=>$ws,'type'=>'server','name'=>'Second target']);
    $second=$connector->create($admin,['workspace_id'=>$ws,'provider'=>'proxmox','name'=>'PVE-02',
        'endpoint_host'=>'pve-02.internal','endpoint_port'=>8006,'asset_id'=>$asset2['id']]);
    $link2=$connector->bind($admin,$second['id'],$token['id'],'api',1,null,true);
    $ok($connector->runtimeContext($admin,$second['id'],2)['asset_id']===$asset2['id'],
        'one transaction binds the connector and its Inventory asset');
    $connector->unbind($admin,$second['id'],$link2['id'],2);
    $connector->delete($admin,$second['id'],3);

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
