#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Isolated PostgreSQL health integration with deterministic SNMP session fixture. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Auth\TokenCodec;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Connector\{ConnectorBindingService,ConnectorRuntimeService,ConnectorExecutionService,EndpointPolicy,SnmpTransport,RouterConnector,ConnectorHealthMonitor,ConnectorTransportException};
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
$root=dirname(__DIR__);$schema='cajeer_plans_'.bin2hex(random_bytes(8));$dir=sys_get_temp_dir().'/'.$schema;
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
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file)copy($file,$dir.'/migrations/'.basename($file));
    (new MigrationRunner($db,$dir.'/migrations'))->run();
    $password='fixture-only-password-123!';$q=$pdo->prepare('INSERT INTO users(email,name,password_hash,two_factor_enabled) VALUES(:email,:name,:hash,true) RETURNING id::text');
    $q->execute(['email'=>'health-test@example.invalid','name'=>'Health integration','hash'=>password_hash($password,PASSWORD_ARGON2ID)]);$actor=(string)$q->fetchColumn();
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$actor]);
    $ws=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:id,'owner')")->execute(['ws'=>$ws,'id'=>$actor]);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$inventory=new InventoryService($db,$repo,$audit);$rbac=new Rbac($db,$audit);
    $key=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));$crypto=new VaultCrypto($key);$vault=new CredentialsVault($db,$crypto,$key);$vault->initialize();
    $store=new CredentialStore($db,$repo,$vault,$crypto,$audit);
    $fault=null;$calls=0;
    $factory=static function()use(&$fault,&$calls):object {
        $calls++;if($fault!==null)throw new ConnectorTransportException($fault);
        return new class {
            public function get(string $oid):string{return match($oid){'.1.3.6.1.2.1.1.5.0'=>'fixture-router','.1.3.6.1.2.1.1.2.0'=>'.1.3.6.1.4.1.8072.3.2.10','.1.3.6.1.2.1.1.1.0'=>'Fixture OS','.1.3.6.1.2.1.2.1.0'=>'1',default=>throw new RuntimeException('Unexpected OID')};}
            public function next(string $oid):array{return match($oid){'.1.3.6.1.2.1.2.2.1.2'=>['.1.3.6.1.2.1.2.2.1.2.1','eth0'],'.1.3.6.1.2.1.2.2.1.8'=>['.1.3.6.1.2.1.2.2.1.8.1','1'],'.1.3.6.1.2.1.4.20.1.2'=>['.1.3.6.1.2.1.4.20.1.2.192.0.2.5','1'],default=>[null,null]};}
            public function close():void{}
        };
    };
    $registry=new ConnectorRegistry(new RouterConnector(new SnmpTransport(new EndpointPolicy(['127.0.0.1/32']),true,$factory)));
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
    $asset=$inventory->create($actor,['workspace_id'=>$ws,'type'=>'server','name'=>'SNMP endpoint']);
    $credential=$store->create($actor,['workspace_id'=>$ws,'name'=>'SNMP credential','type'=>'snmp_v2c','secret'=>['community'=>'fixture-read-only']]);
    $target=$bindings->create($actor,['workspace_id'=>$ws,'provider'=>'snmp','name'=>'SNMP integration','endpoint_host'=>'127.0.0.1','endpoint_port'=>1161,'asset_id'=>$asset['id']]);
    $id=$target['id'];$cid=$credential['id'];$bindings->bind($actor,$id,$cid,'snmp',1,null,true);
    $authorize=static function()use($pdo,$actor,$principal,$access,$asset,$cid,$password,$totpKey):void {
        // Isolated test schema only: reset OTP replay and fixed-window counters between probes.
        $pdo->exec("DELETE FROM security_rate_limits");
        $pdo->prepare('UPDATE user_mfa SET last_counter=NULL WHERE user_id=:id')->execute(['id'=>$actor]);
        $access->authorize($principal,$asset['id'],$cid,'snmp',$password,TwoFactorManager::totp($totpKey,time()));
    };
    $before=(int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn();
    $body=['name'=>'Scheduled SNMP inventory','collector'=>'snmp.inventory','operation'=>'sync','ttl_seconds'=>3600,'interval_seconds'=>60];
    $authorize();$plan=$execution->createPlan($principal,$id,2,$body);$pid=$plan['id'];$grant=$plan['grant_id'];
    $ok(count($execution->plans($actor,$id))===1&&$plan['collector_name']==='snmp.inventory','persistent plan');
    $reject(403,fn()=>$execution->enqueue($actor,$id,$grant,'bypass','snmp.inventory'),'dedicated grant denied to generic enqueue');
    $reject(403,fn()=>$db->transaction(fn(PDO $p)=>$execution->eventGrant($p,$actor,$id,$grant)),'dedicated grant denied to webhook');
    $due=static function()use($pdo,$pid):void{$pdo->prepare("UPDATE collection_plans SET next_due_at=clock_timestamp()-interval '1 second' WHERE id=:id")->execute(['id'=>$pid]);};
    $pdo->prepare('UPDATE auth_sessions SET revoked_at=now() WHERE id=:id')->execute(['id'=>$session['id']]);
    $due();$ok($execution->tick()===1,'schedule without live session');$due();$ok($execution->tick()===1&&count($execution->runs($actor,$id,50,0,$pid))===1,'pending tick coalesces');
    $ok($execution->once()['status']==='succeeded','scheduled execution succeeds');
    $row=$execution->runs($actor,$id,50,0,$pid)[0];$result=$execution->status($actor,$id,$row['id']);
    $ok($result['result']['collection']['sink']==='inventory'&&$result['attempt_history'][0]['outcome']==='succeeded','saved result and delivery history');
    $ok((int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn()===$before+1,'actual Inventory application');
    $execution->setPlanEnabled($actor,$id,$pid,false);$due();$ok($execution->tick()===0,'paused plan no dispatch');
    $execution->setPlanEnabled($actor,$id,$pid,true);$due();$ok($execution->tick()===1&&$execution->once()['status']==='succeeded','resume');
    $ok((int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn()===$before+1,'no duplicate inventory');
    $manual=$execution->runPlan($actor,$id,$pid,'manual-1');$ok($execution->runPlan($actor,$id,$pid,'manual-1')['id']===$manual['id'],'manual idempotency');
    $fault='connector_transport_failed';$ok($execution->once()['status']==='retry_scheduled','transient fault retry');
    $r=$execution->status($actor,$id,$manual['id']);$ok($r['attempt_history'][0]['outcome']==='retry_scheduled'&&$r['attempt_history'][0]['error_code']==='connector_transport_failed','retry history');
    $pdo->prepare("UPDATE queue_jobs SET available_at=clock_timestamp()-interval '1 second' WHERE id=:id")->execute(['id'=>$manual['id']]);$fault=null;
    $ok($execution->once()['status']==='succeeded','retry succeeds');$r=$execution->status($actor,$id,$manual['id']);$ok(count($r['attempt_history'])===2&&$r['attempt_history'][1]['outcome']==='succeeded','two distinct attempts');
    $cancel=$execution->runPlan($actor,$id,$pid,'cancel');$execution->cancel($actor,$id,$cancel['id']);$ok($execution->status($actor,$id,$cancel['id'])['status']==='cancelled'&&$execution->once()===null,'cancel queued');
    $retry=$execution->runPlan($actor,$id,$pid,'redrive',$cancel['id']);$ok($execution->runPlan($actor,$id,$pid,'redrive',$cancel['id'])['id']===$retry['id'],'redrive idempotent');
    $reject(409,fn()=>$execution->runPlan($actor,$id,$pid,'duplicate-redrive',$cancel['id']),'redrive once');
    $ok($execution->once()['status']==='succeeded'&&$execution->status($actor,$id,$retry['id'])['redrive_of']===$cancel['id'],'redrive linkage');
    $lost=$execution->runPlan($actor,$id,$pid,'lost');$queue=new \Cajeer\Servers\Queue\PostgresQueueDriver($db);$reservation=$queue->reserve('connectors',300);$ok($reservation->job->id===$lost['id'],'claim');
    $pdo->prepare("UPDATE queue_jobs SET reserved_until=clock_timestamp()-interval '1 second' WHERE id=:id")->execute(['id'=>$lost['id']]);
    $ok($execution->once()['status']==='succeeded','lease recovered');$r=$execution->status($actor,$id,$lost['id']);$ok($r['attempt_history'][0]['outcome']==='lease_expired'&&$r['attempt_history'][1]['outcome']==='succeeded','lease history');
    $revoked=$execution->runPlan($actor,$id,$pid,'revoke');$execution->revoke($actor,$id,$grant);$prior=$calls;
    $ok($execution->once()['status']==='failed'&&$calls===$prior,'revocation before remote execution');
    $reject(403,fn()=>$execution->runPlan($actor,$id,$pid,'after-revoke'),'revoked plan cannot enqueue');
    $due();$ok($execution->tick()===0,'revocation pauses scheduler');
    echo "PostgreSQL collection plans / Vault / Scheduler / attempts: $n PASS\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL Collection plans PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally {
    if ($pdo instanceof PDO&&$created) {try {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $e){$failed=true;fwrite(STDERR,"FAIL schema cleanup\n");}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)unlink($file);rmdir($dir.'/migrations');rmdir($dir);
}
exit($failed?1:0);
