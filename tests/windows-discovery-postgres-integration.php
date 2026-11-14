#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Run via host-transport-integration.py --postgres; isolated schema, real WinRM fixture and DB. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Auth\TokenCodec;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Connector\{ConnectorBindingService,ConnectorRuntimeService,ConnectorExecutionService,EndpointPolicy,HostTransport,WindowsConnector,WindowsDiscoveryStore};
use Cajeer\Servers\Credentials\{CredentialStore,CredentialVersionService,CredentialsVault,SecretAccessService,VaultCrypto,VaultMasterKey};
use Cajeer\Servers\Database\{ConnectionFactory,DatabaseManager,MigrationRunner};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Inventory\{HistoryService,InventoryRepository,InventoryService};
use Cajeer\Servers\Security\{RateLimiter,Rbac,SecretBox,TwoFactorManager};
if (getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')||$argc!==5||!ctype_digit($argv[1])||!is_file($argv[2])||!is_executable($argv[3])||!is_file($argv[4])) {
    fwrite(STDERR,"REFUSED: disposable cajeer_test_* PostgreSQL and local WinRM fixture required.\n"); exit(2);
}
if (!extension_loaded('pdo_pgsql')||!extension_loaded('sodium')) {fwrite(STDERR,"FAIL: pdo_pgsql and sodium required.\n");exit(1);}
$root=dirname(__DIR__);$schema='cajeer_windows_'.bin2hex(random_bytes(8));$dir=sys_get_temp_dir().'/'.$schema;
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
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file)if(strcmp(substr(basename($file),0,4),'0055')<0)copy($file,$dir.'/migrations/'.basename($file));
    $runner=new MigrationRunner($db,$dir.'/migrations');$runner->run();
    copy($root.'/migrations/pgsql/0055_windows_discovery.sql',$dir.'/migrations/0055_windows_discovery.sql');
    $ok(count($runner->run()['applied'])===1&&$runner->run()['applied']===[],'0.8.2 upgrade and replay');
    $password='fixture-only-password-123!';$q=$pdo->prepare('INSERT INTO users(email,name,password_hash,two_factor_enabled) VALUES(:email,:name,:hash,true) RETURNING id::text');
    $q->execute(['email'=>'windows-test@example.invalid','name'=>'Windows integration','hash'=>password_hash($password,PASSWORD_ARGON2ID)]);$actor=(string)$q->fetchColumn();
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$actor]);
    $ws=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:id,'owner')")->execute(['ws'=>$ws,'id'=>$actor]);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$inventory=new InventoryService($db,$repo,$audit);$rbac=new Rbac($db,$audit);
    $key=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));$crypto=new VaultCrypto($key);$vault=new CredentialsVault($db,$crypto,$key);$vault->initialize();
    $store=new CredentialStore($db,$repo,$vault,$crypto,$audit);
    $transport=new HostTransport(new EndpointPolicy(['127.0.0.1/32','::1/128'],null,true),$argv[3],null,$argv[4],true);
    $registry=new ConnectorRegistry(new WindowsConnector($transport));
    $bindings=new ConnectorBindingService($db,$repo,$audit,$registry);
    $versions=new CredentialVersionService($db,$repo,$vault,$crypto,$audit,$registry);
    $secretBox=new SecretBox('windows-test-app-key-'.bin2hex(random_bytes(24)));$limiter=new RateLimiter($secretBox);$mfa=new TwoFactorManager($db,$secretBox,$audit,$limiter);$totpKey=random_bytes(20);
    $pdo->prepare("INSERT INTO user_mfa(user_id,secret_ciphertext,enabled_at) VALUES(:id,decode(:cipher,'hex'),now())")
        ->execute(['id'=>$actor,'cipher'=>bin2hex($secretBox->seal(TwoFactorManager::base32($totpKey),'mfa:'.$actor))]);
    $session=TokenCodec::issue('cs1');
    $pdo->prepare('INSERT INTO auth_sessions(id,user_id,secret_hash,csrf_hash,expires_at,mfa_verified) VALUES(:session,:actor,:hash,:csrf,now()+interval \'1 hour\',true)')
        ->execute(['session'=>$session['id'],'actor'=>$actor,'hash'=>$session['hash'],'csrf'=>hash('sha256','fixture-csrf')]);
    $principal=['mode'=>'session','id'=>$session['id'],'user'=>['id'=>$actor],'mfa_verified'=>true];
    $access=new SecretAccessService($db,$repo,$rbac,$mfa,$limiter,$audit,$vault,$crypto);$history=new HistoryService($db,$repo,$inventory,$audit);
    $runtime=new ConnectorRuntimeService($db,$repo,$bindings,$access,$history,$audit,$registry,$versions);
    $execution=new ConnectorExecutionService($db,$bindings,$repo,$rbac,$access,$vault,$crypto,$audit,$runtime);
    $asset=$inventory->create($actor,['workspace_id'=>$ws,'type'=>'server','name'=>'Windows endpoint']);
    $credential=$store->create($actor,['workspace_id'=>$ws,'name'=>'WinRM access','type'=>'winrm','secret'=>['username'=>'test','password'=>'fixture-secret','auth'=>'basic']]);
    $profile=json_decode(file_get_contents($argv[2]),true,8,JSON_THROW_ON_ERROR);
    $target=$bindings->create($actor,['workspace_id'=>$ws,'provider'=>'winrm','name'=>'Windows integration','endpoint_host'=>'localhost','endpoint_port'=>(int)$argv[1],'asset_id'=>$asset['id'],'os_network_profile'=>$profile]);
    $id=$target['id'];$cid=$credential['id'];$bindings->bind($actor,$id,$cid,'winrm',1,null,true);
    $authorize=static function()use($pdo,$actor,$principal,$access,$asset,$cid,$password,$totpKey):void {
        $pdo->exec('DELETE FROM security_rate_limits');
        $pdo->prepare('UPDATE user_mfa SET last_counter=NULL WHERE user_id=:id')->execute(['id'=>$actor]);
        $access->authorize($principal,$asset['id'],$cid,'winrm',$password,TwoFactorManager::totp($totpKey,time()));
    };
    $before=(int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn();
    foreach(['system','software','services','storage','network','updates'] as $scope){
        $ok($runtime->windowsDiscovery($actor,$id,$scope)['state']==='not_collected','initial '.$scope);
        $authorize();$plan=$execution->createPlan($principal,$id,2,['name'=>'Windows '.$scope,'collector'=>'winrm.'.$scope,'operation'=>'inspect','ttl_seconds'=>3600,'interval_seconds'=>300]);
        $run=$execution->runPlan($actor,$id,$plan['id'],'windows-'.$scope);
        $ok($execution->once()['status']==='succeeded','worker '.$scope);
        $current=$runtime->windowsDiscovery($actor,$id,$scope);
        $ok($current['state']==='available'&&array_sum($current['snapshot']['counts'])>0,'current '.$scope);
        $history=$execution->status($actor,$id,$run['id'])['result']['connection']['windows_discovery'];
        $ok($history==$current['snapshot'],'history '.$scope);
        $execution->runPlan($actor,$id,$plan['id'],'windows-'.$scope.'-repeat');
        $ok($execution->once()['status']==='succeeded','repeat '.$scope);
    }
    $ok((int)$pdo->query('SELECT count(*) FROM windows_discovery_state')->fetchColumn()===6,'independent states without duplicates');
    $ok((int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn()===$before,'inspect does not change CMDB');
    $current=$runtime->windowsDiscovery($actor,$id,'system');
    $context=$bindings->runtimeContext($actor,$id,2,'inspect');
    $reject(409,fn()=>$db->transaction(fn($p)=>WindowsDiscoveryStore::save($p,$context,$current['snapshot'],'2000-01-01T00:00:00Z','system')),'late snapshot fenced');
    $bindings->update($actor,$id,2,['os_network_profile'=>array_replace($profile,['primary_pin'=>str_repeat('a',64)])]);
    $ok($runtime->windowsDiscovery($actor,$id,'system')['state']==='stale','profile revision invalidates state');
    $ok($execution->status($actor,$id,$run['id'])['result']['connection']['windows_discovery']['scope']==='updates','historical state survives');
    echo "Windows discovery PostgreSQL/Vault/Queue/WinRM: $n PASS\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL Windows PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally {
    if ($pdo instanceof PDO&&$created) {try {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $e){$failed=true;fwrite(STDERR,"FAIL schema cleanup\n");}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)unlink($file);rmdir($dir.'/migrations');rmdir($dir);
}
exit($failed?1:0);
