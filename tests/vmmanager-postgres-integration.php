#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Run via vmmanager-https-integration.py --postgres; isolated schema, real TLS fixture and DB. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Auth\TokenCodec;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Connector\{ConnectorBindingService,ConnectorRuntimeService,ConnectorExecutionService,EndpointPolicy,HttpsTransport,VmmanagerConnector,ProxmoxConnector};
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
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file)if(!str_starts_with(basename($file),'0047_'))copy($file,$dir.'/migrations/'.basename($file));
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
    $credential=$store->create($actor,['workspace_id'=>$ws,'name'=>'VMmanager token','type'=>'vmmanager_api','secret'=>['token'=>'fixture-vmm-active']]);
    $profile=['primary_pin'=>openssl_x509_fingerprint(file_get_contents($argv[2]),'sha256'),'anchor_id'=>1,'anchor_node'=>'vmm-node-a'];
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
    $count=static fn():int=>(int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn();
    $before=$count();$authorize();$preview=$runtime->run($principal,$id,2,false);
    $ok($preview['connection']['identity_verified']===true,'runtime propagates trust profile');
    $ok($preview['discovered']===4&&$preview['preview']['summary']['create']===4&&$count()===$before,'real API preview has no writes');
    $authorize();$sync=$runtime->run($principal,$id,2,true);
    $ok($sync['discovered']===4&&$sync['outcomes']['created']===4&&$count()===$before+4,'VMmanager TLS to Vault to Inventory');
    $q=$pdo->prepare("SELECT asset_id::text FROM connector_discovered_resources WHERE target_id=:id AND external_id='host:21'");$q->execute(['id'=>$id]);$uuid=(string)$q->fetchColumn();
    $ok($uuid!==''&&$inventory->read($actor,$uuid)['name']==='vmm-guest-a','stable VM identity recorded');
    $authorize();$grant=$execution->issue($principal,$id,2,'sync',3600,60);
    $queued=$execution->enqueue($actor,$id,$grant['id'],'repeat');
    $pdo->prepare('UPDATE auth_sessions SET revoked_at=now() WHERE id=:id')->execute(['id'=>$session['id']]);
    $ok($execution->once()['status']==='succeeded'&&$execution->status($actor,$id,$queued['id'])['status']==='succeeded'&&$count()===$before+4,'worker runs without session, no duplicates');
    $ok($execution->tick()===1&&$execution->once()['status']==='succeeded'&&$count()===$before+4,'scheduler executes VMmanager');
    $pdo->prepare('UPDATE auth_sessions SET revoked_at=NULL WHERE id=:id')->execute(['id'=>$session['id']]);
    $expiry=gmdate('Y-m-d\\TH:i:s\\Z',time()+3600);
    $pending=$versions->stage($actor,$cid,1,['token'=>'fixture-vmm-rotated','expires_at'=>$expiry]);
    $reject(409,fn()=>$versions->activate($actor,$cid,$pending['version'],$pending['secret_version'],true),'VMmanager pending proof required');
    $authorize();$proof=$runtime->validatePending($principal,$id,2,$pending['secret_version']);
    $ok($proof['required_targets']===1&&$proof['validated_targets']===1&&$count()===$before+4,'actual TLS validation with pending token, no Inventory write');
    $versions->activate($actor,$cid,$pending['version'],$pending['secret_version'],true);
    $reject(403,fn()=>$execution->enqueue($actor,$id,$grant['id'],'stale'),'old service grant invalid after rotation');
    $authorize();$runtime->run($principal,$id,2,true);$q->execute(['id'=>$id]);
    $ok((string)$q->fetchColumn()===$uuid&&$inventory->read($actor,$uuid)['name']==='vmm-guest-renamed'&&$count()===$before+4,'rename and migration preserve Inventory UUID');
    $pending=$versions->stage($actor,$cid,3,['token'=>'fixture-vmm-denied']);
    $authorize();$reject(502,fn()=>$runtime->validatePending($principal,$id,2,$pending['secret_version']),'remote unauthorized pending token rejected');
    $reject(409,fn()=>$versions->activate($actor,$cid,$pending['version'],$pending['secret_version'],true),'failed remote proof cannot activate');
    $ok($count()===$before+4,'failed remote validation leaves Inventory intact');
    $deniedAsset=$inventory->create($actor,['workspace_id'=>$ws,'type'=>'server','name'=>'Denied endpoint']);
    $deniedCredential=$store->create($actor,['workspace_id'=>$ws,'name'=>'Denied token','type'=>'vmmanager_api','secret'=>['token'=>'fixture-vmm-denied']]);
    $deniedTarget=$bindings->create($actor,['workspace_id'=>$ws,'provider'=>'vmmanager','name'=>'Denied VMmanager','endpoint_host'=>'127.0.0.1','endpoint_port'=>(int)$argv[1],'asset_id'=>$deniedAsset['id']]);
    $bindings->bind($actor,$deniedTarget['id'],$deniedCredential['id'],'api',1,null,true);
    $pdo->prepare('UPDATE user_mfa SET last_counter=NULL WHERE user_id=:id')->execute(['id'=>$actor]);
    $access->authorize($principal,$deniedAsset['id'],$deniedCredential['id'],'api',$password,TwoFactorManager::totp($totpKey,time()));
    $deniedGrant=$execution->issue($principal,$deniedTarget['id'],2,'sync',3600);
    $deniedRun=$execution->enqueue($actor,$deniedTarget['id'],$deniedGrant['id'],'denied');
    $ok($execution->once()['status']==='failed'&&$execution->status($actor,$deniedTarget['id'],$deniedRun['id'])['error_code']==='connector_auth_failed',
        'remote auth failure is terminal, safe and not retried');
    $ok($count()===$before+5,'denied worker leaves Inventory unchanged');
    $rotatedProfile=$bindings->update($actor,$id,2,['vmmanager_profile'=>array_replace($profile,['primary_pin'=>strtoupper($profile['primary_pin'])])]);
    $ok($rotatedProfile['version']===3&&$rotatedProfile['vmmanager_profile']==$profile,'profile update normalizes pin and bumps target version');
    $reject(412,fn()=>$bindings->runtimeContext($actor,$id,2,'health'),'old profile revision fenced');
    $authorize();$verified=$runtime->health($principal,$id,3);
    $ok($verified['connection']['identity_verified']===true,'updated profile health verified through Vault');
    $ok($verified['connection']['authentication']['owner_id']===7&&$verified['connection']['authentication']['expires_at']===$expiry&&$verified['connection']['capabilities']['guests']==='available','rotation expiry and remote authorization diagnostics through Vault');
    copy($root.'/migrations/pgsql/0047_vmmanager_cluster_discovery.sql',$dir.'/migrations/0047_vmmanager_cluster_discovery.sql');
    $runner=new MigrationRunner($db,$dir.'/migrations');$ok(count($runner->run()['applied'])===1&&$runner->run()['applied']===[],'upgrade from 0.7.2 and migration idempotency');
    $ok($runtime->vmmanagerCluster($actor,$id)['state']==='not_collected','cluster current state initially absent');
    $authorize();$plan=$execution->createPlan($principal,$id,3,['name'=>'Cluster discovery','collector'=>'vmmanager.cluster','operation'=>'sync','ttl_seconds'=>3600,'interval_seconds'=>300]);
    $run=$execution->runPlan($actor,$id,$plan['id'],'cluster-sync');
    $ok($execution->once()['status']==='succeeded','cluster collection worker succeeds');
    $current=$runtime->vmmanagerCluster($actor,$id);
    $ok($current['state']==='available'&&$current['snapshot']['counts']['clusters']===2&&$current['snapshot']['counts']['nodes']===2,'cluster persisted atomically');
    $q=$pdo->prepare("SELECT external_id,parent_external_id,asset_id::text,presence FROM connector_discovered_resources WHERE target_id=:id ORDER BY external_id");$q->execute(['id'=>$id]);$mapped=array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'external_id');
    $ok($mapped['node:1']['parent_external_id']==='cluster:1'&&$mapped['cluster:1']['asset_id']!==null&&$mapped['host:21']['presence']==='seen','cluster membership and guest protection');
    // Old absent cluster fixture, no CMDB object required for testing presence bookkeeping.
    $pdo->prepare("INSERT INTO connector_discovered_resources(target_id,workspace_id,external_id,resource_type,presence,first_seen_at,last_seen_at,absent_since,missed_complete,last_generation) VALUES(:id,:ws,'cluster:999','cluster','seen',now()-interval '5 days',now()-interval '5 days',now()-interval '4 days',2,1)")->execute(['id'=>$id,'ws'=>$ws]);
    $beforeClusterRepeat=$count();$run2=$execution->runPlan($actor,$id,$plan['id'],'cluster-repeat');$ok($execution->once()['status']==='succeeded'&&$count()===$beforeClusterRepeat,'repeat cluster sync no duplicates');
    $q->execute(['id'=>$id]);$absent=array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'external_id');$ok($absent['cluster:999']['presence']==='missing'&&$absent['host:21']['presence']==='seen','only missing clusters reconciled by full cluster scope');
    $ok($execution->status($actor,$id,$run['id'])['result']['connection']['vmmanager_cluster_discovery']['counts']['nodes']===2,'run history retains full snapshot');
    $authorize();$runtime->run($principal,$id,3,true);$q->execute(['id'=>$id]);$mapped2=array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'external_id');
    $ok($mapped2['cluster:1']['asset_id']===$mapped['cluster:1']['asset_id']&&$mapped2['cluster:1']['presence']==='seen'&&$mapped2['cluster:999']['presence']==='missing','legacy inventory keeps cluster identity');
    $bindings->update($actor,$id,3,['vmmanager_profile'=>$profile]);$ok($runtime->vmmanagerCluster($actor,$id)['state']==='stale','profile revision invalidates current cluster snapshot');
    echo "VMmanager PostgreSQL/Vault/Queue/HTTPS integration: $n PASS\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL VMmanager PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally {
    if ($pdo instanceof PDO&&$created) {try {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $e){$failed=true;fwrite(STDERR,"FAIL schema cleanup\n");}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)unlink($file);rmdir($dir.'/migrations');rmdir($dir);
}
exit($failed?1:0);
