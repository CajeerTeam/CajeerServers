#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Real isolated PostgreSQL 17+ test: signed script execution, Vault and worker lifecycle. */
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Auth\TokenCodec;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Credentials\CredentialStore;
use Cajeer\Servers\Credentials\CredentialsVault;
use Cajeer\Servers\Credentials\SecretAccessService;
use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\CredentialBindingService;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\InventoryService;
use Cajeer\Servers\Security\RateLimiter;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Security\SecretBox;
use Cajeer\Servers\Security\TwoFactorManager;

if (getenv('CAJEER_PG_TEST')!=='1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')) {
    fwrite(STDERR,"REFUSED: requires CAJEER_PG_TEST=1 and disposable cajeer_test_* database.\n"); exit(2);
}
if (!extension_loaded('pdo_pgsql') || !extension_loaded('sodium') || !function_exists('pcntl_alarm') || posix_geteuid()===0) {
    fwrite(STDERR,"FAIL: non-root PHP CLI, ext-pdo_pgsql, sodium and pcntl required.\n");exit(1);
}
putenv('SCRIPT_EXECUTION_ENABLED=true');
$root=dirname(__DIR__);$schema='cajeer_scripts_'.bin2hex(random_bytes(7));
$tmp=sys_get_temp_dir().'/cajeer-scripts-'.bin2hex(random_bytes(7));
mkdir($tmp.'/migrations',0700,true);
$checks=0;$pdo=null;$created=false;$error=null;
$ok=static function(bool $valid,string $label)use(&$checks):void{
    if(!$valid)throw new RuntimeException('FAIL: '.$label);
    $checks++;
};
$reject=static function(int $status,callable $callback,string $label)use($ok):void{
    try{$callback();}catch(HttpException $e){$ok($e->status===$status,$label);return;}
    throw new RuntimeException('Expected HTTP '.$status.': '.$label);
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
    $pdo->exec('SET search_path TO "'.$schema.'",public');
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $path)
        copy($path,$tmp.'/migrations/'.basename($path));
    $migration=new MigrationRunner($db,$tmp.'/migrations');
    $ok(count($migration->run()['applied'])===count(glob($root.'/migrations/pgsql/*.sql')),'all migrations applied');
    $ok($migration->validate()['valid']===true && $migration->run()['applied']===[],'migrations idempotent');
    $password='ci-only-strong-password-never-production-123!';
    $seed=$pdo->prepare('INSERT INTO users(email,name,password_hash,two_factor_enabled) VALUES(:email,:name,:hash,:mfa) RETURNING id::text');
    $newUser=static function(string $name,bool $mfa)use($seed,$password):string{
        $seed->execute(['email'=>$name.bin2hex(random_bytes(5)).'@example.invalid','name'=>$name,
            'hash'=>password_hash($password,PASSWORD_ARGON2ID),'mfa'=>$mfa?'true':'false']);
        return (string)$seed->fetchColumn();
    };
    $admin=$newUser('admin',true);$viewer=$newUser('viewer',true);
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :actor,id FROM roles WHERE slug='administrator'")->execute(['actor'=>$admin]);
    $workspace=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $join=$pdo->prepare('INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(CAST(:ws AS uuid),CAST(:actor AS uuid),:role)');
    $join->execute(['ws'=>$workspace,'actor'=>$admin,'role'=>'owner']);
    $join->execute(['ws'=>$workspace,'actor'=>$viewer,'role'=>'viewer']);
    $auditor=new AuditLogger($db);
    $securityKey=new SecretBox('CIAPPKEY-not-reused-for-vault-'.bin2hex(random_bytes(20)));
    $limiter=new RateLimiter($securityKey);
    $mfa=new TwoFactorManager($db,$securityKey,$auditor,$limiter);
    $totpKey=random_bytes(20);
    $mfaCipher=$securityKey->seal(TwoFactorManager::base32($totpKey),'mfa:'.$admin);
    $enroll=$pdo->prepare("INSERT INTO user_mfa(user_id,secret_ciphertext,enabled_at) VALUES(CAST(:id AS uuid),decode(:cipher,'hex'),now())");
    $enroll->execute(['id'=>$admin,'cipher'=>bin2hex($mfaCipher)]);
    $session=TokenCodec::issue('cs1');
    $pdo->prepare("INSERT INTO auth_sessions(id,user_id,secret_hash,csrf_hash,expires_at,mfa_verified) VALUES(:id,:user,:hash,:csrf,now()+interval '12 hours',true)")
        ->execute(['id'=>$session['id'],'user'=>$admin,'hash'=>$session['hash'],'csrf'=>hash('sha256','test-csrf')]);
    $principal=['mode'=>'session','id'=>$session['id'],'user'=>['id'=>$admin],'mfa_verified'=>true];
    $registry=new \Cajeer\Servers\Script\ScriptRegistry($db,new Rbac($db,$auditor),$auditor,$mfa,$limiter,new \Cajeer\Servers\Script\ScriptTemplate());
    $pair=sodium_crypto_sign_keypair();$pub=bin2hex(sodium_crypto_sign_publickey($pair));$priv=sodium_crypto_sign_secretkey($pair);
    $step=fn(int $delta)=>['password'=>$password,'totp'=>TwoFactorManager::totp($totpKey,time()+$delta)];
    $enrollRunner=function(string $name)use($newUser,$pdo,$join,$workspace,$securityKey){
        $id=$newUser($name,true);$pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :actor,id FROM roles WHERE slug='administrator'")->execute(['actor'=>$id]);
        $join->execute(['ws'=>$workspace,'actor'=>$id,'role'=>'manager']);$key=random_bytes(20);
        $pdo->prepare("INSERT INTO user_mfa(user_id,secret_ciphertext,enabled_at) VALUES(:id,decode(:cipher,'hex'),now())")->execute(['id'=>$id,'cipher'=>bin2hex($securityKey->seal(TwoFactorManager::base32($key),'mfa:'.$id))]);
        $sess=TokenCodec::issue('cs1');$pdo->prepare("INSERT INTO auth_sessions(id,user_id,secret_hash,csrf_hash,expires_at,mfa_verified) VALUES(:id,:user,:hash,:csrf,now()+interval '1 hour',true)")->execute(['id'=>$sess['id'],'user'=>$id,'hash'=>$sess['hash'],'csrf'=>hash('sha256','test-csrf')]);
        return [['mode'=>'session','id'=>$sess['id'],'user'=>['id'=>$id],'mfa_verified'=>true],$key];
    };
    [$runner,$runnerKey]=$enrollRunner('runner');[$other,$otherKey]=$enrollRunner('other');
    $repo=new InventoryRepository();$inventory=new InventoryService($db,$repo,$auditor);$vaultKey=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));$crypto=new VaultCrypto($vaultKey);$vault=new CredentialsVault($db,$crypto,$vaultKey);$vault->initialize();
    $store=new CredentialStore($db,$repo,$vault,$crypto,$auditor);$asset=$inventory->create($admin,['workspace_id'=>$workspace,'type'=>'server','name'=>'PVE target']);
    $credential=$store->create($admin,['workspace_id'=>$workspace,'name'=>'PVE script test','type'=>'proxmox_api','secret'=>['token_id'=>'test@pam!read','token_secret'=>'test-only-secret-123456']]);
    $calls=0;$hook=null;
    $transport=static function(string $url,string $authorization,?string $caFile)use(&$calls,&$hook,$ok):string{
        $ok(str_contains($authorization,'test-only-secret-123456'),'Vault secret passed only to driver');$calls++;if($hook!==null)$hook();
        return str_ends_with($url,'/nodes')?json_encode(['data'=>[['node'=>'pve','status'=>'online']]]):json_encode(['data'=>[['type'=>'qemu','node'=>'pve','vmid'=>101,'name'=>'vm','status'=>'running']]]);
    };
    $driverRegistry=new \Cajeer\Servers\Integration\ConnectorRegistry(new \Cajeer\Servers\Connector\ProxmoxConnector($transport));
    $bindings=new \Cajeer\Servers\Connector\ConnectorBindingService($db,$repo,$auditor,$driverRegistry);
    $target=$bindings->create($admin,['workspace_id'=>$workspace,'provider'=>'proxmox','name'=>'PVE','endpoint_host'=>'pve.internal','endpoint_port'=>8006,'asset_id'=>$asset['id']]);
    $bindings->bind($admin,$target['id'],$credential['id'],'api',1);(new CredentialBindingService($db,$repo,$auditor))->attach($admin,$asset['id'],$credential['id'],'api');
    $rbac=new Rbac($db,$auditor);$access=new SecretAccessService($db,$repo,$rbac,$mfa,$limiter,$auditor,$vault,$crypto);
    $runtime=new \Cajeer\Servers\Connector\ConnectorRuntimeService($db,$repo,$bindings,$access,new \Cajeer\Servers\Inventory\HistoryService($db,$repo,$inventory,$auditor),$auditor,$driverRegistry);
    $cfg=new ConfigRepository($root.'/config');$exec=new \Cajeer\Servers\Script\ScriptExecution($db,$registry,new \Cajeer\Servers\Script\ScriptRunner($cfg),$runtime,$bindings,$auditor,$cfg);
    $key=$registry->addKey($principal,$workspace,['name'=>'offline','public_key'=>$pub]+$step(-30));
    $script=$registry->create($principal,$workspace,['name'=>'PVE preview']);$sid=$script['id'];
    $version=$registry->addVersion($principal,$workspace,$sid,['template'=>'proxmox','target_ids'=>[$target['id']]]);$vid=$version['id'];
    $registry->sign($principal,$workspace,$sid,$vid,['key_id'=>$key['id'],'signature'=>bin2hex(sodium_crypto_sign_detached($version['signing_bytes'],$priv))]);
    $registry->review($principal,$workspace,$sid,$vid,$step(0),true);
    $local=$registry->addVersion($principal,$workspace,$sid,['language'=>'python','provider'=>'local','source'=>'print("{}")']);
    $registry->sign($principal,$workspace,$sid,$local['id'],['key_id'=>$key['id'],'signature'=>bin2hex(sodium_crypto_sign_detached($local['signing_bytes'],$priv))]);$registry->review($principal,$workspace,$sid,$local['id'],$step(30),true);
    $body=['mode'=>'preview','target_id'=>$target['id'],'parameters'=>[],'password'=>$password,'totp'=>TwoFactorManager::totp($runnerKey,time())];
    $reject(403,fn()=>$exec->enqueue($runner,$workspace,$sid,$vid,$body,'test-no-grant'),'remote preview requires Vault grant');
    $access->authorize($runner,$asset['id'],$credential['id'],'api',$password,TwoFactorManager::totp($runnerKey,time()-30));
    $job=$exec->enqueue($runner,$workspace,$sid,$vid,$body,'test-1');$ok($job['state']==='queued'&&!isset($job['session_id'],$job['scope']),'durable queued job, safe response');
    $ok($exec->enqueue($runner,$workspace,$sid,$vid,$body,'test-1')['id']===$job['id'],'idempotent retry, no extra MFA');
    $reject(409,fn()=>$exec->enqueue($runner,$workspace,$sid,$vid,array_replace($body,['mode'=>'execute']),'test-1'),'idempotency conflict');
    $reject(404,fn()=>$exec->detail($other,$workspace,$sid,$vid,$job['id']),'other actor cannot read result');
    $count=(int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn();
    $ok($exec->work(1)===1,'actual worker claims and executes one job');$done=$exec->detail($runner,$workspace,$sid,$vid,$job['id']);
    $ok($done['state']==='succeeded'&&$done['result']->output->count===2&&$done['result']->normalized->count===2&&$done['result']->normalized->schema==='observations'&&$calls===2,'actual signed remote preview result');
    $ok((int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn()===$count,'no Inventory writes');
    $ok($exec->work(1)===0&&$calls===2,'no replay of completed job');
    $localBody=['mode'=>'execute','parameters'=>[],'password'=>$password,'totp'=>TwoFactorManager::totp($runnerKey,time()+30)];
    $localJob=$exec->enqueue($runner,$workspace,$sid,$local['id'],$localBody,'test-cancel');
    $ok($exec->detail($runner,$workspace,$sid,$local['id'],$localJob['id'],true)['state']==='cancelled','cancel queued local job');
    $ok($exec->work(1)===0,'cancelled job never executes');
    $access->authorize($other,$asset['id'],$credential['id'],'api',$password,TwoFactorManager::totp($otherKey,time()-30));
    $job2=$exec->enqueue($other,$workspace,$sid,$vid,array_replace($body,['totp'=>TwoFactorManager::totp($otherKey,time())]),'test-revoke');
    $hook=function()use($pdo,$vid,&$hook){$hook=null;$pdo->prepare("UPDATE script_versions SET state='revoked',revoked_at=clock_timestamp() WHERE id=:v")->execute(['v'=>$vid]);};
    $ok($exec->work(1)===1,'worker processes revocation scenario');$failed=$exec->detail($other,$workspace,$sid,$vid,$job2['id']);$ok($failed['state']==='failed'&&$failed['result']===null,'revoked during I/O: no result commit');
    $ok((int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn()===$count,'preview never writes Inventory');
    sodium_memzero($priv);echo "Script execution PostgreSQL: $checks checks passed.\n";
} catch(Throwable $e){$error=$e;fwrite(STDERR,$e->getMessage()."\n");}
finally{if($created&&$pdo){$pdo->exec('SET search_path TO public');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}foreach(glob($tmp.'/migrations/*')?:[] as $f)unlink($f);rmdir($tmp.'/migrations');rmdir($tmp);putenv('SCRIPT_EXECUTION_ENABLED');}
exit($error?1:0);
