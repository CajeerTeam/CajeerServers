#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Isolated PostgreSQL ingestion integration using real Vault, grants, inbox and metric store. */
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
$root=dirname(__DIR__);$schema='cajeer_ingestion_'.bin2hex(random_bytes(8));$dir=sys_get_temp_dir().'/'.$schema;
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
    $authorize();$grant=$execution->issue($principal,$id,2,'inspect',3600);
    $service=new \Cajeer\Servers\Ingestion\IngestionService($db,$bindings,$repo,$rbac,$execution,$vault,$crypto,$limiter,$audit);
    $source=$service->create($actor,$id,$grant['id'],'External metrics','metrics');
    $events=$service->create($actor,$id,$grant['id'],'External events','events');
    $observations=$service->create($actor,$id,$grant['id'],'External observations','observations');
    $ok(!isset($service->sources($actor,$id)[0]['secret']),'secret not returned in list');
    $at=gmdate('Y-m-d\TH:i:s\Z');
    $body=static fn($kind,$batch,$rows)=>json_encode(['schema_version'=>1,'batch_id'=>$batch,'kind'=>$kind,'records'=>$rows],JSON_THROW_ON_ERROR);
    $push=static function(array $s,string $raw,?string $key=null)use($service){$ts=time();$sig=\Cajeer\Servers\Security\SignedPayload::signature($s['id']."\n".$raw,$key??$s['secret'],$ts);return $service->ingest($s['id'],new \Cajeer\Servers\Runtime\HttpRequest('POST','/',['Content-Type'=>'application/json','X-Cajeer-Timestamp'=>(string)$ts,'X-Cajeer-Signature'=>$sig],$raw));};
    $sample=['metric'=>'external.bytes','value'=>'18446744073709551615','kind'=>'counter','observed_at'=>$at];$raw=$body('metrics','m1',[$sample]);
    $reject(401,fn()=>$push($source,$raw,str_repeat('a',64)),'invalid signature');
    $b=$push($source,$raw);$ok($b['status']==='queued'&&(int)$pdo->query('SELECT count(*) FROM metric_samples')->fetchColumn()===0,'durable acceptance before processing');
    $ok($push($source,$raw)['deduplicated'],'identical retry');$reject(409,fn()=>$push($source,$raw.' '),'same batch id changed bytes');
    $ok($service->work(1)['processed']===1,'worker processed');$result=$service->batch($actor,$id,$b['id']);$ok($result['status']==='succeeded'&&$result['result']['inserted']===1&&count($result['history'])===1,'persisted result and history');
    $ok($pdo->query('SELECT value::text FROM metric_samples')->fetchColumn()==='18446744073709551615','exact Counter64');
    $b2=$push($source,$body('metrics','m2',[$sample]));$service->work(1);$ok($service->batch($actor,$id,$b2['id'])['result']['deduplicated']===1,'sample dedup across batches');
    $conflict=$push($source,$body('metrics','m3',[array_replace($sample,['metric'=>'another.metric']),array_replace($sample,['value'=>'7'])]));$service->work(1);$ok($service->batch($actor,$id,$conflict['id'])['status']==='failed'&&(int)$pdo->query('SELECT count(*) FROM metric_series')->fetchColumn()===1,'atomic conflict rollback');
    $service->redrive($actor,$id,$conflict['id']);$service->work(1);$ok(count($service->batch($actor,$id,$conflict['id'])['history'])===2,'redrive retains history');
    $event=['event_id'=>'e1','type'=>'resource.deleted','severity'=>'warning','message'=>'Notice only','observed_at'=>$at];$eventBatch=$push($events,$body('events','e1',[$event]));$service->work(1);$ok(count($service->events($actor,$id))===1,'event persisted');
    $dup=$push($events,$body('events','e2',[$event]));$service->work(1);$ok($service->batch($actor,$id,$dup['id'])['result']['deduplicated']===1,'event ID dedup');
    $collision=$push($events,$body('events','e3',[array_replace($event,['message'=>'different'])]));$service->work(1);$ok($service->batch($actor,$id,$collision['id'])['error_code']==='event_conflict','event collision');
    // Force a real transient SQLSTATE in the savepoint, then remove the fault and retry.
    $pdo->exec("CREATE FUNCTION fixture_ingestion_retry() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event_id='retry' AND current_setting('cajeer.test_retry',true)='on' THEN RAISE EXCEPTION 'fixture lock' USING ERRCODE='55P03'; END IF; RETURN NEW; END; $$");
    $pdo->exec("CREATE TRIGGER fixture_ingestion_retry BEFORE INSERT ON data_events FOR EACH ROW EXECUTE FUNCTION fixture_ingestion_retry()");
    $pdo->exec("SET cajeer.test_retry='on'");$retry=$push($events,$body('events','retry',[array_replace($event,['event_id'=>'retry'])]));$service->work(1);
    $r=$service->batch($actor,$id,$retry['id']);$ok($r['status']==='queued'&&$r['history'][0]['outcome']==='retry_scheduled','transient SQL failure rolled back and retried');
    $pdo->exec("SET cajeer.test_retry='off'");$pdo->prepare("UPDATE data_ingestion_inbox SET available_at=clock_timestamp()-interval '1 second' WHERE id=:id")->execute(['id'=>$retry['id']]);$service->work(1);
    $ok($service->batch($actor,$id,$retry['id'])['status']==='succeeded'&&count($service->batch($actor,$id,$retry['id'])['history'])===2,'retry recovered with attempt history');
    $otherSource=$service->create($actor,$id,$grant['id'],'Second producer','metrics');$otherBatch=$push($otherSource,$body('metrics','m1',[$sample]));$service->work(1);
    $ok((int)$pdo->query("SELECT count(*) FROM metric_series WHERE metric='external.bytes'")->fetchColumn()===2,'two push sources are distinct metric identities');
    $otherTarget=$bindings->create($actor,['workspace_id'=>$ws,'provider'=>'snmp','name'=>'Other target','endpoint_host'=>'127.0.0.1','endpoint_port'=>1162,'asset_id'=>$asset['id']]);
    $reject(404,fn()=>$service->batch($actor,$otherTarget['id'],$b['id']),'cross target batch isolation');
    $obs=['external_id'=>'external-host','type'=>'server','fields'=>['name'=>'candidate'],'observed_at'=>$at];$candidate=$push($observations,$body('observations','o1',[$obs]));$service->work(1);$ok($service->batch($actor,$id,$candidate['id'])['result']['applied']===false&&(int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn()===1,'candidate does not mutate CMDB');
    // 0.5.8 manual import and durable exports over the same real persisted data.
    $csv="event_id,type,severity,message,observed_at\nmanual1,system.changed,info,=1+2,$at";
    $preview=$service->manual($actor,$id,$events['id'],'csv','manual1',$csv);
    $ok($preview['count']===1&&$preview['records'][0]['message']==='=1+2','manual import preview');
    $reject(409,fn()=>$service->manual($actor,$id,$events['id'],'csv','manual1',str_replace('=1+2','different',$csv),$preview['confirmation']),'changed preview denied');
    $manual=$service->manual($actor,$id,$events['id'],'csv','manual1',$csv,$preview['confirmation']);
    $ok($manual['status']==='queued'&&$service->batch($actor,$id,$manual['id'])['accepted_by']===$actor,'durable manual provenance');
    $ok($service->manual($actor,$id,$events['id'],'csv','manual1',$csv,$preview['confirmation'])['deduplicated'],'manual idempotency');$service->work(1);
    $ok($service->batch($actor,$id,$manual['id'])['status']==='succeeded','manual events persisted by worker');
    $exports=new \Cajeer\Servers\ImportExport\ExportManager($db,$bindings,$repo,$rbac,$audit,new \Cajeer\Servers\ImportExport\ExporterRegistry());
    $selection=['kind'=>'metrics','format'=>'json','from'=>gmdate('Y-m-d\TH:i:s\Z',time()-3600),'to'=>gmdate('Y-m-d\TH:i:s\Z',time()+60)];
    $job=$exports->create($actor,$id,$selection,'export-1');$ok($job['status']==='queued','export durable before worker');
    $ok($exports->create($actor,$id,$selection,'export-1')['id']===$job['id'],'export idempotency');$reject(409,fn()=>$exports->create($actor,$id,array_replace($selection,['format'=>'csv']),'export-1'),'export key conflict');
    $reject(409,fn()=>$exports->download($actor,$id,$job['id']),'not ready download');$exports->work(1);$artifact=$exports->download($actor,$id,$job['id']);
    $ok(hash('sha256',$artifact['body'])===$artifact['sha256']&&count(json_decode($artifact['body'],true)['records'])===2&&str_contains($artifact['body'],'18446744073709551615'),'real metric export exact values and checksum');
    $reject(404,fn()=>$exports->show($actor,$otherTarget['id'],$job['id']),'cross-target export isolation');
    // Current schema includes profile columns needed by the live BindingService.
    // Upgrade from 0.6.0 is separately tested by proxmox-profile-postgres-integration.php.
    $upgrade=new MigrationRunner($db,$dir.'/migrations');
    $ok($upgrade->validate()['valid']&&$upgrade->run()['applied']===[],'current migration replay and checksum validation');
    $ok($exports->download($actor,$id,$job['id'])['sha256']===$artifact['sha256'],'upgrade preserves artifact');
    $importSource=$service->create($actor,$id,$grant['id'],'Roundtrip metrics','metrics');
    $reject(422,fn()=>$service->manual($actor,$id,$importSource['id'],'json','archive-roundtrip',$artifact['body']),'archive cannot merge distinct series');
    $series=$pdo->prepare('SELECT id FROM metric_series WHERE ingestion_source_id=:source');$series->execute(['source'=>$source['id']]);
    $single=$exports->create($actor,$id,array_replace($selection,['series_id'=>$series->fetchColumn()]),'export-single-series');$exports->work(1);$portable=$exports->download($actor,$id,$single['id']);
    $preview=$service->manual($actor,$id,$importSource['id'],'json','archive-roundtrip',$portable['body']);
    $imported=$service->manual($actor,$id,$importSource['id'],'json','archive-roundtrip',$portable['body'],$preview['confirmation']);
    $service->work(1);$ok($service->batch($actor,$id,$imported['id'])['status']==='succeeded','actual exported JSON import worker');
    $roundTrip=$pdo->prepare('SELECT m.value::text FROM metric_samples m JOIN metric_series s ON s.id=m.series_id WHERE s.ingestion_source_id=:source AND m.value=18446744073709551615');
    $roundTrip->execute(['source'=>$importSource['id']]);$ok($roundTrip->fetchColumn()==='18446744073709551615','exact archive value in destination PostgreSQL series');
    $pdo->prepare("UPDATE data_export_jobs SET finished_at=clock_timestamp()-interval '8 days' WHERE id=:id")->execute(['id'=>$job['id']]);
    $reject(410,fn()=>$exports->download($actor,$id,$job['id']),'expired artifact denied before cleanup');
    $pdo->prepare("UPDATE data_export_jobs SET finished_at=clock_timestamp() WHERE id=:id")->execute(['id'=>$job['id']]);

    $eventJob=$exports->create($actor,$id,array_replace($selection,['kind'=>'events','format'=>'csv']),'events-export');$exports->work(1);$eventArtifact=$exports->download($actor,$id,$eventJob['id']);$ok(str_contains($eventArtifact['body'],"'=1+2"),'CSV protection on persisted events');
    $om=$exports->create($actor,$id,array_replace($selection,['format'=>'openmetrics']),'om-export');$exports->work(1);$ok(str_ends_with($exports->download($actor,$id,$om['id'])['body'],"# EOF\n"),'OpenMetrics latest export');
    $cancel=$exports->create($actor,$id,$selection,'cancel-export');$ok($exports->cancel($actor,$id,$cancel['id'])['status']==='cancelled','export cancellation');
    $pdo->prepare("UPDATE data_export_jobs SET finished_at=clock_timestamp()-interval '8 days' WHERE id=:id")->execute(['id'=>$cancel['id']]);$ok($exports->retain()['deleted']===1,'artifact retention');
    $rotated=$service->change($actor,$id,$source['id'],true);$reject(401,fn()=>$push($source,$raw),'old HMAC revoked');$ok($push($rotated,$raw)['deduplicated'],'new key valid');
    $queued=$push($events,$body('events','e4',[array_replace($event,['event_id'=>'e4'])]));$service->change($actor,$id,$events['id'],false);$service->work(1);$ok($service->batch($actor,$id,$queued['id'])['error_code']==='ingestion_revoked','revoke before commit');
    $queued=$push($rotated,$body('metrics','m4',[array_replace($sample,['metric'=>'new.metric'])]));$execution->revoke($actor,$id,$grant['id']);$service->work(1);$ok($service->batch($actor,$id,$queued['id'])['status']==='failed','grant revocation fences write');
    $q=$pdo->prepare('INSERT INTO users(email,name,password_hash,two_factor_enabled) VALUES(:email,:name,:hash,true) RETURNING id::text');$q->execute(['email'=>'outside@example.invalid','name'=>'Outside','hash'=>password_hash($password,PASSWORD_ARGON2ID)]);$outside=$q->fetchColumn();$pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")->execute(['id'=>$outside]);$reject(404,fn()=>$service->batches($outside,$id),'workspace membership isolation');
    $pdo->prepare("UPDATE data_ingestion_inbox SET finished_at=clock_timestamp()-interval '31 days' WHERE id=:id")->execute(['id'=>$b['id']]);$ret=$service->retain(1000);$ok($ret['batches_deleted']===1,'retention deletes expired inbox and attempts');$reject(404,fn()=>$service->batch($actor,$id,$b['id']),'expired batch removed');
    $next=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));(new \Cajeer\Servers\Credentials\VaultKeyRotation($db,$crypto,$key,$vault,$audit))->rotate($next);
    $q=$pdo->prepare("SELECT encode(secret_ciphertext,'hex') FROM data_ingestion_sources WHERE id=:id");$q->execute(['id'=>$source['id']]);$ok((new VaultCrypto($next))->open(hex2bin($q->fetchColumn()),$source['id'],$ws,'data_ingestion')===$rotated['secret'],'offline master key rotation preserves HMAC');
    echo "PostgreSQL ingestion / dedup / revocation / rotation: $n PASS\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL Ingestion PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally {
    if ($pdo instanceof PDO&&$created) {try {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $e){$failed=true;fwrite(STDERR,"FAIL schema cleanup\n");}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)unlink($file);rmdir($dir.'/migrations');rmdir($dir);
}
exit($failed?1:0);
