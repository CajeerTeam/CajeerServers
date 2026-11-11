#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Connector\ProxmoxConnector;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;

$checks=0;
$ok=static function(bool $good,string $why)use(&$checks):void{
    if(!$good)throw new RuntimeException('FAIL: '.$why);
    $checks++;
};
$fail=static function(callable $operation,string $why)use($ok):void{
    try{$operation();}catch(Throwable){$ok(true,$why);return;}
    throw new RuntimeException('FAIL: expected rejection: '.$why);
};
$secret=json_encode(['token_id'=>'svc@pve!inventory','token_secret'=>'secret-test-only-123456'],JSON_THROW_ON_ERROR);
$calls=[];
$transport=static function(string $url,string $authorization,?string $caFile)use(&$calls):string{
    $calls[]=[$url,hash('sha256',$authorization),$caFile];
    if($authorization!=='PVEAPIToken=svc@pve!inventory=secret-test-only-123456')throw new RuntimeException('Bad auth header');
    if(str_contains($url,'/api2/json/nodes'))return json_encode(['data'=>[
        ['node'=>'pve-a','status'=>'online'],['node'=>'pve-b','status'=>'offline']
    ]],JSON_THROW_ON_ERROR);
    if(str_contains($url,'/api2/json/cluster/resources?type=vm'))return json_encode(['data'=>[
        ['type'=>'qemu','vmid'=>100,'node'=>'pve-a','name'=>'Postgres','status'=>'running'],
        ['type'=>'lxc','vmid'=>200,'node'=>'pve-b','name'=>'workers','status'=>'stopped'],
    ]],JSON_THROW_ON_ERROR);
    throw new RuntimeException('Wrong read-only API path');
};
$client=new ProxmoxConnector($transport);
$assets=$client->discover('127.0.0.1',8006,'proxmox_api',$secret);
$ok(count($calls)===2,'two real Proxmox API endpoints invoked');
$ok(str_starts_with($calls[0][0],'https://127.0.0.1:8006/api2/json/nodes'),'TLS endpoint');
$ok(count($assets)===4,'node+qemu+lxc enumeration');
$ok($assets[0]['type']==='hypervisor_node' && $assets[0]['fields']['status']==='online','node mapped');
$ok($assets[1]['fields']['status']==='offline','offline node mapped');
$ok($assets[2]['type']==='virtual_machine' && $assets[2]['external_id']==='qemu:100','stable VMID identity');
$ok($assets[3]['type']==='container' && $assets[3]['fields']['status']==='offline','container mapped');
$fail(fn()=>$client->discover('http://evil',8006,'proxmox_api',$secret),'host with protocol rejected');
$fail(fn()=>$client->discover('pve',8006,'generic_secret','not-a-token'),'wrong profile rejected');
$fail(fn()=>$client->discover('pve',8006,'api_token','invalid\r\nInjected: header'),'header injection rejected');
$fail(fn()=>ProxmoxConnector::observations([], [['type'=>'qemu','vmid'=>42,'node'=>'pve-a','status'=>'running'],
    ['type'=>'qemu','vmid'=>42,'node'=>'pve-b','status'=>'stopped']]),'duplicate identity rejected');
$fail(fn()=>ProxmoxConnector::observations([], [['type'=>'qemu','vmid'=>0,'node'=>'pve-a']]),'invalid VMID rejected');
$fail(fn()=>ProxmoxConnector::observations([], [['type'=>'other','vmid'=>30,'node'=>'pve-a']]),'unknown guest type rejected');
$fail(fn()=>ProxmoxConnector::observations(array_fill(0,257,['node'=>'pve-a','status'=>'online']), []),'bounded nodes');
$fail(fn()=>ProxmoxConnector::observations([], array_fill(0,1501,['type'=>'qemu','vmid'=>10,'node'=>'pve-a'])),'bounded guests');
$invalid=new ProxmoxConnector(static fn()=>'<html>bad response</html>');
$fail(fn()=>$invalid->discover('pve',8006,'proxmox_api',$secret),'malformed server response rejected');
$badEnvelope=new ProxmoxConnector(static fn()=>'{"data":{"not":"a list"}}');
$fail(fn()=>$badEnvelope->discover('pve',8006,'proxmox_api',$secret),'non-array data rejected');
$ok(!str_contains(json_encode($assets,JSON_THROW_ON_ERROR),'secret-test-only'),'discovery has no credentials');
$ok(!str_contains(json_encode($calls,JSON_THROW_ON_ERROR),'secret-test-only'),'test transport metadata does not persist secrets');
$root=dirname(__DIR__);
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application($root))->boot();
try{
    $router=$app->container()->get(Router::class);
    $routes=$router->routes();
    foreach(['/api/v1/connectors/targets/{id}/inspect','/api/v1/connectors/targets/{id}/sync'] as $route){
        $matches=array_filter($routes,static fn(array $r)=>$r['path']===$route&&in_array('POST',$r['methods'],true));
        $ok(count($matches)===1,'real POST route '.$route);
    }
    $id='11111111-1111-4111-8111-111111111111';
    foreach(['inspect','sync'] as $operation){
        $response=$app->handle(new HttpRequest('POST','/api/v1/connectors/targets/'.$id.'/'.$operation));
        $ok(in_array($response->status,[401,503],true),'anonymous operation blocked '.$operation);
        $ok(!str_contains($response->body,'secret-test-only'),'no secret in HTTP denial');
    }
}finally{$app->terminate();}
echo "Proxmox runtime native smoke: $checks PASS. External TLS + PostgreSQL are separately validated.\n";
