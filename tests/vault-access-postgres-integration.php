#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Real isolated PostgreSQL 17+ test: MFA + binding + grant + one-time internal decryption. */
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
$root=dirname(__DIR__);$schema='cajeer_use_'.bin2hex(random_bytes(7));
$tmp=sys_get_temp_dir().'/cajeer-access-'.bin2hex(random_bytes(7));
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
    $inventory=new InventoryService($db,new InventoryRepository(),$auditor);
    $asset=$inventory->create($admin,['workspace_id'=>$workspace,'type'=>'server','name'=>'Vault secure-use test asset'])['id'];
    $key=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));
    $crypto=new VaultCrypto($key);$vault=new CredentialsVault($db,$crypto,$key);
    $ok($vault->initialize()['initialized']===true,'vault initialized');
    $ok($vault->check()['ok']===true,'vault verifier works');
    $store=new CredentialStore($db,new InventoryRepository(),$vault,$crypto,$auditor);
    $secret='test-only-secret-'.bin2hex(random_bytes(16));
    $cred=$store->create($admin,['workspace_id'=>$workspace,'name'=>'Test credential','type'=>'api_token','secret'=>$secret]);
    $bind=new CredentialBindingService($db,new InventoryRepository(),$auditor);
    $binding=$bind->attach($admin,$asset,$cred['id'],'api');
    $ok($binding['credential_id']===$cred['id'],'asset credential is bound');
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
    $access=new SecretAccessService($db,new InventoryRepository(),new Rbac($db,$auditor),$mfa,$limiter,$auditor,$vault,$crypto);
    $ok(SecretAccessService::allowsPurpose('api_token','api'),'compatible type and purpose');
    $reject(403,fn()=>$access->consumeForAsset($principal,$asset,$cred['id'],'api',fn()=>true),'no grant cannot consume');
    $totp=TwoFactorManager::totp($totpKey,time());
    $reject(403,fn()=>$access->authorize($principal,$asset,$cred['id'],'ssh',$password,$totp),'wrong purpose denied');
    $reject(403,fn()=>$access->authorize($principal,$asset,$cred['id'],'api','wrong password',$totp),'wrong password denied');
    $approved=$access->authorize($principal,$asset,$cred['id'],'api',$password,$totp);
    $ok($approved===['authorized'=>true,'expires_in'=>120],'real one-use grant inserted');
    $stmt=$pdo->prepare('SELECT count(*) FROM vault_use_grants WHERE session_id=:id AND consumed_at IS NULL');
    $stmt->execute(['id'=>$session['id']]);
    $ok((int)$stmt->fetchColumn()===1,'pending grant persisted');
    $proof='';
    $return=$access->consumeForAsset($principal,$asset,$cred['id'],'api',static function(string $bytes,string $type)use(&$proof):string {
        $proof=$type==='api_token'?hash_hmac('sha256','internal-test-challenge',$bytes):'incorrect-type';
        return 'this result must not be returned';
    });
    $ok($return===null,'trusted callback result never passed to caller');
    $ok($proof===hash_hmac('sha256','internal-test-challenge',$secret),'authorized trusted handler received decrypted secret');
    $stmt->execute(['id'=>$session['id']]);
    $ok((int)$stmt->fetchColumn()===0,'grant consumed exactly once');
    $reject(403,fn()=>$access->consumeForAsset($principal,$asset,$cred['id'],'api',fn()=>true),'replay rejected');
    $reject(403,fn()=>$access->authorize($principal,$asset,$cred['id'],'api',$password,$totp),'TOTP replay rejected');
    // Accept a next-window RFC 6238 code (server allows +/-1 window), then invalidate the grant.
    $nextTotp=TwoFactorManager::totp($totpKey,time()+30);
    $ok($access->authorize($principal,$asset,$cred['id'],'api',$password,$nextTotp)['authorized']===true,
        'fresh next-window TOTP authorizes a new grant');
    $store->update($admin,$cred['id'],1,['status'=>'disabled']);
    $reject(403,fn()=>$access->consumeForAsset($principal,$asset,$cred['id'],'api',fn()=>true),
        'credential state/version change invalidates previously issued grant');
    $events=$pdo->query("SELECT action FROM audit_log WHERE action LIKE 'vault.credential.%' ORDER BY created_at")?->fetchAll(PDO::FETCH_COLUMN);
    $ok(in_array('vault.credential.use_authorized',$events,true),'grant issued audit recorded');
    $ok(in_array('vault.credential.use_denied',$events,true),'denied use audit recorded');
    $ok(in_array('vault.credential.accessed',$events,true),'actual internal decrypt audit recorded');
    $stmt=$pdo->prepare('SELECT count(*) FROM audit_log WHERE payload::text LIKE :secret');
    $stmt->execute(['secret'=>'%'.$secret.'%']);
    $ok((int)$stmt->fetchColumn()===0,'plain secret not in audit payload');
    $pdo->prepare('UPDATE users SET is_active=false WHERE id=:id')->execute(['id'=>$admin]);
    $reject(403,fn()=>$access->consumeForAsset($principal,$asset,$cred['id'],'api',fn()=>true),'revoked user cannot consume');
    echo "Vault access PostgreSQL integration: $checks PASS\n";
}catch(Throwable $e){$error=$e;fwrite(STDERR,'FAIL: '.get_class($e).': '.$e->getMessage()."\n");}
finally{
    if($pdo instanceof PDO && $created){
        try{$pdo->exec('SET search_path TO public');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
        catch(Throwable $cleanup){fwrite(STDERR,'Cleanup failed: '.$cleanup->getMessage()."\n");$error??=$cleanup;}
    }
    foreach(glob($tmp.'/migrations/*.sql')?:[] as $file)@unlink($file);
    @rmdir($tmp.'/migrations');@rmdir($tmp);
}
exit($error===null?0:1);
