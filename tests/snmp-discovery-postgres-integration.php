#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Run via snmp-protocol-integration.sh --postgres; isolated schema, real SNMP fixture and DB. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Auth\TokenCodec;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Connector\{ConnectorBindingService,ConnectorRuntimeService,ConnectorExecutionService,EndpointPolicy,SnmpTransport,SnmpSession,RouterConnector,SnmpDiscoveryStore};
use Cajeer\Servers\Credentials\{CredentialStore,CredentialVersionService,CredentialsVault,SecretAccessService,VaultCrypto,VaultMasterKey};
use Cajeer\Servers\Database\{ConnectionFactory,DatabaseManager,MigrationRunner};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Inventory\{HistoryService,InventoryRepository,InventoryService};
use Cajeer\Servers\Security\{RateLimiter,Rbac,SecretBox,TwoFactorManager};
if (getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')||$argc!==2||!ctype_digit($argv[1])||(int)$argv[1]<1024||(int)$argv[1]>65535) {
    fwrite(STDERR,"REFUSED: disposable cajeer_test_* PostgreSQL and local snmpd fixture required.\n"); exit(2);
}
if (!extension_loaded('pdo_pgsql')||!extension_loaded('sodium')||!extension_loaded('snmp')) {fwrite(STDERR,"FAIL: pdo_pgsql, snmp and sodium required.\n");exit(1);}
$root=dirname(__DIR__);$schema='cajeer_snmp_'.bin2hex(random_bytes(8));$dir=sys_get_temp_dir().'/'.$schema;
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
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file)if(strcmp(substr(basename($file),0,4),'0056')<0)copy($file,$dir.'/migrations/'.basename($file));
    $runner=new MigrationRunner($db,$dir.'/migrations');$runner->run();
    copy($root.'/migrations/pgsql/0056_snmp_discovery.sql',$dir.'/migrations/0056_snmp_discovery.sql');
    $ok(count($runner->run()['applied'])===1&&$runner->run()['applied']===[],'0.8.3 upgrade and replay');
    $password='fixture-only-password-123!';$q=$pdo->prepare('INSERT INTO users(email,name,password_hash,two_factor_enabled) VALUES(:email,:name,:hash,true) RETURNING id::text');
    $q->execute(['email'=>'snmp-test@example.invalid','name'=>'Snmp integration','hash'=>password_hash($password,PASSWORD_ARGON2ID)]);$actor=(string)$q->fetchColumn();
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$actor]);
    $ws=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:id,'owner')")->execute(['ws'=>$ws,'id'=>$actor]);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$inventory=new InventoryService($db,$repo,$audit);$rbac=new Rbac($db,$audit);
    $key=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));$crypto=new VaultCrypto($key);$vault=new CredentialsVault($db,$crypto,$key);$vault->initialize();
    $store=new CredentialStore($db,$repo,$vault,$crypto,$audit);
    $transport=new SnmpTransport(new EndpointPolicy(['127.0.0.1/32']),false,null,true);
    $registry=new ConnectorRegistry(new RouterConnector($transport));
    $bindings=new ConnectorBindingService($db,$repo,$audit,$registry);
    $versions=new CredentialVersionService($db,$repo,$vault,$crypto,$audit,$registry);
    $secretBox=new SecretBox('snmp-test-app-key-'.bin2hex(random_bytes(24)));$limiter=new RateLimiter($secretBox);$mfa=new TwoFactorManager($db,$secretBox,$audit,$limiter);$totpKey=random_bytes(20);
    $pdo->prepare("INSERT INTO user_mfa(user_id,secret_ciphertext,enabled_at) VALUES(:id,decode(:cipher,'hex'),now())")
        ->execute(['id'=>$actor,'cipher'=>bin2hex($secretBox->seal(TwoFactorManager::base32($totpKey),'mfa:'.$actor))]);
    $session=TokenCodec::issue('cs1');
    $pdo->prepare('INSERT INTO auth_sessions(id,user_id,secret_hash,csrf_hash,expires_at,mfa_verified) VALUES(:session,:actor,:hash,:csrf,now()+interval \'1 hour\',true)')
        ->execute(['session'=>$session['id'],'actor'=>$actor,'hash'=>$session['hash'],'csrf'=>hash('sha256','fixture-csrf')]);
    $principal=['mode'=>'session','id'=>$session['id'],'user'=>['id'=>$actor],'mfa_verified'=>true];
    $access=new SecretAccessService($db,$repo,$rbac,$mfa,$limiter,$audit,$vault,$crypto);$history=new HistoryService($db,$repo,$inventory,$audit);
    $runtime=new ConnectorRuntimeService($db,$repo,$bindings,$access,$history,$audit,$registry,$versions);
    $execution=new ConnectorExecutionService($db,$bindings,$repo,$rbac,$access,$vault,$crypto,$audit,$runtime);
    $asset=$inventory->create($actor,['workspace_id'=>$ws,'type'=>'router','name'=>'SNMP endpoint']);
    $secret=['username'=>'cajeer-reader','auth_protocol'=>'sha256','auth_passphrase'=>'cajeer-test-auth','privacy_protocol'=>'aes128','privacy_passphrase'=>'cajeer-test-priv'];
    $credential=$store->create($actor,['workspace_id'=>$ws,'name'=>'SNMP access','type'=>'snmp_v3','secret'=>$secret]);
    $sessionProbe=new SnmpSession('127.0.0.1',(int)$argv[1],'snmp_v3',$secret);
    try{$profile=['engine_id'=>bin2hex($sessionProbe->get('.1.3.6.1.6.3.10.2.1.1.0')),'object_id'=>'.'.ltrim($sessionProbe->get('.1.3.6.1.2.1.1.2.0'),'.')];}finally{$sessionProbe->close();}
    $target=$bindings->create($actor,['workspace_id'=>$ws,'provider'=>'snmp','name'=>'Snmp integration','endpoint_host'=>'127.0.0.1','endpoint_port'=>(int)$argv[1],'asset_id'=>$asset['id'],'os_network_profile'=>$profile]);
    $id=$target['id'];$cid=$credential['id'];$bindings->bind($actor,$id,$cid,'snmp',1,null,true);
    $authorize=static function()use($pdo,$actor,$principal,$access,$asset,$cid,$password,$totpKey):void {
        $pdo->exec('DELETE FROM security_rate_limits');
        $pdo->prepare('UPDATE user_mfa SET last_counter=NULL WHERE user_id=:id')->execute(['id'=>$actor]);
        $access->authorize($principal,$asset['id'],$cid,'snmp',$password,TwoFactorManager::totp($totpKey,time()));
    };
    $before=(int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn();
    foreach(['device','interfaces','topology'] as $scope){
        $ok($runtime->snmpDiscovery($actor,$id,$scope)['state']==='not_collected','initial '.$scope);
        $authorize();$plan=$execution->createPlan($principal,$id,2,['name'=>'Snmp '.$scope,'collector'=>'snmp.'.$scope,'operation'=>'inspect','ttl_seconds'=>3600,'interval_seconds'=>300]);
        $run=$execution->runPlan($actor,$id,$plan['id'],'snmp-'.$scope);
        $ok($execution->once()['status']==='succeeded','worker '.$scope);
        $current=$runtime->snmpDiscovery($actor,$id,$scope);
        $ok($current['state']==='available'&&array_sum($current['snapshot']['counts'])>0,'current '.$scope);
        $history=$execution->status($actor,$id,$run['id'])['result']['connection']['snmp_discovery'];
        $ok($history==$current['snapshot'],'history '.$scope);
        $execution->runPlan($actor,$id,$plan['id'],'snmp-'.$scope.'-repeat');
        $ok($execution->once()['status']==='succeeded','repeat '.$scope);
    }
    $ok((int)$pdo->query('SELECT count(*) FROM snmp_discovery_state')->fetchColumn()===3,'independent states without duplicates');
    $ok((int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn()===$before,'inspect does not change CMDB');
    $current=$runtime->snmpDiscovery($actor,$id,'device');
    $context=$bindings->runtimeContext($actor,$id,2,'inspect');
    $reject(409,fn()=>$db->transaction(fn($p)=>SnmpDiscoveryStore::save($p,$context,$current['snapshot'],'2000-01-01T00:00:00Z','device')),'late snapshot fenced');
    $bindings->update($actor,$id,2,['name'=>'SNMP renamed']);
    $ok($runtime->snmpDiscovery($actor,$id,'device')['state']==='stale','target revision invalidates state');
    $ok($execution->status($actor,$id,$run['id'])['result']['connection']['snmp_discovery']['scope']==='topology','historical state survives');
    echo "Snmp discovery PostgreSQL/Vault/Queue/SNMP: $n PASS\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL Snmp PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally {
    if ($pdo instanceof PDO&&$created) {try {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $e){$failed=true;fwrite(STDERR,"FAIL schema cleanup\n");}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)unlink($file);rmdir($dir.'/migrations');rmdir($dir);
}
exit($failed?1:0);
