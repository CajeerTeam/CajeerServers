#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Run via vmmanager-power-https-integration.py --postgres; isolated schema, real TLS fixture and DB. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Auth\TokenCodec;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Connector\{ConnectorActionService,ConnectorBindingService,ConnectorRuntimeService,ConnectorExecutionService,EndpointPolicy,HttpsTransport,VmmanagerConnector,ProxmoxConnector};
use Cajeer\Servers\Credentials\{CredentialStore,CredentialVersionService,CredentialsVault,SecretAccessService,VaultCrypto,VaultMasterKey};
use Cajeer\Servers\Database\{ConnectionFactory,DatabaseManager,MigrationRunner};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Inventory\{HistoryService,InventoryRepository,InventoryService};
use Cajeer\Servers\Security\{RateLimiter,Rbac,SecretBox,TwoFactorManager};
if (getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')||$argc!==3||!ctype_digit($argv[1])||!is_file($argv[2])) {
    fwrite(STDERR,"REFUSED: disposable cajeer_test_* PostgreSQL and local HTTPS fixture required.\n"); exit(2);
}
if (!extension_loaded('pdo_pgsql')||!extension_loaded('sodium')) {fwrite(STDERR,"FAIL: pdo_pgsql and sodium required.\n");exit(1);}
$root=dirname(__DIR__);$schema='cajeer_vmm_'.bin2hex(random_bytes(8));$dir=sys_get_temp_dir().'/'.$schema;
mkdir($dir.'/migrations',0700,true);$pdo=null;$created=false;$failed=false;$n=0;
$ok=static function(bool $value,string $label)use(&$n):void {if(!$value)throw new RuntimeException('FAIL: '.$label);$n++;};
$reject=static function(int $status,callable $fn,string $label)use($ok):void {try{$fn();}catch(HttpException $e){$ok($e->status===$status,$label);return;}throw new RuntimeException('Expected rejection: '.$label);};
try {
    $db=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),[
        'driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1','port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432',
        'database'=>getenv('CAJEER_PG_TEST_DATABASE'),'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer',
        'password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'','sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer',
    ]);
    $pdo=$db->connection();$ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;$pdo->exec('SET search_path TO "'.$schema.'", public');
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file)if(!str_starts_with(basename($file),'0052_'))copy($file,$dir.'/migrations/'.basename($file));
    (new MigrationRunner($db,$dir.'/migrations'))->run();
    copy($root.'/migrations/pgsql/0052_vmmanager_safe_actions.sql',$dir.'/migrations/0052_vmmanager_safe_actions.sql');
    (new MigrationRunner($db,$dir.'/migrations'))->run();
    (new MigrationRunner($db,$dir.'/migrations'))->run();
    $password='fixture-only-password-123!';$q=$pdo->prepare('INSERT INTO users(email,name,password_hash,two_factor_enabled) VALUES(:email,:name,:hash,true) RETURNING id::text');
    $q->execute(['email'=>'vmm-test@example.invalid','name'=>'VMM integration','hash'=>password_hash($password,PASSWORD_ARGON2ID)]);$actor=(string)$q->fetchColumn();
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$actor]);
    $ws=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:id,'owner')")->execute(['ws'=>$ws,'id'=>$actor]);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$inventory=new InventoryService($db,$repo,$audit);$rbac=new Rbac($db,$audit);
    $key=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));$crypto=new VaultCrypto($key);$vault=new CredentialsVault($db,$crypto,$key);$vault->initialize();
    $store=new CredentialStore($db,$repo,$vault,$crypto,$audit);
    $transport=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],null,true));
    $registry=new ConnectorRegistry(new ProxmoxConnector(null,null,$transport),new VmmanagerConnector($transport,$argv[2]));
    $bindings=new ConnectorBindingService($db,$repo,$audit,$registry);
    $versions=new CredentialVersionService($db,$repo,$vault,$crypto,$audit,$registry);
    $secretBox=new SecretBox('vmm-test-app-key-'.bin2hex(random_bytes(24)));$limiter=new RateLimiter($secretBox);$mfa=new TwoFactorManager($db,$secretBox,$audit,$limiter);$totpKey=random_bytes(20);
    $pdo->prepare("INSERT INTO user_mfa(user_id,secret_ciphertext,enabled_at) VALUES(:id,decode(:cipher,'hex'),now())")
        ->execute(['id'=>$actor,'cipher'=>bin2hex($secretBox->seal(TwoFactorManager::base32($totpKey),'mfa:'.$actor))]);
    $session=TokenCodec::issue('cs1');
    $pdo->prepare('INSERT INTO auth_sessions(id,user_id,secret_hash,csrf_hash,expires_at,mfa_verified) VALUES(:session,:actor,:hash,:csrf,now()+interval \'1 hour\',true)')
        ->execute(['session'=>$session['id'],'actor'=>$actor,'hash'=>$session['hash'],'csrf'=>hash('sha256','fixture-csrf')]);
    $principal=['mode'=>'session','id'=>$session['id'],'user'=>['id'=>$actor],'mfa_verified'=>true];
    $access=new SecretAccessService($db,$repo,$rbac,$mfa,$limiter,$audit,$vault,$crypto);$history=new HistoryService($db,$repo,$inventory,$audit);
    $runtime=new ConnectorRuntimeService($db,$repo,$bindings,$access,$history,$audit,$registry,$versions);
    $execution=new ConnectorExecutionService($db,$bindings,$repo,$rbac,$access,$vault,$crypto,$audit,$runtime);
    $asset=$inventory->create($actor,['workspace_id'=>$ws,'type'=>'server','name'=>'VMmanager endpoint']);
    $credential=$store->create($actor,['workspace_id'=>$ws,'name'=>'VMmanager token','type'=>'vmmanager_api','secret'=>['token'=>'fixture-power-db']]);
    $profile=['primary_pin'=>openssl_x509_fingerprint(file_get_contents($argv[2]),'sha256'),'anchor_id'=>1,'anchor_node'=>'node-1'];
    $target=$bindings->create($actor,['workspace_id'=>$ws,'provider'=>'vmmanager','name'=>'VMmanager integration','endpoint_host'=>'127.0.0.1','endpoint_port'=>(int)$argv[1],'asset_id'=>$asset['id'],'vmmanager_profile'=>$profile]);
    $ok($target['vmmanager_profile']==$profile,'persisted VMmanager profile');
    $id=$target['id'];$cid=$credential['id'];
    $reject(409,fn()=>$bindings->update($actor,$id,1,['vmmanager_profile'=>array_replace($profile,['anchor_id'=>2])]),'cannot change identity');
    $reject(422,fn()=>$bindings->update($actor,$id,1,['vmmanager_profile'=>null]),'cannot remove trust');
    $reject(422,fn()=>$bindings->update($actor,$id,1,['endpoint_port'=>443]),'endpoint change requires trust');
    $reject(412,fn()=>$bindings->update($actor,$id,99,['vmmanager_profile'=>$profile]),'stale trust update');$bindings->bind($actor,$id,$cid,'api',1,null,true);
    $authorize=static function()use($pdo,$actor,$principal,$access,$asset,$cid,$password,$totpKey):void {
        // Test-only OTP reset; production never resets replay protection.
        $pdo->exec('DELETE FROM security_rate_limits');
        $pdo->prepare('UPDATE user_mfa SET last_counter=NULL WHERE user_id=:id')->execute(['id'=>$actor]);
        $access->authorize($principal,$asset['id'],$cid,'api',$password,TwoFactorManager::totp($totpKey,time()));
    };
    $actions=new ConnectorActionService($db,$bindings,$repo,$access,$registry,$rbac,$audit,true);
    $intent=static fn(string $op,int $host=21):array=>['action'=>'vmmanager.host.'.$op,'external_id'=>'host:'.$host,'confirm'=>'vmmanager.host.'.$op.' host:'.$host];
    $submit=fn(string $op,int $host,string $key)=>$actions->submit($principal,$id,2,$intent($op,$host),$key);
    $reject(409,fn()=>$submit('start',21,'db-missing-guest-0001'),'requires synchronized guest');
    $authorize();$guestPlan=$execution->createPlan($principal,$id,2,['name'=>'Guest inventory','collector'=>'vmmanager.guests','operation'=>'sync','ttl_seconds'=>3600,'interval_seconds'=>300]);
    $execution->runPlan($actor,$id,$guestPlan['id'],'db-guests-first');$ok($execution->once()['status']==='succeeded','guest sync worker');
    $authorize();$taskPlan=$execution->createPlan($principal,$id,2,['name'=>'Task history','collector'=>'vmmanager.tasks','operation'=>'inspect','ttl_seconds'=>3600,'interval_seconds'=>60]);
    $reject(403,fn()=>$submit('start',21,'db-no-grant-000001'),'requires one-time Vault grant');
    $pdo->exec("DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE scope='connectors.vmmanager.actions.execute')");
    $reject(403,fn()=>$submit('start',21,'db-no-permission-01'),'requires provider-specific permission');
    $pdo->exec("INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.scope='connectors.vmmanager.actions.execute'");
    $authorize();$start=$submit('start',21,'db-start-guest21-01');$ok($start['status']==='submitted'&&str_starts_with($start['remote_task'],'vmm:'),'durable intent and receipt');
    $ok($submit('start',21,'db-start-guest21-01')['id']===$start['id'],'idempotency replay');
    $reject(409,fn()=>$submit('start',21,'db-concurrent-00001'),'blocks competing action');
    $authorize();$ok($actions->refresh($principal,$id,$start['id'],2)['status']==='submitted','running task not success');
    $execution->runPlan($actor,$id,$taskPlan['id'],'db-tasks-start');$ok($execution->once()['status']==='succeeded'&&$actions->get($principal,$id,$start['id'])['status']==='succeeded','worker reconciles action atomically');
    $authorize();$restart=$submit('reboot',21,'db-reboot-guest21-1');$ok($restart['status']==='submitted','restart accepted');
    foreach(['db-reboot-poll1','db-reboot-poll2'] as $k){$execution->runPlan($actor,$id,$taskPlan['id'],$k);$ok($execution->once()['status']==='succeeded','restart worker');}
    $ok($actions->get($principal,$id,$restart['id'])['status']==='succeeded','both restart tasks completed');
    $authorize();$stop=$submit('stop',21,'db-stop-guest21-001');
    foreach(['submitted','succeeded'] as $expected){$authorize();$ok($actions->refresh($principal,$id,$stop['id'],2)['status']===$expected,'stop poll');}
    $authorize();$lxd=$submit('start',22,'db-start-guest22-01');
    foreach(['submitted','succeeded'] as $expected){$authorize();$ok($actions->refresh($principal,$id,$lxd['id'],2)['status']===$expected,'LXD poll');}
    $authorize();$lost=$submit('stop',22,'db-lost-guest22-001');$ok($lost['status']==='unknown'&&$lost['remote_task']===null,'lost response remains unknown');
    $ok($submit('stop',22,'db-lost-guest22-001')['id']===$lost['id'],'unknown idempotency replay');
    $reject(409,fn()=>$submit('start',22,'db-blocked-guest22-01'),'unknown action blocks next write');
    $authorize();$ok($actions->refresh($principal,$id,$lost['id'],2,true)['status']==='acknowledged','explicit external acknowledgement');
    $ok(count($actions->list($principal,$id,0,'host:21'))===3&&count($actions->list($principal,$id,0,'host:22'))===2,'guest-scoped action history');
    echo "VMmanager power PostgreSQL/Vault/Queue/HTTPS integration: $n PASS\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL VMmanager PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally {
    if ($pdo instanceof PDO&&$created) {try {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $e){$failed=true;fwrite(STDERR,"FAIL schema cleanup\n");}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)unlink($file);rmdir($dir.'/migrations');rmdir($dir);
}
exit($failed?1:0);
