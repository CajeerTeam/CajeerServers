#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Isolated PostgreSQL upgrade and real PBS HTTPS worker integration. Run through pbs-backup-https-integration.py --postgres. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Auth\TokenCodec;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Connector\{ConnectorBindingService,ConnectorRuntimeService,ConnectorExecutionService,EndpointPolicy,HttpsTransport,PbsConnector};
use Cajeer\Servers\Credentials\{CredentialStore,CredentialVersionService,CredentialsVault,SecretAccessService,VaultCrypto,VaultMasterKey};
use Cajeer\Servers\Database\{ConnectionFactory,DatabaseManager,MigrationRunner};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Inventory\{HistoryService,InventoryRepository,InventoryService};
use Cajeer\Servers\Security\{RateLimiter,Rbac,SecretBox,TwoFactorManager};
if (getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')) {
    fwrite(STDERR,"REFUSED: disposable cajeer_test_* PostgreSQL required.\n"); exit(2);
}
if (!extension_loaded('pdo_pgsql')||!extension_loaded('sodium')) {fwrite(STDERR,"FAIL: pdo_pgsql and sodium required.\n");exit(1);}
$root=dirname(__DIR__);$schema='cajeer_pbs_'.bin2hex(random_bytes(8));$dir=sys_get_temp_dir().'/'.$schema;
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
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file)if(strcmp(basename($file),'0044_pbs_backup_integration.sql')<0)copy($file,$dir.'/migrations/'.basename($file));
    $runner=new MigrationRunner($db,$dir.'/migrations');$runner->run();
    copy($root.'/migrations/pgsql/0044_pbs_backup_integration.sql',$dir.'/migrations/0044_pbs_backup_integration.sql');
    $ok($runner->run()['applied']===['0044_pbs_backup_integration']&&$runner->validate()['valid']&&$runner->run()['applied']===[],'0.6.7 upgrade and replay');
    $password='fixture-only-password-123!';$q=$pdo->prepare('INSERT INTO users(email,name,password_hash,two_factor_enabled) VALUES(:email,:name,:hash,true) RETURNING id::text');
    $q->execute(['email'=>'health-test@example.invalid','name'=>'Health integration','hash'=>password_hash($password,PASSWORD_ARGON2ID)]);$actor=(string)$q->fetchColumn();
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$actor]);
    $ws=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:id,'owner')")->execute(['ws'=>$ws,'id'=>$actor]);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$inventory=new InventoryService($db,$repo,$audit);$rbac=new Rbac($db,$audit);
    $key=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));$crypto=new VaultCrypto($key);$vault=new CredentialsVault($db,$crypto,$key);$vault->initialize();
    $store=new CredentialStore($db,$repo,$vault,$crypto,$audit);
    $ca=getenv('CAJEER_PBS_TEST_CA');$port=(int)getenv('CAJEER_PBS_TEST_PORT');
    if(!$ca||!is_file($ca)||$port<1)throw new RuntimeException('Run through the TLS fixture.');
    $registry=new ConnectorRegistry(new PbsConnector(new HttpsTransport(new EndpointPolicy(['127.0.0.1/32'],static fn($host)=>['127.0.0.1'],true),3),$ca));
    $bindings=new ConnectorBindingService($db,$repo,$audit,$registry);
    $versions=new CredentialVersionService($db,$repo,$vault,$crypto,$audit,$registry);
    $secretBox=new SecretBox('health-test-app-key-'.bin2hex(random_bytes(24)));$limiter=new RateLimiter($secretBox);$mfa=new TwoFactorManager($db,$secretBox,$audit,$limiter);$totpKey=random_bytes(20);
    $pdo->prepare("INSERT INTO user_mfa(user_id,secret_ciphertext,enabled_at) VALUES(:id,decode(:cipher,'hex'),now())")
        ->execute(['id'=>$actor,'cipher'=>bin2hex($secretBox->seal(TwoFactorManager::base32($totpKey),'mfa:'.$actor))]);
    $session=TokenCodec::issue('cs1');
    $pdo->prepare('INSERT INTO auth_sessions(id,user_id,secret_hash,csrf_hash,expires_at,mfa_verified) VALUES(:session,:actor,:hash,:csrf,now()+interval \'1 hour\',true)')
        ->execute(['session'=>$session['id'],'actor'=>$actor,'hash'=>$session['hash'],'csrf'=>hash('sha256','fixture-csrf')]);
    $principal=['mode'=>'session','id'=>$session['id'],'user'=>['id'=>$actor],'mfa_verified'=>true];
    $access=new SecretAccessService($db,$repo,$rbac,$mfa,$limiter,$audit,$vault,$crypto);$history=new HistoryService($db,$repo,$inventory,$audit);
    $runtime=new ConnectorRuntimeService($db,$repo,$bindings,$access,$history,$audit,$registry,$versions);
    $execution=new ConnectorExecutionService($db,$bindings,$repo,$rbac,$access,$vault,$crypto,$audit,$runtime);
    $asset=$inventory->create($actor,['workspace_id'=>$ws,'type'=>'server','name'=>'PBS endpoint']);
    $credential=$store->create($actor,['workspace_id'=>$ws,'name'=>'PBS credential','type'=>'api_token','secret'=>'svc@pbs!read:fixture-secret']);
    $target=$bindings->create($actor,['workspace_id'=>$ws,'provider'=>'pbs','name'=>'PBS integration','endpoint_host'=>'pbs.test','endpoint_port'=>$port,'asset_id'=>$asset['id']]);
    $id=$target['id'];$cid=$credential['id'];$bindings->bind($actor,$id,$cid,'api',1,null,true);
    $authorize=static function()use($pdo,$actor,$principal,$access,$asset,$cid,$password,$totpKey):void {
        // Isolated test schema only: reset OTP replay and fixed-window counters between probes.
        $pdo->exec("DELETE FROM security_rate_limits");
        $pdo->prepare('UPDATE user_mfa SET last_counter=NULL WHERE user_id=:id')->execute(['id'=>$actor]);
        $access->authorize($principal,$asset['id'],$cid,'api',$password,TwoFactorManager::totp($totpKey,time()));
    };
    $before=(int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn();
    $body=['name'=>'Scheduled PBS backups','collector'=>'pbs.backups','operation'=>'inspect','ttl_seconds'=>3600,'interval_seconds'=>60];
    $authorize();$plan=$execution->createPlan($principal,$id,2,$body);$pid=$plan['id'];$grant=$plan['grant_id'];
    $ok(count($execution->plans($actor,$id))===1&&$plan['collector_name']==='pbs.backups','persistent plan');
    $run=$execution->runPlan($actor,$id,$pid,'pbs-first');
    $ok($execution->once()['status']==='succeeded','real PBS collector worker');
    $result=$execution->status($actor,$id,$run['id']);
    $snapshot=$result['result']['connection']['pbs_discovery'];
    $ok($snapshot['complete']&&$snapshot['counts']['snapshots']===2&&$snapshot['counts']['jobs']===4,'persisted PBS history');
    $ok($result['result']['collection']['count']===array_sum($snapshot['counts']),'accurate collection count');
    $current=$runtime->pbsBackups($actor,$id);$ok($current['state']==='available'&&$current['snapshot']==$snapshot,'current matches committed run');
    $ok((int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn()===$before,'no CMDB writes');
    $ok((int)$pdo->query('SELECT count(*) FROM pbs_backup_state')->fetchColumn()===1,'one current scoped state');
    $again=$execution->runPlan($actor,$id,$pid,'pbs-second');$ok($execution->once()['status']==='succeeded','repeat collection');
    $ok(count($execution->runs($actor,$id,50,0,$pid))===2&&$runtime->pbsBackups($actor,$id)['snapshot']==$snapshot,'history retained');
    $q=$pdo->prepare('INSERT INTO users(email,name,password_hash) VALUES(:email,:name,:hash) RETURNING id::text');$q->execute(['email'=>'pbs-other@example.invalid','name'=>'Other','hash'=>password_hash($password,PASSWORD_ARGON2ID)]);$other=(string)$q->fetchColumn();
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$other]);
    $reject(404,fn()=>$runtime->pbsBackups($other,$id),'workspace isolation');
    $target=$bindings->show($actor,$id);$bindings->update($actor,$id,$target['version'],['name'=>'Renamed PBS']);
    $ok($runtime->pbsBackups($actor,$id)['state']==='stale'&&$runtime->pbsBackups($actor,$id)['snapshot']===null,'target revision invalidates current');
    $ok($execution->status($actor,$id,$run['id'])['result']['connection']['pbs_discovery']==$snapshot,'history retained after target edit');
    echo "PostgreSQL PBS upgrade / Vault / worker / state: $n PASS\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL PBS PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally {
    if ($pdo instanceof PDO&&$created) {try {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $e){$failed=true;fwrite(STDERR,"FAIL schema cleanup\n");}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)unlink($file);rmdir($dir.'/migrations');rmdir($dir);
}
exit($failed?1:0);
