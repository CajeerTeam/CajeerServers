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
    $state=static function()use($pdo,$id):array {$q=$pdo->prepare('SELECT * FROM connector_health_current WHERE target_id=:id');$q->execute(['id'=>$id]);return $q->fetch(PDO::FETCH_ASSOC);};
    $count=static fn():int=>(int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn();
    $before=$count();$ok($state()['health_status']==='unknown','initial state');
    $authorize();$runtime->health($principal,$id,2);$ok($state()['health_status']==='healthy'&&$state()['health_success_at']!==null&&$count()===$before,'health no Inventory write');
    $authorize();$sync=$runtime->run($principal,$id,2,true);$ok($sync['outcomes']['created']===1&&$count()===$before+1,'SNMP sync');
    $q=$pdo->prepare("SELECT asset_id::text FROM connector_discovered_resources WHERE target_id=:id AND external_id='device'");$q->execute(['id'=>$id]);$uuid=(string)$q->fetchColumn();
    $router=$inventory->read($actor,$uuid);$ok($router['attributes']['network_device']['interfaces'][0]['name']==='eth0','network metadata persisted');
    $authorize();$runtime->run($principal,$id,2,true);$q->execute(['id'=>$id]);$ok($count()===$before+1&&$q->fetchColumn()===$uuid,'stable identity');
    $attrs=$router['attributes'];$attrs['network_device']['description']='manual description';$attrs['operator_note']='keep';
    $inventory->update($actor,$uuid,$inventory->read($actor,$uuid)['version'],['attributes'=>$attrs]);
    $authorize();$runtime->run($principal,$id,2,true);$ok($inventory->read($actor,$uuid)['attributes']==$attrs,'manual metadata preserved');
    // Named collection jobs execute the real SNMP adapter over the deterministic session fixture.
    $authorize();$readGrant=$execution->issue($principal,$id,2,'inspect',3600);
    $reject(422,fn()=>$execution->enqueue($actor,$id,$readGrant['id'],'wrong-provider','ssh.inventory'),'collector scope');
    $run=$execution->enqueue($actor,$id,$readGrant['id'],'collection-read','snmp.inventory');
    $ok($execution->enqueue($actor,$id,$readGrant['id'],'collection-read','snmp.inventory')['id']===$run['id'],'collection idempotency');
    $reject(409,fn()=>$execution->enqueue($actor,$id,$readGrant['id'],'collection-read'),'idempotency plan conflict');
    $pdo->prepare('UPDATE auth_sessions SET revoked_at=now() WHERE id=:id')->execute(['id'=>$session['id']]);
    $ok($execution->once()['status']==='succeeded','collector executes without session');
    $row=$execution->status($actor,$id,$run['id']);$snapshot=$row['result']['collection'];
    $ok($row['collector_name']==='snmp.inventory'&&$snapshot['sink']==='result'&&$snapshot['observations'][0]['external_id']==='device'&&$count()===$before+1,'read-only snapshot saved');
    $ok(!isset($execution->runs($actor,$id)[0]['result']['collection']['observations']),'list omits large observations');
    $pdo->prepare('UPDATE auth_sessions SET revoked_at=NULL WHERE id=:id')->execute(['id'=>$session['id']]);
    $authorize();$writeGrant=$execution->issue($principal,$id,2,'sync',3600);
    $write=$execution->enqueue($actor,$id,$writeGrant['id'],'collection-sync','snmp.inventory');
    $ok($execution->once()['status']==='succeeded'&&$execution->status($actor,$id,$write['id'])['result']['collection']['sink']==='inventory','collection sync persisted');
    $q->execute(['id'=>$id]);$ok($q->fetchColumn()===$uuid&&$inventory->read($actor,$uuid)['attributes']==$attrs,'collection preserves UUID/manual data');
    $cancel=$execution->enqueue($actor,$id,$readGrant['id'],'collection-cancel','snmp.inventory');$execution->cancel($actor,$id,$cancel['id']);
    $ok($execution->status($actor,$id,$cancel['id'])['status']==='cancelled','collection cancellation');
    $revoked=$execution->enqueue($actor,$id,$readGrant['id'],'collection-revoke','snmp.inventory');$execution->revoke($actor,$id,$readGrant['id']);
    $prior=$calls;$ok($execution->once()['status']==='failed'&&$calls===$prior,'revoked grant denied before collection');
    $authorize();$grant=$execution->issue($principal,$id,2,'health',3600,60);
    $pdo->prepare('UPDATE auth_sessions SET revoked_at=now() WHERE id=:id')->execute(['id'=>$session['id']]);
    $ok($execution->tick()===1&&$execution->once()['status']==='succeeded','scheduled health without session');
    $pdo->prepare('UPDATE auth_sessions SET revoked_at=NULL WHERE id=:id')->execute(['id'=>$session['id']]);
    $queued=$execution->enqueue($actor,$id,$grant['id'],'queued-before-failure');
    $fault='connector_transport_failed';
    for($i=1;$i<=3;$i++){$authorize();$reject(502,fn()=>$runtime->health($principal,$id,2),'remote failure '.$i);$ok($state()['health_status']===($i<3?'degraded':'unreachable'),'failure transition');}
    $ok(ConnectorHealthMonitor::isOpen($pdo,$id)&&$state()['health_success_at']!==null,'circuit open, previous success retained');
    $callsBefore=$calls;$ok($execution->once()['status']==='failed'&&$execution->status($actor,$id,$queued['id'])['error_code']==='connector_circuit_open'&&$calls===$callsBefore,'queued work denied before remote call');
    $due=static function()use($pdo,$grant):void{$pdo->prepare("UPDATE connector_service_grants SET next_due_at=clock_timestamp()-interval '1 second' WHERE id=:id")->execute(['id'=>$grant['id']]);};
    $due();$ok($execution->tick()===1&&$execution->once()===null,'scheduler skips open circuit');
    $pdo->prepare("UPDATE connector_health_state SET circuit_open_until=clock_timestamp()-interval '1 second' WHERE target_id=:id")->execute(['id'=>$id]);$fault=null;$due();
    $ok($execution->tick()===1&&$execution->once()['status']==='succeeded'&&$state()['health_status']==='healthy'&&!ConnectorHealthMonitor::isOpen($pdo,$id),'automatic recovery after cooldown');
    $metrics=ConnectorHealthMonitor::metrics($pdo);$ok(str_contains($metrics,'cajeer_connector_health_checks_total{provider="snmp",outcome="failure"} 3')&&!str_contains($metrics,$id),'bounded metrics');
    $pending=$versions->stage($actor,$cid,1,['community'=>'fixture-rotated']);$ok($state()['health_status']==='unknown','credential revision invalidates health');
    $authorize();$runtime->validatePending($principal,$id,2,$pending['secret_version']);$ok($state()['health_status']==='unknown','pending probe does not alter active health');
    $versions->activate($actor,$cid,$pending['version'],$pending['secret_version'],true);
    $authorize();$runtime->health($principal,$id,2);$ok($state()['health_status']==='healthy','rotated credential healthy');
    $ok($count()===$before+1,'no extra Inventory assets');
    echo "PostgreSQL SNMP health / Vault / Scheduler: $n PASS\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL Health PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally {
    if ($pdo instanceof PDO&&$created) {try {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $e){$failed=true;fwrite(STDERR,"FAIL schema cleanup\n");}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)unlink($file);rmdir($dir.'/migrations');rmdir($dir);
}
exit($failed?1:0);
