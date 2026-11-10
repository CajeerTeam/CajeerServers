#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\Network;
use Cajeer\Servers\Inventory\IpAddress;
use Cajeer\Servers\Inventory\IpamController;
use Cajeer\Servers\Inventory\IpamService;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Database\SqlStatementParser;

$n=0;
$ok=static function(bool $v,string $name)use(&$n):void {if(!$v)throw new RuntimeException('FAIL '.$name);$n++;};
$reject=static function(int $expected,callable $fn,string $name)use($ok):void {
    try{$fn();}catch(HttpException $e){$ok($e->status===$expected,$name);return;}
    throw new RuntimeException('Expected '.$expected.' for '.$name);
};
$ok(Network::cidr('192.0.2.0/24')==='192.0.2.0/24','IPv4 normalization');
$ok(Network::cidr('2001:0db8:0000::/48')==='2001:db8::/48','IPv6 normalization');
$ok(Network::cidr('0.0.0.0/0')==='0.0.0.0/0','IPv4 default route');
$ok(Network::cidr('::/0')==='::/0','IPv6 default route');
$ok(Network::host('2001:0db8::1')==='2001:db8::1','IPv6 host normalization');
$ok(Network::contains('192.0.2.0/24','192.0.2.5'),'IPv4 containment');
$ok(!Network::contains('192.0.2.0/24','192.0.3.1'),'IPv4 outside');
$ok(Network::contains('2001:db8::/64','2001:db8::42'),'IPv6 containment');
$ok(!Network::contains('2001:db8::/64','2001:db8:1::1'),'IPv6 outside');
$ok(Network::overlaps('192.0.2.0/24','192.0.2.128/25'),'nested subnets overlap');
$ok(!Network::overlaps('192.0.2.0/24','192.0.3.0/24'),'different subnets');
$ok(!Network::overlaps('192.0.2.0/24','2001:db8::/64'),'distinct IP families');
$ok(IpAddress::mac('AA-BB-CC-DD-EE-FF')==='aa:bb:cc:dd:ee:ff','MAC normalized');
$ok(IpAddress::hostname('Host.Example.NET')==='host.example.net','hostname normalized');
foreach(['192.0.2.1/24','192.0.2.256','zzzz::1','',123] as $v)$reject(422,fn()=>Network::host($v),'reject invalid IP');
foreach(['192.0.2.5/24','2001:db8::1/64','192.0.2.0/33','2001:db8::/129','localhost/24','192.0.2.0/'] as $v)$reject(422,fn()=>Network::cidr($v),'reject invalid CIDR');
foreach(['AA:BB:CC:DD:EE','AA:BB:CC:DD:EE:GG','AA-BB:CC-DD:EE-FF'] as $v)$reject(422,fn()=>IpAddress::mac($v),'reject invalid MAC');
foreach(['invalid_host','-hostname','a..b'] as $v)$reject(422,fn()=>IpAddress::hostname($v),'reject invalid hostname');
$method=new ReflectionMethod(IpamController::class,'version');
$id='00000000-0000-4000-8000-000000000001';
$reject(428,fn()=>$method->invoke(null,new HttpRequest('PATCH','/ipam/networks/'.$id),$id),'missing ETag');
$reject(400,fn()=>$method->invoke(null,new HttpRequest('PATCH','/ipam/networks/'.$id,['If-Match'=>'W/"'.$id.':1"']),$id),'weak ETag');
$reject(400,fn()=>$method->invoke(null,new HttpRequest('PATCH','/ipam/networks/'.$id,['If-Match'=>'"00000000-0000-4000-8000-000000000002:1"']),$id),'foreign ETag');
$ok($method->invoke(null,new HttpRequest('PATCH','/ipam/networks/'.$id,['If-Match'=>'"'.$id.':12"']),$id)===12,'valid ETag');
$method=new ReflectionMethod(IpamService::class,'normalize');
$reject(422,fn()=>$method->invoke(null,'network',['name'=>'x','cidr'=>'192.0.2.1/24'],true),'reject host bits');
$reject(422,fn()=>$method->invoke(null,'network',['name'=>'n','cidr'=>'192.0.2.0/24','password'=>'x'],true),'reject unexpected property');
$reject(422,fn()=>$method->invoke(null,'network',['name'=>'n','cidr'=>'192.0.2.0/24','vlan_id'=>4095],true),'reject invalid VLAN');
$reject(422,fn()=>$method->invoke(null,'address',['network_id'=>$id,'address'=>'192.0.2.1','interface_id'=>$id],true),'prevent direct assignment on reservation');
$reject(422,fn()=>$method->invoke(null,'network',['cidr'=>'192.0.2.0/24'],false),'CIDR immutable');
$ok($method->invoke(null,'network',['name'=>'LAN','cidr'=>'192.0.2.0/24'],true)['description']==='','network defaults');
$ok($method->invoke(null,'address',['network_id'=>$id,'address'=>'192.0.2.25'],true)['interface_id']===null,'reserve without assignment');
$root=dirname(__DIR__);
$sql=file_get_contents($root.'/migrations/pgsql/0011_network_inventory_ipam.sql');
$stmts=(new SqlStatementParser())->split($sql);
$ok(count($stmts)>=20,'real IPAM migration statements');
$ok(str_contains($sql,'inventory_ip_address_guard') && str_contains($sql,'inventory_network_guard'),'DB-enforced IPAM validation');
$ok(str_contains($sql,'inventory_assets_id_workspace_unique'),'composite FK for workspace isolation');
foreach(['inventory.network.read','inventory.network.manage'] as $scope) $ok(in_array($scope,Rbac::PERMISSIONS,true),'RBAC registered '.$scope);
$oldEnv=getenv('APP_ENV');$oldUrl=getenv('APP_URL');putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=null;
try {
    $app=(new Application($root))->boot();
    $routes=$app->container()->get(Router::class)->routes();
    $ok(count($routes)===114,'114 routes, including IPAM');
    foreach(['/api/v1/ipam/networks','/api/v1/ipam/interfaces','/api/v1/ipam/addresses',
        '/api/v1/ipam/addresses/{id}/assign','/api/v1/ipam/addresses/{id}/unassign'] as $route) {
        $ok(in_array($route,array_column($routes,'path'),true),'registered '.$route);
    }
    $ws='00000000-0000-4000-8000-000000000001';
    foreach(['GET','POST'] as $verb) {
        $r=new HttpRequest($verb,'/api/v1/ipam/networks', ['Content-Type'=>'application/json'],
            $verb==='POST'?'{"name":"LAN","cidr":"192.0.2.0/24"}':'', ['workspace_id'=>$ws]);
        $result=$app->handle($r);
        $ok(in_array($result->status,[401,503],true),'anonymous IPAM denied');
    }
    $result=$app->handle(new HttpRequest('POST','/api/v1/ipam/networks',['Content-Type'=>'application/json'],'{"name":"LAN","cidr":"192.0.2.0/24","password":"wrong"}',['workspace_id'=>$ws]));
    $ok($result->status===422,'schema rejects unknown credentials');
    $result=$app->handle(new HttpRequest('POST','/api/v1/ipam/addresses',['Content-Type'=>'application/json'],'{"network_id":"'.$ws.'","address":"192.0.2.25","interface_id":"'.$ws.'"}',['workspace_id'=>$ws]));
    $ok($result->status===422,'schema forbids direct assignment');
    echo "PASS: $n IPAM native smoke assertions (PostgreSQL integration separate).\n";
} finally {
    $app?->terminate();
    if($oldEnv===false)putenv('APP_ENV');else putenv('APP_ENV='.$oldEnv);
    if($oldUrl===false)putenv('APP_URL');else putenv('APP_URL='.$oldUrl);
}
