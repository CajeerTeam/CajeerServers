#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Isolated PostgreSQL event integration with deterministic SNMP session fixture. */
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
$root=dirname(__DIR__);$schema='cajeer_health_'.bin2hex(random_bytes(8));$dir=sys_get_temp_dir().'/'.$schema;
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
    $keyRoot=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));$crypto=new VaultCrypto($keyRoot);$vault=new CredentialsVault($db,$crypto,$keyRoot);$vault->initialize();
    $store=new CredentialStore($db,$repo,$vault,$crypto,$audit);
    $fault=null;$calls=0;$onRemote=null;
    $factory=static function()use(&$fault,&$calls,&$onRemote):object {
        $calls++;if ($onRemote!==null) {$callback=$onRemote;$onRemote=null;$callback();}if($fault!==null)throw new ConnectorTransportException($fault);
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
    $events=new \Cajeer\Servers\Connector\ConnectorEventService($db,$bindings,$repo,$rbac,$execution,$vault,$crypto,$limiter,$audit);
    $authorize();$grant=$execution->issue($principal,$id,2,'sync',3600);
    $source=$events->create($actor,$id,$grant['id']);$sid=$source['id'];$key=$source['secret'];
    $ok(!isset($events->sources($actor,$id)[0]['secret'])&&!isset($events->sources($actor,$id)[0]['secret_ciphertext']),'source metadata safe');
    $request=static function(string $body,string $secret,?int $timestamp=null)use($sid):\Cajeer\Servers\Runtime\HttpRequest {
        $ts=$timestamp??time();return new \Cajeer\Servers\Runtime\HttpRequest('POST','/api/v1/connectors/webhooks/'.$sid.'/events',['Content-Type'=>'application/json','X-Cajeer-Timestamp'=>(string)$ts,'X-Cajeer-Signature'=>\Cajeer\Servers\Security\SignedPayload::signature($sid."\n".$body,$secret,$ts)],$body);
    };
    $body=static fn(string $eid,string $type='resource.updated'):string=>json_encode(['event_id'=>$eid,'type'=>$type,'resource_id'=>'device'],JSON_THROW_ON_ERROR);
    $reject(401,fn()=>$events->ingest($sid,$request($body('e1'),str_repeat('x',64))),'invalid signature');
    $reject(401,fn()=>$events->ingest($sid,$request($body('e1'),$key,time()-301)),'expired signature');
    $first=$events->ingest($sid,$request($body('e1'),$key));$ok(!$first['deduplicated'],'accepted');
    $dup=$events->ingest($sid,$request($body('e1'),$key,time()+1));$ok($dup['deduplicated']&&$dup['run_id']===$first['run_id'],'resigned duplicate');
    $reject(409,fn()=>$events->ingest($sid,$request($body('e1','resource.deleted'),$key)),'same ID different body');
    $ok((int)$pdo->query('SELECT count(*) FROM connector_runs')->fetchColumn()===1,'atomic single queue run');
    // A real PostgreSQL constraint forces inbox persistence to fail AFTER enqueue.
    $pdo->exec("ALTER TABLE connector_events ADD CONSTRAINT fixture_rollback CHECK(event_id<>'rollback-test')");
    try {$events->ingest($sid,$request($body('rollback-test'),$key));throw new RuntimeException('Expected rollback');}
    catch(PDOException $e){$ok($e->getCode()==='23514','inbox insert failed');}
    $pdo->exec('ALTER TABLE connector_events DROP CONSTRAINT fixture_rollback');
    $ok((int)$pdo->query('SELECT count(*) FROM connector_runs')->fetchColumn()===1&&(int)$pdo->query("SELECT count(*) FROM queue_jobs WHERE queue='connectors'")->fetchColumn()===1,'inbox failure rolls back run and queue');
    $pdo->prepare('UPDATE auth_sessions SET revoked_at=now() WHERE id=:id')->execute(['id'=>$session['id']]);
    $ok($execution->once()['status']==='succeeded','event worker executes without session');
    $q=$pdo->prepare("SELECT asset_id::text FROM connector_discovered_resources WHERE target_id=:id AND external_id='device'");$q->execute(['id'=>$id]);$uuid=$q->fetchColumn();$ok(is_string($uuid),'inventory synchronized');
    $ok($events->events($actor,$id)[0]['status']==='succeeded','event status linked to run');
    $next=$events->ingest($sid,$request($body('e2','resource.deleted'),$key));$ok($execution->once()['status']==='succeeded','delete hint runs authoritative sync');$q->execute(['id'=>$id]);$ok($q->fetchColumn()===$uuid,'no deletion or duplication');
    $cancel=$events->ingest($sid,$request($body('e3'),$key));$execution->cancel($actor,$id,$cancel['run_id']);$execution->once();
    $retry=$events->redrive($actor,$id,$cancel['id'],$grant['id']);$ok($retry['dispatch_count']===2&&$retry['run_id']!==$cancel['run_id'],'redrive new run');
    $ok($execution->once()['status']==='succeeded','redrive completed');
    $reject(409,fn()=>$events->redrive($actor,$id,$cancel['id'],$grant['id']),'successful event cannot redrive');
    $new=$events->change($actor,$id,$sid,true);$ok($new['key_version']==2&&$new['secret']!==$key,'rotated key');
    $reject(401,fn()=>$events->ingest($sid,$request($body('e4'),$key)),'old key rejected');
    $key=$new['secret'];$queued=$events->ingest($sid,$request($body('e4'),$key));
    $events->change($actor,$id,$sid,false);$before=$calls;
    $ok($execution->once()['status']==='failed'&&$calls===$before,'revocation blocks queued remote access');
    $reject(401,fn()=>$events->ingest($sid,$request($body('e5'),$key)),'revoked source rejected');
    $ok($events->events($actor,$id)[0]['status']==='dead_letter','dead letter visible');
    // A second source is revoked after the worker begins remote I/O; commit must be fenced.
    $other=$events->create($actor,$id,$grant['id']);$otherId=$other['id'];$ts=time();$raw=$body('in-flight');
    $inFlight=new \Cajeer\Servers\Runtime\HttpRequest('POST','/api/v1/connectors/webhooks/'.$otherId.'/events',['Content-Type'=>'application/json','X-Cajeer-Timestamp'=>(string)$ts,'X-Cajeer-Signature'=>\Cajeer\Servers\Security\SignedPayload::signature($otherId."\n".$raw,$other['secret'],$ts)],$raw);
    $pending=$events->ingest($otherId,$inFlight);$assetBefore=$inventory->read($actor,$uuid);
    $onRemote=fn()=>$events->change($actor,$id,$otherId,false);
    $ok($execution->once()['status']==='failed'&&$inventory->read($actor,$uuid)===$assetBefore,'in-flight revoke fences Inventory commit');
    $nextKey=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));
    (new \Cajeer\Servers\Credentials\VaultKeyRotation($db,$crypto,$keyRoot,$vault,$audit))->rotate($nextKey);
    $select=$pdo->prepare("SELECT encode(secret_ciphertext,'hex') FROM connector_webhook_sources WHERE id=:id");$select->execute(['id'=>$sid]);$cipher=$select->fetchColumn();
    $ok((new VaultCrypto($nextKey))->open(hex2bin($cipher),$sid,$ws,'connector_webhook')===$key,'master key rotation preserves signing secret');
    echo "PostgreSQL connector events / Vault / Queue: $n PASS\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL Connector Events PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally {
    if ($pdo instanceof PDO&&$created) {try {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $e){$failed=true;fwrite(STDERR,"FAIL schema cleanup\n");}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)unlink($file);rmdir($dir.'/migrations');rmdir($dir);
}
exit($failed?1:0);
