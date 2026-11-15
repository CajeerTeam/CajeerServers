#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{CollectorAccess,EndpointPolicy};
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Logging\JsonLogger;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\{Permission,Rbac};
$checks=0;
$ok=static function(bool $value,string $name)use(&$checks):void{if(!$value)throw new RuntimeException($name);$checks++;};
$reject=static function(callable $call,string $name)use($ok):void{try{$call();}catch(RuntimeException){$ok(true,$name);return;}throw new RuntimeException('Expected rejection: '.$name);};
$env=getenv('APP_KEY');$key='base64:'.base64_encode(random_bytes(32));$dir=sys_get_temp_dir().'/cajeer-security-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try{
    foreach(['connectors.vmmanager.actions.execute','system.events.read','network.configuration.read','network.configuration.backup'] as $p){Permission::assertKnown($p);$ok(in_array($p,Rbac::PERMISSIONS,true),'registered '.$p);}
    $ok(count(array_unique(Rbac::PERMISSIONS))===count(Rbac::PERMISSIONS),'unique scopes');
    $ok(CollectorAccess::executePermissions('routeros.configuration-backup','inspect')===['network.configuration.backup','network.configuration.read'],'backup requires both scopes');
    $ok(CollectorAccess::executePermissions('ssh.events','inspect')===['system.events.read'],'events require read');
    $ok(CollectorAccess::executePermissions('vmmanager.network','sync')===['inventory.network.read','inventory.network.manage'],'network sync scopes');
    $ok(CollectorAccess::executePermissions('ssh.system','inspect')===[],'unrelated collector unchanged');
    $filter=CollectorAccess::historyFilter(['system.events.read'],'collector_name');
    $ok(str_contains($filter,"IS DISTINCT FROM 'routeros.configuration-backup'")&&!str_contains($filter,'ssh.events'),'history filter uses null-safe exclusion');
    $ok(CollectorAccess::historyFilter(Rbac::PERMISSIONS,'p.collector_name')==='','administrator history complete');
    $policy=new EndpointPolicy(['0.0.0.0/0','::/0']);
    foreach(['::ffff:169.254.169.254','::a9fe:a9fe','64:ff9b::a9fe:a9fe','64:ff9b:1::a9fe:a9fe','2002:a9fe:a9fe::1','2001:0::1','169.254.169.254','fd00:ec2::254','100.100.100.200'] as $ip)$ok(!$policy->permits($ip),'reject '.$ip);
    $ok($policy->permits('::1')&&$policy->permits('10.0.0.2')&&$policy->permits('2001:db8::1'),'explicit allowlist preserved');
    $reject(fn()=>(new EndpointPolicy(['10.0.0.0/8','::/0'],fn()=>['10.0.0.2','64:ff9b::a9fe:a9fe'],true))->resolve('device.example.invalid'),'mixed DNS cannot bypass policy');
    $ok((new EndpointPolicy(['10.0.0.0/8'],fn()=>['10.0.0.2','10.0.0.3'],true))->resolve('device.example.invalid')==='10.0.0.2','approved address pinned');
    $ok(!(new EndpointPolicy([],null,true))->permits('10.0.0.2'),'production default deny');
    putenv('APP_KEY='.$key);
    $reject(fn()=>(new VaultMasterKey($key))->load(),'same key denied');
    $alias='base64:'.substr($key,7,10)."\n".substr($key,17);
    $reject(fn()=>(new VaultMasterKey($alias))->load(),'same material alternate encoding denied');
    file_put_contents($dir.'/rotation.key',$alias);chmod($dir.'/rotation.key',0600);
    $reject(fn()=>VaultMasterKey::fromPrivateFile($dir.'/rotation.key')->load(),'rotation cannot reuse APP_KEY');
    $different='base64:'.base64_encode(random_bytes(32));file_put_contents($dir.'/rotation.key',$different);chmod($dir.'/rotation.key',0600);
    $ok(strlen(VaultMasterKey::fromPrivateFile($dir.'/rotation.key')->load())===32,'independent key loads');
    link($dir.'/rotation.key',$dir.'/linked.key');$reject(fn()=>VaultMasterKey::fromPrivateFile($dir.'/rotation.key')->load(),'hardlinked key denied');unlink($dir.'/linked.key');
    symlink($dir.'/rotation.key',$dir.'/linked.key');$reject(fn()=>VaultMasterKey::fromPrivateFile($dir.'/linked.key')->load(),'symlink denied');unlink($dir.'/linked.key');
    foreach(['Authorization: Bearer example-secret-value','PVEAPIToken=example-secret-value','Cookie: session=example-secret-value','{"password":"example-secret-value with spaces"}',"-----BEGIN PRIVATE KEY-----\nexample-secret-value\n-----END PRIVATE KEY-----",'https://user:example-secret-value@example.invalid','community=example-secret-value','totp=example-secret-value'] as $text)$ok(!str_contains(JsonLogger::redactString($text),'example-secret-value'),'text redaction');
    $router=new Router();$router->get('/api/v1/example/{id}',fn()=>null);
    $ok($router->logRoute(new HttpRequest('GET','/api/v1/example/sensitive-token'))==='/api/v1/example/{id}','route path parameters not logged');
    $ok($router->logRoute(new HttpRequest('GET','/sensitive-token'))==='[unmatched]','unknown path not logged');
    echo "Security hardening smoke: $checks PASS\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL security hardening: '.$e->getMessage()."\n");exit(1);}finally{if($env===false)putenv('APP_KEY');else putenv('APP_KEY='.$env);foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
