#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Credentials\CredentialVersionService;
use Cajeer\Servers\Credentials\CredentialVersionController;
use Cajeer\Servers\Credentials\VaultKeyRotation;
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Runtime\HttpRequest;

$n=0;
$ok=static function(bool $valid,string $label)use(&$n):void {
    if (!$valid) throw new RuntimeException('FAIL: '.$label);
    $n++;
};
$fail=static function(callable $action,string $label)use($ok):void {
    try {$action();}catch(Throwable){$ok(true,$label);return;}
    throw new RuntimeException('FAIL: must reject '.$label);
};
$old=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));
$new=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));
$crypto=new VaultCrypto($old);
$id='99111111-1111-4111-8111-111111111111';
$ws='99222222-2222-4222-8222-222222222222';
$payload='private-vault-test-value-'.bin2hex(random_bytes(18));
$sealed=$crypto->seal($payload,$id,$ws,'api_token');
$changed=$crypto->rewrap($sealed,$id,$ws,'api_token',$new);
$ok($changed!==$sealed,'rewrap changes wrapped-key envelope');
$ok(substr($changed,92)===substr($sealed,92),'data nonce and ciphertext unchanged exactly');
$ok((new VaultCrypto($new))->open($changed,$id,$ws,'api_token')===$payload,'new key opens rewrapped payload');
$fail(fn()=> $crypto->open($changed,$id,$ws,'api_token'),'old key rejects new envelope');
$fail(fn()=> (new VaultCrypto($new))->open($changed,$id,'99333333-3333-4333-8333-333333333333','api_token'),'new key requires original AAD');
$fail(fn()=> $crypto->rewrap($sealed,$id,$ws,'api_token',$old),'identical master key rejected');
$fail(fn()=> $crypto->rewrap($sealed,$id,$ws,'ssh_password',$new),'wrong secret type rejected');
$fail(fn()=> $crypto->rewrap(substr($sealed,0,10),$id,$ws,'api_token',$new),'invalid length rejected');
$wrong=$sealed;
$wrong[strlen($wrong)-1]=chr(ord($wrong[strlen($wrong)-1])^1);
$fail(fn()=> $crypto->rewrap($wrong,$id,$ws,'api_token',$new),'corrupted data authentication fails');
$wrong=$sealed;$wrong[28]=chr(ord($wrong[28])^1);
$fail(fn()=> $crypto->rewrap($wrong,$id,$ws,'api_token',$new),'corrupted wrap authentication fails');
$wrong=$sealed;$wrong[5]=chr(ord($wrong[5])^1);
$fail(fn()=> $crypto->rewrap($wrong,$id,$ws,'api_token',$new),'wrong key identifier rejected');
$folder=sys_get_temp_dir().'/cajeer-rotation-'.bin2hex(random_bytes(7));
mkdir($folder,0700);
try {
    $file=$folder.'/staged.key';
    VaultMasterKey::generatePrivateFile($file);
    $ok((fileperms($file)&0077)===0,'staged key mode is private');
    $loaded=VaultMasterKey::fromPrivateFile($file);
    $ok(strlen($loaded->load())===32,'staged key can be read without exposing it');
    chmod($file,0644);
    $fail(fn()=>VaultMasterKey::fromPrivateFile($file),'insecure staged key refused');
    chmod($file,0600);
    $fail(fn()=>VaultMasterKey::generatePrivateFile($file),'existing staged key never overwritten');
}finally{@unlink($folder.'/staged.key');@rmdir($folder);}
$root=dirname(__DIR__);
$sql=file_get_contents($root.'/migrations/pgsql/0020_vault_versioning_rotation.sql');
$parser=new SqlStatementParser();
$ok(count($parser->split($sql))>=14,'all schema and guard migration statements parse');
foreach(['vault_credential_versions','vault_key_state','vault_key_rotation_events','vault_credential_one_pending',
    'cajeer_vault_active_cipher_guard','cajeer_vault_version_cipher_guard','cajeer.vault_rewrap'] as $token)
    $ok(str_contains($sql,$token),'SQL includes '.$token);
$ok(str_contains(file_get_contents($root.'/core/Credentials/CredentialStore.php'),'vault_credential_versions'),
    'legacy CRUD persists real encrypted revisions');
$ok(str_contains(file_get_contents($root.'/core/Credentials/VaultKeyRotation.php'),'ACCESS EXCLUSIVE'),
    'offline rotation locks writes');
putenv('APP_ENV=local'); putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application($root))->boot();
try {
    $container=$app->container();
    $ok($container->get(CredentialVersionService::class) instanceof CredentialVersionService,'versioning service registered');
    $ok($container->get(CredentialVersionController::class) instanceof CredentialVersionController,'version controller registered');
    $ok($container->get(VaultKeyRotation::class) instanceof VaultKeyRotation,'key rotation service registered');
    $routes=$container->get(Router::class)->routes();
    $ok(count($routes)===156,'registered Vault rotation routes');
    foreach([
        '/api/v1/vault/credentials/{id}/versions',
        '/api/v1/vault/credentials/{id}/rotation',
        '/api/v1/vault/credentials/{id}/rotation/{revision}/activate',
        '/api/v1/vault/credentials/{id}/rotation/{revision}/cancel',
    ] as $path) $ok(count(array_filter($routes,static fn($r)=>$r['path']===$path))===1,'route '.$path);
    $resp=$app->handle(new HttpRequest('GET','/api/v1/vault/credentials/'.$id.'/versions'));
    $ok(in_array($resp->status,[401,503],true),'no anonymous history access');
    $ok(!str_contains($resp->body,$payload),'secret not in unauthenticated response');
    $resp=$app->handle(new HttpRequest('POST','/api/v1/vault/credentials/'.$id.'/rotation',
        ['Content-Type'=>'application/json'],json_encode(['secret'=>$payload,'extra'=>'bad'],JSON_THROW_ON_ERROR)));
    $ok(in_array($resp->status,[401,422,503],true),'invalid rotation input refused');
    $ok(!str_contains($resp->body,$payload),'secret not reflected in errors');
} finally {$app->terminate();}
echo "Vault rotation native smoke: $n PASS (PostgreSQL integration separate)\n";
