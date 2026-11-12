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
$root=dirname(__DIR__);$schema='cajeer_metrics_'.bin2hex(random_bytes(8));$dir=sys_get_temp_dir().'/'.$schema;
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
            public function get(string $oid):string{return match($oid){'.1.3.6.1.2.1.1.3.0'=>'12345','.1.3.6.1.2.1.1.5.0'=>'fixture-router','.1.3.6.1.2.1.1.2.0'=>'.1.3.6.1.4.1.8072.3.2.10','.1.3.6.1.2.1.1.1.0'=>'Fixture OS','.1.3.6.1.2.1.2.1.0'=>'1',default=>throw new RuntimeException('Unexpected OID')};}
            public function next(string $oid):array{return match($oid){'.1.3.6.1.2.1.31.1.1.1.6'=>['.1.3.6.1.2.1.31.1.1.1.6.1','18446744073709551615'],'.1.3.6.1.2.1.2.2.1.2'=>['.1.3.6.1.2.1.2.2.1.2.1','eth0'],'.1.3.6.1.2.1.2.2.1.8'=>['.1.3.6.1.2.1.2.2.1.8.1','1'],'.1.3.6.1.2.1.4.20.1.2'=>['.1.3.6.1.2.1.4.20.1.2.192.0.2.5','1'],default=>[null,null]};}
            public function close():void{}
        };
    };
    $registry=new ConnectorRegistry(new \Cajeer\Servers\Connector\PrometheusConnector(new \Cajeer\Servers\Connector\HttpsTransport(new EndpointPolicy(['127.0.0.1/32']))),new RouterConnector(new SnmpTransport(new EndpointPolicy(['127.0.0.1/32']),true,$factory)));
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
    $exporter=$bindings->create($actor,['workspace_id'=>$ws,'provider'=>'prometheus','name'=>'Prometheus test','endpoint_host'=>'127.0.0.1','endpoint_port'=>443,'asset_id'=>$asset['id']]);
    $token=$store->create($actor,['workspace_id'=>$ws,'name'=>'Exporter token','type'=>'api_token','secret'=>'fixture-prometheus-token']);
    $bindings->bind($actor,$exporter['id'],$token['id'],'api',1,null,true);
    $ok($bindings->runtimeContext($actor,$exporter['id'],2,'inspect')['credential_type']==='api_token','Prometheus DB provider and API binding guard');
    $before=(int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn();
    $authorize();$plan=$execution->createPlan($principal,$id,2,['name'=>'SNMP metrics','collector'=>'snmp.metrics','operation'=>'inspect','ttl_seconds'=>3600,'interval_seconds'=>60]);
    $pid=$plan['id'];$pdo->prepare("UPDATE collection_plans SET next_due_at=clock_timestamp()-interval '1 second' WHERE id=:id")->execute(['id'=>$pid]);
    $pdo->prepare('UPDATE auth_sessions SET revoked_at=now() WHERE id=:id')->execute(['id'=>$session['id']]);
    $ok($execution->tick()===1&&$execution->once()['status']==='succeeded','scheduled metrics without session');
    $run=$execution->runs($actor,$id,50,0,$pid)[0];$result=$execution->status($actor,$id,$run['id']);
    $ok($result['result']['metrics']['inserted']===2&&$result['result']['collection']['sink']==='metrics','atomic metric result');
    $ok((int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn()===$before,'no CMDB mutation');
    $target=$bindings->show($actor,$id);$series=\Cajeer\Servers\Metrics\MetricStore::series($pdo,$target,100,0,'snmp.interface.receive');$ok(count($series)===1,'series query');
    $sid=$series[0]['id'];$from=gmdate('Y-m-d\TH:i:s\Z',time()-3600);$to=gmdate('Y-m-d\TH:i:s\Z',time()+60);
    $query=\Cajeer\Servers\Metrics\MetricStore::query($pdo,$target,$sid,$from,$to);$ok(count($query['points'])===1&&$query['points'][0]['value']==='18446744073709551615','exact counter64 database roundtrip');
    $samples=$result['result']['collection']['metric_samples'];$at=$result['result']['collection']['observed_at'];
    $r=$db->transaction(fn(PDO $p)=>\Cajeer\Servers\Metrics\MetricStore::ingest($p,$target,$samples,$at));$ok($r['inserted']===0&&$r['deduplicated']===2,'duplicate batch');
    $conflict=$samples;$conflict[0]['value']='999';$reject(409,fn()=>$db->transaction(fn(PDO $p)=>\Cajeer\Servers\Metrics\MetricStore::ingest($p,$target,$conflict,$at)),'conflicting sample rejects batch');
    $next=gmdate('Y-m-d\TH:i:s\Z',time()+10);$reset=\Cajeer\Servers\Metrics\MetricBatch::sample('snmp.interface.receive','10','device',['if_index'=>'1'],'counter','bytes',$next);
    $db->transaction(fn(PDO $p)=>\Cajeer\Servers\Metrics\MetricStore::ingest($p,$target,[$reset],$next));
    $rate=\Cajeer\Servers\Metrics\MetricStore::query($pdo,$target,$sid,$from,$to,'rate',60);$ok(count($rate['points'])===1&&(float)$rate['points'][0]['value']>0,'counter reset produces positive rate');
    $agg=\Cajeer\Servers\Metrics\MetricStore::query($pdo,$target,$sid,$from,$to,'max',3600);$ok(count($agg['points'])>=1,'range aggregation');
    $reject(404,fn()=>\Cajeer\Servers\Metrics\MetricStore::query($pdo,array_replace($target,['workspace_id'=>'11111111-1111-4111-8111-111111111111']),$sid,$from,$to),'workspace scoping');
    $pdo->exec("SELECT cajeer_metric_partition((clock_timestamp() AT TIME ZONE 'UTC')::date-31)");
    $pdo->prepare("INSERT INTO metric_samples(series_id,observed_at,value) VALUES(:id,clock_timestamp()-interval '31 days',1)")->execute(['id'=>$sid]);
    $ret=$db->transaction(fn(PDO $p)=>\Cajeer\Servers\Metrics\MetricStore::retain($p));$ok($ret['deleted']===1,'retention removes expired sample');
    $manual=$execution->runPlan($actor,$id,$pid,'revoke');$execution->revoke($actor,$id,$plan['grant_id']);$prior=$calls;
    $ok($execution->once()['status']==='failed'&&$calls===$prior,'revoked grant blocks metrics');
    echo "PostgreSQL metrics / Scheduler / Vault / retention: $n PASS\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL Metrics PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally {
    if ($pdo instanceof PDO&&$created) {try {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $e){$failed=true;fwrite(STDERR,"FAIL schema cleanup\n");}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)unlink($file);rmdir($dir.'/migrations');rmdir($dir);
}
exit($failed?1:0);
