#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Isolated PostgreSQL action service integration with deterministic Proxmox driver fixture. */
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
$root=dirname(__DIR__);$schema='cajeer_action_'.bin2hex(random_bytes(8));$dir=sys_get_temp_dir().'/'.$schema;
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
    $driver=new class implements \Cajeer\Servers\Connector\ConnectorInterface, \Cajeer\Servers\Connector\ConnectorActionDriver {
        public int $posts=0;public bool $fail=false;
        public function manifest():\Cajeer\Servers\Connector\ConnectorManifest{return new \Cajeer\Servers\Connector\ConnectorManifest('proxmox','api',['proxmox_api'],['inspect','sync','health','validate_rotation'],['hypervisor_node','virtual_machine'],'https');}
        public function execute(\Cajeer\Servers\Connector\ConnectorContext $c,string $op,string $secret):\Cajeer\Servers\Connector\ConnectorResult{return new \Cajeer\Servers\Connector\ConnectorResult([['type'=>'hypervisor_node','external_id'=>'node:pve','fields'=>['name'=>'pve','status'=>'online']],['type'=>'virtual_machine','external_id'=>'qemu:101','parent_external_id'=>'node:pve','fields'=>['name'=>'test-vm','status'=>'offline']]],true);}
        public function actions():array{return ['vm.start','vm.shutdown'];}
        public function prepareAction(\Cajeer\Servers\Connector\ConnectorContext $c,string $action,string $externalId,string $secret):array{return ['node'=>'pve','vmid'=>101];}
        public function dispatchAction(\Cajeer\Servers\Connector\ConnectorContext $c,string $action,array $r,string $secret):string{$this->posts++;if($this->fail)throw new RuntimeException('fixture lost response');return 'UPID:pve:00000001:00000002:00000003:qmstart:101:svc@pve!test:';}
        public function actionStatus(\Cajeer\Servers\Connector\ConnectorContext $c,string $node,string $task,string $secret):string{return 'succeeded';}
    };
    $registry=new ConnectorRegistry($driver);
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
    $asset=$inventory->create($actor,['workspace_id'=>$ws,'type'=>'server','name'=>'PVE endpoint']);
    $credential=$store->create($actor,['workspace_id'=>$ws,'name'=>'PVE credential','type'=>'proxmox_api','secret'=>['token_id'=>'svc@pve!test','token_secret'=>'fixture-secret']]);
    $target=$bindings->create($actor,['workspace_id'=>$ws,'provider'=>'proxmox','name'=>'PVE integration','endpoint_host'=>'127.0.0.1','endpoint_port'=>8006,'asset_id'=>$asset['id']]);
    $id=$target['id'];$cid=$credential['id'];$bindings->bind($actor,$id,$cid,'api',1,null,true);
    $authorize=static function()use($pdo,$actor,$principal,$access,$asset,$cid,$password,$totpKey):void {
        $pdo->exec("DELETE FROM security_rate_limits");$pdo->prepare('UPDATE user_mfa SET last_counter=NULL WHERE user_id=:id')->execute(['id'=>$actor]);
        $access->authorize($principal,$asset['id'],$cid,'api',$password,TwoFactorManager::totp($totpKey,time()));
    };
    $actions=new \Cajeer\Servers\Connector\ConnectorActionService($db,$bindings,$repo,$access,$registry,$rbac,$audit,true);
    $disabled=new \Cajeer\Servers\Connector\ConnectorActionService($db,$bindings,$repo,$access,$registry,$rbac,$audit,false);
    $body=['action'=>'vm.start','external_id'=>'qemu:101','confirm'=>'vm.start qemu:101'];$key='test-idempotency-0001';
    $reject(403,fn()=>$disabled->submit($principal,$id,2,$body,$key),'disabled by default');
    $reject(409,fn()=>$actions->submit($principal,$id,2,$body,$key),'unmapped VM denied');
    $authorize();$runtime->run($principal,$id,2,true);
    $reject(403,fn()=>$actions->submit($principal,$id,2,$body,$key),'fresh MFA required');
    $authorize();$row=$actions->submit($principal,$id,2,$body,$key);$ok($row['status']==='submitted'&&$driver->posts===1,'durable dispatch');
    $same=$actions->submit($principal,$id,2,$body,$key);$ok($same['id']===$row['id']&&$driver->posts===1,'idempotent duplicate without POST');
    $reject(409,fn()=>$actions->submit($principal,$id,2,$body,'test-idempotency-0002'),'unresolved action blocks duplicate');
    $authorize();$done=$disabled->refresh($principal,$id,$row['id'],2);$ok($done['status']==='succeeded'&&$driver->posts===1,'task confirmed without POST when writes disabled');
    $driver->fail=true;$authorize();$unknown=$actions->submit($principal,$id,2,$body,'test-idempotency-0002');$ok($unknown['status']==='unknown'&&$driver->posts===2,'ambiguous response persisted');
    $reject(409,fn()=>$actions->submit($principal,$id,2,$body,'test-idempotency-0003'),'unknown blocks repeat');
    $authorize();$ack=$disabled->refresh($principal,$id,$unknown['id'],2,true);$ok($ack['status']==='acknowledged'&&$driver->posts===2,'operator acknowledgement no write');
    $ok(count($actions->list($principal,$id))===2,'history persisted');
    $pdo->exec("DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE scope='connectors.actions.execute')");
    $reject(403,fn()=>$actions->submit($principal,$id,2,$body,'test-idempotency-0003'),'action scope revoked');
    echo "PostgreSQL connector actions: $n PASS\n";
} catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL Connector Actions PostgreSQL: '.$e::class.': '.$e->getMessage()."\n");}
finally {
    if ($pdo instanceof PDO&&$created) {try {if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}catch(Throwable $e){$failed=true;fwrite(STDERR,"FAIL schema cleanup\n");}}
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)unlink($file);rmdir($dir.'/migrations');rmdir($dir);
}
exit($failed?1:0);
