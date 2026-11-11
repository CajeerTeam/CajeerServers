#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Actual PostgreSQL integration, isolated random schema in a disposable cajeer_test_* database. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Credentials\CredentialProfile;
use Cajeer\Servers\Credentials\CredentialStore;
use Cajeer\Servers\Credentials\CredentialsVault;
use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\CredentialBindingService;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\InventoryService;
use Cajeer\Servers\Security\Rbac;

if (getenv('CAJEER_PG_TEST')!=='1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')) {
    fwrite(STDERR,"REFUSED: CAJEER_PG_TEST=1 and a disposable cajeer_test_* database are required.\n");exit(2);
}
if (!extension_loaded('pdo_pgsql') || !extension_loaded('sodium')) {
    fwrite(STDERR,"FAIL: pdo_pgsql and sodium are required.\n");exit(1);
}
$root=dirname(__DIR__);
$dir=sys_get_temp_dir().'/cajeer-vault-crud-'.bin2hex(random_bytes(8));
$schema='cajeer_vaultcrud_'.bin2hex(random_bytes(8));
mkdir($dir.'/migrations',0700,true);
$pdo=null;$created=false;$failed=false;$checks=0;
$ok=static function(bool $value,string $message)use(&$checks):void{
    if (!$value) throw new RuntimeException('FAIL: '.$message);
    $checks++;
};
$reject=static function(int $status,callable $action,string $message)use($ok):void{
    try{$action();}catch(HttpException $exception){$ok($exception->status===$status,$message);return;}
    throw new RuntimeException('Expected HTTP '.$status.': '.$message);
};
try{
    $db=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),[
        'driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1',
        'port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432',
        'database'=>getenv('CAJEER_PG_TEST_DATABASE'),'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer',
        'password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'',
        'sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer',
    ]);
    $pdo=$db->connection();
    $ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;
    $pdo->exec('SET search_path TO "'.$schema.'", public');
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file) copy($file,$dir.'/migrations/'.basename($file));
    $migrations=new MigrationRunner($db,$dir.'/migrations');
    $ok(count($migrations->run()['applied'])===count(glob($root.'/migrations/pgsql/*.sql')),'all migrations executed');
    $ok($migrations->validate()['valid']===true && $migrations->run()['applied']===[],'migration journal idempotent');
    $seed=$pdo->prepare('INSERT INTO users(email,name,password_hash) VALUES(:email,:name,:hash) RETURNING id::text');
    $user=static function(string $name)use($seed):string{
        $seed->execute(['email'=>$name.bin2hex(random_bytes(4)).'@example.invalid','name'=>$name,
            'hash'=>password_hash('test-only-NO-PROD-password!',PASSWORD_ARGON2ID)]);
        return (string)$seed->fetchColumn();
    };
    $admin=$user('admin');$viewer=$user('viewer');$outsider=$user('outsider');
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :actor,id FROM roles WHERE slug='administrator'")->execute(['actor'=>$admin]);
    $workspace=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $join=$pdo->prepare('INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(CAST(:ws AS uuid),CAST(:actor AS uuid),:role)');
    $join->execute(['ws'=>$workspace,'actor'=>$admin,'role'=>'owner']);
    $join->execute(['ws'=>$workspace,'actor'=>$viewer,'role'=>'viewer']);
    $otherWs=(string)$pdo->query("INSERT INTO workspaces(organization_id,slug,name) SELECT organization_id,'other-vault','Other Vault Workspace' FROM workspaces WHERE id='".$workspace."'::uuid RETURNING id::text")->fetchColumn();
    $join->execute(['ws'=>$otherWs,'actor'=>$outsider,'role'=>'owner']);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$inventory=new InventoryService($db,$repo,$audit);
    $rbac=new Rbac($db,$audit);
    $rbac->requirePermission(['mode'=>'session','user'=>['id'=>$admin]],'vault.credentials.create');
    $reject(403,fn()=>$rbac->requirePermission(['mode'=>'session','user'=>['id'=>$viewer]],'vault.credentials.create'),'viewer lacks global Vault write permission');
    $key=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));
    $crypto=new VaultCrypto($key);
    $vault=new CredentialsVault($db,$crypto,$key);
    $assertKey=$vault->initialize();
    $ok($assertKey['initialized']===true && $vault->check()['ok']===true,'real key verification');
    $store=new CredentialStore($db,$repo,$vault,$crypto,$audit);
    $secret='test-only-Vault-value_'.bin2hex(random_bytes(12));
    $createdCredential=$store->create($admin,[
        'workspace_id'=>$workspace,'name'=>'Test API credential','description'=>'CI disposable secret',
        'type'=>'api_token','secret'=>$secret,
    ]);
    $id=$createdCredential['id'];
    $ok($createdCredential['version']===1 && $createdCredential['state']==='active','encrypted credential created');
    $ok(!isset($createdCredential['secret'],$createdCredential['encrypted_payload'],$createdCredential['metadata']),'create response excludes secret fields');
    $query=$pdo->prepare('SELECT encode(encrypted_payload,\'hex\') FROM credentials WHERE id=CAST(:id AS uuid)');
    $query->execute(['id'=>$id]);$cipher=hex2bin((string)$query->fetchColumn());
    $ok(is_string($cipher) && $crypto->open($cipher,$id,$workspace,'api_token')===$secret,'actual PostgreSQL ciphertext decrypts with matching AAD');
    $ok(!str_contains($cipher,$secret),'plaintext never written to encrypted_payload');
    $page=$store->list($admin,$workspace);
    $ok(count($page['data'])===1 && $page['pagination']['total']===1,'real bounded workspace list');
    $ok(!str_contains(json_encode($page,JSON_THROW_ON_ERROR),$secret),'list never exposes plaintext');
    $ok($store->show($admin,$id)['etag']===CredentialInput::etag($id,1),'show includes version ETag');
    $reject(404,fn()=>$store->list($outsider,$workspace),'foreign workspace cannot list');
    $reject(404,fn()=>$store->show($outsider,$id),'foreign workspace cannot show');
    $reject(403,fn()=>$store->create($viewer,['workspace_id'=>$workspace,'name'=>'Forbidden','type'=>'api_token','secret'=>'unimportant']),'workspace viewer cannot create');
    $reject(404,fn()=>$store->create($admin,['workspace_id'=>$otherWs,'name'=>'Forbidden','type'=>'api_token','secret'=>'unimportant']),'foreign workspace cannot create');
    $row=$store->update($admin,$id,1,['name'=>'Renamed credential','description'=>'not a secret']);
    $ok($row['name']==='Renamed credential' && $row['version']===2,'metadata changes and increments version');
    $reject(412,fn()=>$store->replaceSecret($admin,$id,1,'stale'),'stale ETag refused');
    $secret2='replacement-value-'.bin2hex(random_bytes(9));
    $row=$store->replaceSecret($admin,$id,2,$secret2);
    $ok($row['version']===3 && !isset($row['secret']),'secret replaced without returning it');
    $query->execute(['id'=>$id]);$newCipher=hex2bin((string)$query->fetchColumn());
    $ok($newCipher!==$cipher && $crypto->open($newCipher,$id,$workspace,'api_token')===$secret2,'replacement cipher stored in PostgreSQL');
    $bind=new CredentialBindingService($db,$repo,$audit);
    $legacy=$pdo->prepare("INSERT INTO credentials(name,type,encrypted_payload) VALUES('legacy unverified','api',decode(:cipher,'hex')) RETURNING id::text");
    $legacy->execute(['cipher'=>bin2hex(random_bytes(32))]);$legacyId=(string)$legacy->fetchColumn();
    $reject(404,fn()=>$bind->scope($admin,$workspace,$legacyId),'unverified legacy credential cannot be claimed');
    $ok($bind->scope($admin,$workspace,$id)['credential_id']===$id,'new credential has authorized workspace scope');
    $assets=$inventory->create($admin,['workspace_id'=>$workspace,'type'=>'server','name'=>'Vault-test server']);
    $asset=$assets['id'];
    $binding=$bind->attach($admin,$asset,$id,'api');
    $ok($binding['credential_id']===$id,'authorized Inventory binding works');
    $reject(409,fn()=>$store->archive($admin,$id,3),'referenced credential cannot be archived');
    $disabled=$store->update($admin,$id,3,['status'=>'disabled']);
    $ok($disabled['state']==='disabled','credential disabled immediately');
    $reject(403,fn()=>$bind->attach($admin,$asset,$id,'ssh'),'disabled credential cannot be newly attached');
    $bind->detach($admin,$asset,$binding['id']);
    $archived=$store->archive($admin,$id,4);
    $ok($archived['state']==='archived' && $archived['deleted_at']!==null,'archive prevents future use and maintains encrypted row');
    $reject(404,fn()=>$store->show($admin,$id),'archived credential is hidden');
    $reject(404,fn()=>$store->replaceSecret($admin,$id,5,'blocked'),'archived credential is immutable');
    $reject(404,fn()=>$bind->scope($admin,$workspace,$id),'archived credential cannot be scoped');
    $query->execute(['id'=>$id]);$archivedCipher=hex2bin((string)$query->fetchColumn());
    $ok($archivedCipher===$newCipher,'encrypted data unchanged after archive');
    // Real persistence of each 0.3.3 structured format: no plaintext or raw JSON
    // should be exposed by the metadata API, even after replacement.
    $profileCases = [
        'proxmox_api'=>['token_id'=>'root@pam!monitor','token_secret'=>'test-only-very-secret'],
        'vmmanager_api'=>['token'=>'test-only-very-secret'],
        'ssh_password'=>['username'=>'operator','password'=>'test-only-very-secret'],
        'winrm'=>['username'=>'DOMAIN\\svc','password'=>'test-only-very-secret','auth'=>'negotiate'],
        'snmp_v2c'=>['community'=>'test-only-very-secret'],
        'snmp_v3'=>['username'=>'monitor','auth_protocol'=>'sha256',
            'auth_passphrase'=>'test-only-very-secret', 'privacy_protocol'=>'aes128',
            'privacy_passphrase'=>'test-only-very-secret'],
    ];
    $sshKey=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
    if ($sshKey===false || !openssl_pkey_export($sshKey,$sshPrivate)) throw new RuntimeException('OpenSSL key generation failed');
    $profileCases['ssh_private_key']=['username'=>'deploy','private_key'=>$sshPrivate];
    foreach($profileCases as $type=>$secretPayload) {
        $record=$store->create($admin,['workspace_id'=>$workspace,'name'=>'Integration '.$type,
            'type'=>$type,'secret'=>$secretPayload]);
        $profileId=$record['id'];
        $ok($record['type']===$type && $record['version']===1,'persisted profile '.$type);
        $encoded=CredentialProfile::encode($type,$secretPayload);
        $q=$pdo->prepare("SELECT encrypted_payload FROM credentials WHERE id=CAST(:id AS uuid)");
        $q->execute(['id'=>$profileId]);$persisted=$q->fetchColumn();
        $ok(is_string($persisted) && $crypto->open($persisted,$profileId,$workspace,$type)===$encoded,
            'PostgreSQL encrypted payload roundtrip '.$type);
        $ok(!str_contains(json_encode($store->show($admin,$profileId),JSON_THROW_ON_ERROR),$encoded),
            'metadata does not expose secret for '.$type);
        $after=$store->replaceSecret($admin,$profileId,1,$secretPayload);
        $ok($after['version']===2 && !isset($after['secret']),'structured replacement '.$type);
        $q->execute(['id'=>$profileId]);$replacement=$q->fetchColumn();
        $ok(is_string($replacement) && $replacement!==$persisted &&
            $crypto->open($replacement,$profileId,$workspace,$type)===$encoded,
            'unique envelope on replacement '.$type);
    }
    // A direct DB update cannot break the AEAD identity binding, even with incremented version.
    try {
        $pdo->prepare('UPDATE credentials SET workspace_id=CAST(:other AS uuid),version=version+1 WHERE id=CAST(:id AS uuid)')
            ->execute(['other'=>$otherWs,'id'=>$id]);
        throw new RuntimeException('Workspace reassignment incorrectly accepted');
    } catch (PDOException $exception) {
        $ok($exception->getCode()==='23514','SQL trigger blocks cryptographic context reassignment');
    }
    $count=(int)$pdo->query("SELECT count(*) FROM audit_log WHERE action LIKE 'vault.credential.%'")->fetchColumn();
    $ok($count>=5,'operations produce actual transactional audit events');
    echo "Vault encrypted CRUD PostgreSQL integration: $checks PASS\n";
}catch(Throwable $error){$failed=true;fwrite(STDERR,'FAIL: vault-crud-postgres: '.$error::class.': '.$error->getMessage()."\n");}
finally{
    if($pdo instanceof PDO && $created){
        try{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
        catch(Throwable $error){$failed=true;fwrite(STDERR,'FAIL: isolated schema cleanup: '.$error->getMessage()."\n");}
    }
    foreach(glob($dir.'/migrations/*')?:[] as $file)@unlink($file);
    @rmdir($dir.'/migrations');@rmdir($dir);
}
exit($failed?1:0);
