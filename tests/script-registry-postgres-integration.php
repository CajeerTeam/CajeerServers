#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Real isolated PostgreSQL 17+ test: signed script registry and trust lifecycle. */
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
if (!extension_loaded('pdo_pgsql') || !extension_loaded('sodium')) {
    fwrite(STDERR,"FAIL: ext-pdo_pgsql and ext-sodium required.\n");exit(1);
}
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
    $script=$registry->create($principal,$workspace,['name'=>'Linux test','description'=>'synthetic']);
    $ok($registry->list($principal,$workspace)[0]['id']===$script['id'],'registry persistence');
    $registry->update($principal,$workspace,$script['id'],1,['name'=>'Renamed']);
    $reject(412,fn()=>$registry->update($principal,$workspace,$script['id'],1,['name'=>'stale']),'optimistic concurrency');
    $foreign=$pdo->query("INSERT INTO workspaces(organization_id,slug,name) SELECT organization_id,'scripts-private','Private' FROM workspaces WHERE id='$workspace' RETURNING id")->fetchColumn();
    $reject(404,fn()=>$registry->list($principal,$foreign),'workspace isolation');
    $vp=['mode'=>'session','user'=>['id'=>$viewer]];
    $reject(403,fn()=>$registry->create($vp,$workspace,['name'=>'illegal']),'RBAC denial');
    $key=$registry->addKey($principal,$workspace,['name'=>'offline','public_key'=>$pub]+$step(-30));
    $version=$registry->addVersion($principal,$workspace,$script['id'],['language'=>'python','provider'=>'local','source'=>'print(42)','parameters'=>[['name'=>'count','type'=>'integer','min'=>1,'max'=>10]]]);
    $id=$version['id'];$scriptId=$script['id'];
    $reject(409,fn()=>$registry->validate($principal,$workspace,$scriptId,$id,['parameters'=>['count'=>2]]),'draft not executable');
    $reject(422,fn()=>$registry->sign($principal,$workspace,$scriptId,$id,['key_id'=>$key['id'],'signature'=>str_repeat('0',128)]),'wrong signature');
    $signature=bin2hex(sodium_crypto_sign_detached($version['signing_bytes'],$priv));
    $signed=$registry->sign($principal,$workspace,$scriptId,$id,['key_id'=>$key['id'],'signature'=>$signature]);
    $ok($signed['state']==='pending_review','signature persisted');
    $reject(403,fn()=>$registry->review($principal,$workspace,$scriptId,$id,['password'=>'wrong','totp'=>$step(0)['totp']],true),'bad password');
    $remoteScript=$registry->create($principal,$workspace,['name'=>'SNMP test']);
    $remoteId=$pdo->query("INSERT INTO connector_targets(workspace_id,provider,name,endpoint_host,endpoint_port) VALUES('$workspace','snmp','Test router','127.0.0.1',161) RETURNING id")->fetchColumn();
    $remote=$registry->addVersion($principal,$workspace,$remoteScript['id'],['template'=>'snmp','target_ids'=>[$remoteId]]);
    $manifest=json_decode($remote['signing_bytes'],true,32,JSON_THROW_ON_ERROR);
    $ok($manifest['target_revisions']=== [['id'=>$remoteId,'revision'=>'1']],'target revision is signed');
    $reject(409,fn()=>$registry->addVersion($principal,$workspace,$remoteScript['id'],['template'=>'linux','target_ids'=>[$remoteId]]),'provider mismatch');
    $registry->sign($principal,$workspace,$remoteScript['id'],$remote['id'],['key_id'=>$key['id'],'signature'=>bin2hex(sodium_crypto_sign_detached($remote['signing_bytes'],$priv))]);
    $pdo->exec("UPDATE connector_targets SET version=2 WHERE id='$remoteId'");
    $reject(409,fn()=>$registry->review($principal,$workspace,$remoteScript['id'],$remote['id'],$step(0),true),'target revision changed after signing');
    $approved=$registry->review($principal,$workspace,$scriptId,$id,$step(0),true);$ok($approved['state']==='approved','fresh MFA approval');
    $reject(403,fn()=>$registry->addKey($principal,$workspace,['name'=>'replay','public_key'=>$pub]+$step(0)),'TOTP replay denied');
    $ok($registry->validate($principal,$workspace,$scriptId,$id,['parameters'=>['count'=>2]])['valid'],'approved version validates');
    $reject(422,fn()=>$registry->validate($principal,$workspace,$scriptId,$id,['parameters'=>['count'=>99]]),'invalid parameter');
    $second=$registry->addVersion($principal,$workspace,$scriptId,['language'=>'bash','provider'=>'local','source'=>'echo 42']);$ok((int)$second['version']===2,'immutable new version');
    try{$pdo->exec("UPDATE script_versions SET source='tampered' WHERE id='$id'");throw new RuntimeException('immutable source mutation accepted');}catch(PDOException $e){$ok($e->getCode()==='P0001','database immutable trigger');}
    $registry->revokeKey($principal,$workspace,$key['id'],$step(30));
    $ok($registry->getVersion($principal,$workspace,$scriptId,$id)['state']==='revoked','key revokes approved version');
    $reject(409,fn()=>$registry->validate($principal,$workspace,$scriptId,$id,['parameters'=>['count'=>2]]),'revocation enforced');
    $registry->update($principal,$workspace,$scriptId,2,[],true);
    $reject(409,fn()=>$registry->addVersion($principal,$workspace,$scriptId,['language'=>'bash','provider'=>'local','source'=>'echo 42']),'archive blocks new version');
    sodium_memzero($priv);
    echo "Script Registry PostgreSQL: $checks checks passed.\n";
} catch(Throwable $e){$error=$e;fwrite(STDERR,$e->getMessage()."\n");}
finally{if($created&&$pdo){$pdo->exec('SET search_path TO public');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}foreach(glob($tmp.'/migrations/*')?:[] as $f)unlink($f);rmdir($tmp.'/migrations');rmdir($tmp);}
exit($error?1:0);
