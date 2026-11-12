#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Credentials\CredentialStore;
use Cajeer\Servers\Credentials\CredentialController;
use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\Rbac;

$checks=0;
$ok=static function(bool $v,string $msg)use(&$checks):void{if(!$v)throw new RuntimeException('FAIL: '.$msg);$checks++;};
$deny=static function(callable $fn,string $msg)use($ok):void{
    try{$fn();}catch(HttpException $e){$ok(in_array($e->status,[400,404,412,422,428],true),$msg);return;}
    throw new RuntimeException('FAIL: accepted invalid '.$msg);
};
$id='a1211111-bbbb-4ddd-8abc-111111111111';
$ws='e2222222-bbbb-4ccc-8abc-222222222222';
$ok(CredentialInput::uuid(strtoupper($id))===$id,'canonical identifier');
$ok(CredentialInput::name('  vault key  ')==='vault key','name normalization');
$ok(CredentialInput::description(" use in dev ")==='use in dev','description normalization');
$ok(CredentialInput::type('generic_secret')==='generic_secret','generic secret');
$ok(CredentialInput::type('api_token')==='api_token','api token');
$ok(CredentialInput::status('disabled')==='disabled','disabled credential state');
$ok(CredentialInput::secret('very-sensitive-test-only')==='very-sensitive-test-only','bounded secret accepted');
$deny(fn()=>CredentialInput::uuid('../etc'),'path instead of UUID');
$deny(fn()=>CredentialInput::name('  '),'blank name');
$deny(fn()=>CredentialInput::name("name\nattack"),'control character in name');
$deny(fn()=>CredentialInput::name(str_repeat('N',256)),'large name');
$deny(fn()=>CredentialInput::description(str_repeat('X',1025)),'large description');
$deny(fn()=>CredentialInput::type('proxmox_token'),'unsupported profile rejected until 0.3.3');
$deny(fn()=>CredentialInput::secret(''),'empty secret');
$deny(fn()=>CredentialInput::secret(str_repeat('a',65537)),'oversized secret');
$deny(fn()=>CredentialInput::status('archived'),'cannot set archived through metadata');
$deny(fn()=>CredentialInput::patch([]),'empty patch');
$deny(fn()=>CredentialInput::patch(['workspace_id'=>$ws]),'workspace is immutable');
$deny(fn()=>CredentialInput::patch(['secret'=>'plaintext']),'secret cannot go into metadata');
$ok(CredentialInput::patch(['name'=>'updated','status'=>'disabled'])===['name'=>'updated','status'=>'disabled'],'valid metadata patch');
$ok(CredentialInput::version($id,CredentialInput::etag($id,9))===9,'strong ETag version');
$deny(fn()=>CredentialInput::version($id,null),'missing If-Match');
$deny(fn()=>CredentialInput::version($id,'W/'.CredentialInput::etag($id,9)),'weak If-Match');
$deny(fn()=>CredentialInput::version($id,'"e2222222-bbbb-4ccc-8abc-222222222222:4"'),'foreign ETag');
$key=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));
$crypto=new VaultCrypto($key);
$encrypted=$crypto->seal('sensitive-test-value',$id,$ws,'generic_secret');
$ok($crypto->open($encrypted,$id,$ws,'generic_secret')==='sensitive-test-value','real envelope roundtrip');
$ok(!str_contains($encrypted,'sensitive-test-value'),'binary encryption does not contain secret');
$parser=new SqlStatementParser();
$sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0018_encrypted_credentials_crud.sql');
$ok(count($parser->split($sql))===7,'SQL migration with trigger and grants parsed');
$ok(str_contains($sql,'credentials_vault_guard'),'SQL enforces immutable AAD context');
$ok(str_contains($sql,'vault_format=\'legacy\''),'legacy credentials remain segregated');
foreach(['vault.credentials.read','vault.credentials.create','vault.credentials.update','vault.credentials.delete'] as $permission)
    $ok(in_array($permission,Rbac::PERMISSIONS,true),'known permission '.$permission);
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application(dirname(__DIR__)))->boot();
try{
    $ok($app->container()->get(CredentialStore::class) instanceof CredentialStore,'real service registered');
    $ok($app->container()->get(CredentialController::class) instanceof CredentialController,'REST controller registered');
    $routes=$app->container()->get(Router::class)->routes();
    $ok(count($routes)===149,'registered Vault methods');
    $routesByPath=array_column($routes,'methods','path');
    foreach(['/api/v1/vault/credentials','/api/v1/vault/credentials/{id}','/api/v1/vault/credentials/{id}/secret'] as $path)
        $ok(isset($routesByPath[$path]),'Vault path '.$path);
    $get=$app->handle(new HttpRequest('GET','/api/v1/vault/credentials',[], '', ['workspace_id'=>$ws]));
    $ok(in_array($get->status,[401,503],true),'unauthenticated Vault cannot enumerate credentials');
    $ok(!str_contains($get->body,'sensitive-test-value'),'no secret in response');
    $post=$app->handle(new HttpRequest('POST','/api/v1/vault/credentials',['Content-Type'=>'application/json'],
        json_encode(['workspace_id'=>$ws,'name'=>'test','type'=>'generic_secret','secret'=>'never-log-me','extra'=>'forbidden'],JSON_THROW_ON_ERROR)));
    $ok($post->status===422,'unknown property rejected before persistence');
    $ok(!str_contains($post->body,'never-log-me'),'secret absent in validation error');
    $bad=$app->handle(new HttpRequest('POST','/api/v1/vault/credentials',['Content-Type'=>'application/json'],
        json_encode(['workspace_id'=>$ws,'name'=>'test','type'=>'generic_secret','secret'=>'never-log-me'],JSON_THROW_ON_ERROR)));
    $ok(in_array($bad->status,[401,503],true),'authorized credentials required');
    $ok(!str_contains($bad->body,'never-log-me'),'secret absent in auth error');
}finally{$app->terminate();}
echo "Vault encrypted CRUD smoke: $checks PASS\n";
