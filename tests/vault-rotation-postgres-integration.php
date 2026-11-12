#!/usr/bin/env php
<?php
declare(strict_types=1);
/** PostgreSQL 17/18 integration, isolated random schema in an explicitly disposable test DB. */
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Credentials\CredentialStore;
use Cajeer\Servers\Credentials\CredentialVersionService;
use Cajeer\Servers\Credentials\CredentialsVault;
use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultKeyRotation;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\InventoryRepository;

if (getenv('CAJEER_PG_TEST') !== '1' ||
    !preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')) {
    fwrite(STDERR,"REFUSED: disposable cajeer_test_* database and CAJEER_PG_TEST=1 are required.\n");
    exit(2);
}
if (!extension_loaded('pdo_pgsql') || !extension_loaded('sodium')) {
    fwrite(STDERR,"FAIL: pdo_pgsql and sodium are required.\n");
    exit(1);
}
$root=dirname(__DIR__);
$schema='cajeer_vaultrotate_'.bin2hex(random_bytes(6));
$folder=sys_get_temp_dir().'/cajeer-vaultrotate-'.bin2hex(random_bytes(6));
mkdir($folder.'/migrations',0700,true);
$pdo=null;$created=false;$failed=false;$checks=0;
$ok=static function(bool $valid,string $description)use(&$checks):void {
    if (!$valid) throw new RuntimeException('FAIL: '.$description);
    $checks++;
};
$deny=static function(int $code,callable $fn,string $label)use($ok):void {
    try{$fn();}catch(HttpException $e){$ok($e->status===$code,$label);return;}
    throw new RuntimeException('FAIL: expected HTTP '.$code.' '.$label);
};
$reject=static function(callable $fn,string $label)use($ok):void {
    try{$fn();}catch(Throwable){$ok(true,$label);return;}
    throw new RuntimeException('FAIL: expected failure '.$label);
};
try {
    $db=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),[
        'driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1',
        'port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432',
        'database'=>getenv('CAJEER_PG_TEST_DATABASE'),
        'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer',
        'password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'',
        'sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer',
    ]);
    $pdo=$db->connection();
    $ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PG 17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;
    $pdo->exec('SET search_path TO "'.$schema.'", public');
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file)
        if(!copy($file,$folder.'/migrations/'.basename($file)))throw new RuntimeException('Cannot copy migration');
    $runner=new MigrationRunner($db,$folder.'/migrations');
    $ok(count($runner->run()['applied'])===count(glob($root.'/migrations/pgsql/*.sql')),'all real migrations applied');
    $ok($runner->validate()['valid'] && $runner->run()['applied']===[],'migration journal intact');
    $seed=$pdo->prepare("INSERT INTO users(email,name,password_hash) VALUES(:email,'Vault rotation tester',:hash) RETURNING id::text");
    $seed->execute(['email'=>'vaultrotation'.bin2hex(random_bytes(5)).'@example.invalid',
        'hash'=>password_hash('integration-only-strong-password',PASSWORD_ARGON2ID)]);
    $actor=(string)$seed->fetchColumn();
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT CAST(:actor AS uuid),id FROM roles WHERE slug='administrator'")
        ->execute(['actor'=>$actor]);
    $ws=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(CAST(:ws AS uuid),CAST(:actor AS uuid),'owner')")
        ->execute(['ws'=>$ws,'actor'=>$actor]);

    $oldKey=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));
    $newKey=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));
    $oldCrypto=new VaultCrypto($oldKey);
    $vault=new CredentialsVault($db,$oldCrypto,$oldKey);
    $ok($vault->initialize()['initialized']===true,'initial verifier stored');
    $ok($vault->check()['ok']===true,'initial verifier validates');
    $audit=new AuditLogger($db);$inventory=new InventoryRepository();
    $store=new CredentialStore($db,$inventory,$vault,$oldCrypto,$audit);
    $versions=new CredentialVersionService($db,$inventory,$vault,$oldCrypto,$audit,new \Cajeer\Servers\Integration\ConnectorRegistry(new \Cajeer\Servers\Connector\ProxmoxConnector()));
    $initial='integration-old-secret-'.bin2hex(random_bytes(10));
    $createdCred=$store->create($actor,['workspace_id'=>$ws,'type'=>'api_token','name'=>'test-key','secret'=>$initial]);
    $id=$createdCred['id'];
    $ok($createdCred['secret_version']===1,'first secret revision persists');
    $ok(count($versions->history($actor,$id)['data'])===1,'history returns one metadata row');
    $ok(!str_contains(json_encode($versions->history($actor,$id),JSON_THROW_ON_ERROR),$initial),'history never exposes plaintext');
    $pending=$versions->stage($actor,$id,1,'staged-secret-'.bin2hex(random_bytes(5)));
    $ok($pending['secret_version']===2 && $pending['state']==='pending' && $pending['version']===2,'stage creates next encrypted revision');
    $deny(409,fn()=>$versions->stage($actor,$id,2,'blocked'),'only one pending rotation');
    $deny(412,fn()=>$versions->cancel($actor,$id,1,2),'stale ETag refused');
    $deny(422,fn()=>$versions->activate($actor,$id,2,2,false),'unverified external activation refused');
    $deny(409,fn()=>$store->replaceSecret($actor,$id,2,'blocked'),'direct replacement blocked while pending');
    $cancel=$versions->cancel($actor,$id,2,2);
    $ok($cancel['state']==='discarded' && $cancel['version']===3,'staged version cancelled');
    $nextSecret='integration-new-secret-'.bin2hex(random_bytes(10));
    $pending=$versions->stage($actor,$id,3,$nextSecret);
    $ok($pending['secret_version']===3 && $pending['version']===4,'discarded version number never reused');
    $active=$versions->activate($actor,$id,4,3,true);
    $ok($active['secret_version']===3 && $active['version']===5 && $active['state']==='active','new version activated');
    $states=$versions->history($actor,$id)['data'];
    $ok(count($states)===3 && array_column($states,'state','secret_version')[1]==='retired',
        'previous active secret retired without deleting ciphertext');
    $q=$pdo->prepare("SELECT encode(encrypted_payload,'hex') FROM credentials WHERE id=CAST(:id AS uuid)");
    $q->execute(['id'=>$id]);$oldActive=hex2bin((string)$q->fetchColumn());
    $ok($oldCrypto->open($oldActive,$id,$ws,'api_token')===$nextSecret,'PostgreSQL current secret changed');
    $replace=$store->replaceSecret($actor,$id,5,'direct-replacement-'.bin2hex(random_bytes(5)));
    $ok($replace['secret_version']===4 && $replace['version']===6,'legacy direct replacement also records history');
    $archived=$store->create($actor,['workspace_id'=>$ws,'type'=>'api_token','name'=>'archived-key','secret'=>'archived-only-data']);
    $store->archive($actor,$archived['id'],1);
    $ok((int)$pdo->query("SELECT count(*) FROM vault_credential_versions")->fetchColumn()===5,
        'active, pending, retired and archived encrypted versions retained');

    $q->execute(['id'=>$id]);$beforeRotate=hex2bin((string)$q->fetchColumn());
    $rotation=(new VaultKeyRotation($db,$oldCrypto,$oldKey,$vault,$audit))->rotate($newKey);
    $ok($rotation['rotated'] && $rotation['credentials']===2 && $rotation['versions']===5,
        'all live and historical envelopes rewrapped in one DB transaction');
    $newCrypto=new VaultCrypto($newKey);
    $newVault=new CredentialsVault($db,$newCrypto,$newKey);
    $ok($newVault->check()['ok']===true,'new active key authenticates PostgreSQL verifier');
    $reject(fn()=>$vault->check(),'old key fails closed after key switch');
    $q->execute(['id'=>$id]);$newActive=hex2bin((string)$q->fetchColumn());
    $ok(str_starts_with($newCrypto->open($newActive,$id,$ws,'api_token'),'direct-replacement-'),
        'new master key decrypts live payload');
    $ok(substr($beforeRotate,92)===substr($newActive,92),
        'key rotation leaves current payload nonce and ciphertext unchanged');
    $history=$pdo->prepare("SELECT encode(v.encrypted_payload,'hex') AS blob,c.workspace_id::text,c.type
        FROM vault_credential_versions v JOIN credentials c ON c.id=v.credential_id
        WHERE v.credential_id=CAST(:id AS uuid)");
    $history->execute(['id'=>$id]);
    foreach($history->fetchAll(PDO::FETCH_ASSOC) as $version){
        $blob=hex2bin((string)$version['blob']);
        $ok(is_string($newCrypto->open($blob,$id,$ws,'api_token')),'each historical revision still decryptable');
    }
    // An old web worker that passed auth before key switch cannot introduce stale-key ciphertext.
    $oldCipher=$oldCrypto->seal('old-process-payload',$id,$ws,'api_token');
    try{
        $pdo->prepare("UPDATE credentials SET encrypted_payload=decode(:cipher,'hex'),version=version+1 WHERE id=CAST(:id AS uuid)")
            ->execute(['id'=>$id,'cipher'=>bin2hex($oldCipher)]);
        throw new RuntimeException('Stale key ciphertext was accepted');
    }catch(PDOException $e){$ok($e->getCode()==='23514','DB trigger denies stale worker ciphertext');}
    $ok((int)$pdo->query('SELECT generation FROM vault_key_state WHERE id=1')->fetchColumn()===2,
        'key generation advanced');
    $ok((int)$pdo->query('SELECT count(*) FROM vault_key_rotation_events')->fetchColumn()===1,
        'key rotation event persisted');
    $ok((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action='vault.key.rotated'")->fetchColumn()===1,
        'key rotation audited without plaintext');
    echo "Vault rotation PostgreSQL integration: $checks PASS\n";
}catch(Throwable $error){
    $failed=true;fwrite(STDERR,'FAIL: Vault rotation PostgreSQL integration: '.$error::class.': '.$error->getMessage()."\n");
}finally{
    if($pdo instanceof PDO && $created){
        try{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('RESET search_path');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
        catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL: test schema cleanup: '.$e->getMessage()."\n");}
    }
    foreach(glob($folder.'/migrations/*')?:[] as $file)@unlink($file);
    @rmdir($folder.'/migrations');@rmdir($folder);
}
exit($failed?1:0);
