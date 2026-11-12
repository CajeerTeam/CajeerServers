#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Credentials\SecretAccessService;
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Security\Rbac;

$n=0;
$ok=static function (bool $valid,string $description) use (&$n):void {
    if (!$valid) throw new RuntimeException('FAIL: '.$description);
    $n++;
};
$types=['generic_secret','api_token','proxmox_api','vmmanager_api','ssh_password','ssh_private_key','winrm','snmp_v2c','snmp_v3'];
$purposes=['custom','api','ssh','winrm','snmp'];
$expected=['custom','api','api','api','ssh','ssh','winrm','snmp','snmp'];
foreach($types as $i=>$type) {
    foreach($purposes as $purpose) $ok(SecretAccessService::allowsPurpose($type,$purpose)===($expected[$i]===$purpose),$type.' purpose '.$purpose);
}
foreach(['','sshx','any','api:password',';drop table','ssh\n'] as $purpose)
    $ok(!SecretAccessService::allowsPurpose('ssh_password',$purpose),'unknown purpose refused');
$ok(!SecretAccessService::allowsPurpose('unknown','api'),'unknown type refused');
$ok(in_array('vault.credentials.use',Rbac::PERMISSIONS,true),'separate use permission registered');
$ok(count(Rbac::PERMISSIONS)===43,'permission registry includes connector permissions');
$path=dirname(__DIR__).'/migrations/pgsql/0019_vault_secret_access.sql';
$sql=file_get_contents($path);
$ok(is_string($sql) && str_contains($sql,'CREATE TABLE vault_use_grants'),'persistent one-use grants schema present');
foreach(['session_id','actor_id','asset_id','credential_id','workspace_id','credential_version','consumed_at','expires_at'] as $field)
    $ok(str_contains($sql,$field),'grant column '.$field);
$ok(str_contains($sql,'ON CONFLICT DO NOTHING'),'admin-only grant permission seeded');
$app=null;$oldEnv=getenv('APP_ENV');$oldUrl=getenv('APP_URL');
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
try {
    $app=(new Application(dirname(__DIR__)))->boot();
    $routes=$app->container()->get(Router::class)->routes();
    $matches=array_values(array_filter($routes, static fn(array $r):bool=>($r['path']??'')==='/api/v1/vault/credentials/{id}/authorize-use'));
    $ok(count($matches)===1,'single exact use authorization endpoint');
    $ok(in_array('POST',$matches[0]['methods'],true),'POST-only authorization');
    $ok(!in_array('GET',$matches[0]['methods'],true),'no GET use');
    $ok(count($routes)===156,'protected Vault endpoints with connector bindings');
    foreach($routes as $route) {
        $url=(string)($route['path']??'');
        $ok(!str_contains($url,'/decrypt') && !str_contains($url,'/reveal') && !str_contains($url,'/consume'),'no public plaintext endpoint');
    }
    $id='9b111111-4444-4444-8444-111111111111';
    $sentinel='NEVER-EXPOSE-TEST-PASSWORD-'.bin2hex(random_bytes(12));
    $body=json_encode(['asset_id'=>'9b222222-4444-4444-8444-222222222222','purpose'=>'api',
        'password'=>$sentinel,'totp'=>'123456'],JSON_THROW_ON_ERROR);
    $res=$app->handle(new HttpRequest('POST','/api/v1/vault/credentials/'.$id.'/authorize-use',
        ['Content-Type'=>'application/json'], $body));
    $ok(in_array($res->status,[401,503],true),'no authorization before installed session');
    $ok(!str_contains($res->body,$sentinel),'password never echoed on auth failure');
    $ok(!str_contains($res->body,'123456'),'TOTP never echoed');
    $bad=$app->handle(new HttpRequest('POST','/api/v1/vault/credentials/'.$id.'/authorize-use',
        ['Content-Type'=>'application/json'],json_encode(['asset_id'=>'bad','purpose'=>'api','password'=>$sentinel,'totp'=>'broken'],JSON_THROW_ON_ERROR)));
    $ok(in_array($bad->status,[401,503,422],true),'bad payload cannot authorize');
    $ok(!str_contains($bad->body,$sentinel),'no secret echo for invalid payload');
} finally {
    $app?->terminate();
    if($oldEnv===false)putenv('APP_ENV');else putenv('APP_ENV='.$oldEnv);
    if($oldUrl===false)putenv('APP_URL');else putenv('APP_URL='.$oldUrl);
}
echo "Vault secure access native smoke: $n PASS (PostgreSQL integration separate)\n";
