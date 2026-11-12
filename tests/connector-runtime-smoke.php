#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorInterface,ConnectorManifest,ConnectorContext,ConnectorResult,EndpointPolicy,ProxmoxConnector};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
$n=0;
$ok=static function(bool $v,string $label)use(&$n):void {if(!$v)throw new RuntimeException('FAIL: '.$label);$n++;};
$reject=static function(callable $f,?int $status=null)use($ok):void {try{$f();}catch(Throwable $e){$ok($status===null||($e instanceof HttpException&&$e->status===$status),'expected rejection');return;}throw new RuntimeException('Expected rejection');};
$calls=[];
$pve=new ProxmoxConnector(static function(string $url,string $auth)use(&$calls):string{
    $calls[]=$url;if(!str_contains($auth,'test-secret'))throw new RuntimeException('bad secret');
    return str_contains($url,'/nodes')?' {"data":[{"node":"pve1","status":"online"}]}':'{"data":[{"type":"qemu","node":"pve1","vmid":101,"status":"running"}]}';
});
$registry=new ConnectorRegistry($pve);
$ctx=new ConnectorContext('proxmox','pve.internal',8006,'proxmox_api');
$secret='{"token_id":"svc@pve!test","token_secret":"test-secret"}';
foreach(['inspect','sync','validate_rotation'] as $op){$r=$registry->execute($ctx,$op,'api',$secret);$ok(count($r->items)===2,'working '.$op);$ok($r->summary($pve->manifest())['virtual_machine']===1,'normalized summary');}
$ok(count($calls)===6,'two requests per discovery');
$ok(count($registry->execute($ctx,'health','api',$secret)->items)===1&&count($calls)===7,'health probes nodes only');
$reject(fn()=>$registry->register($pve));
$reject(fn()=>$registry->execute($ctx,'vm.start','api',$secret),422);
$reject(fn()=>$registry->execute($ctx,'inspect','ssh',$secret),403);
$reject(fn()=>$registry->require('ssh','inspect'),501);
$ok(count($calls)===7,'reject unsupported before remote calls');
// Independent test driver proves common dispatch and normalized outputs without Proxmox-specific code.
$other=new class implements ConnectorInterface {
    public function manifest():ConnectorManifest{return new ConnectorManifest('custom','custom',['generic_secret'],['inspect'],['server'],'https');}
    public function execute(ConnectorContext $c,string $o,string $s):ConnectorResult {
        if($s!=='authorized')throw new RuntimeException('DO NOT EXPOSE '.$s);
        return new ConnectorResult([['type'=>'server','external_id'=>'srv-1','fields'=>['name'=>'example','status'=>'online']]]);
    }
};
$registry->register($other);$custom=new ConnectorContext('custom','host.internal',443,'generic_secret');
$ok($registry->execute($custom,'inspect','custom','authorized')->items[0]['type']==='server','second provider executed through same registry');
$reject(fn()=>$registry->execute($custom,'sync','custom','authorized'),422);
try{$registry->execute($custom,'inspect','custom','secret-leak-marker');throw new LogicException('expected failure');}catch(HttpException $e){$ok($e->status===502&&!str_contains($e->getMessage(),'secret-leak-marker'),'sanitize driver errors');}
$bad=['type'=>'server','external_id'=>'same','fields'=>['name'=>'example']];
$reject(fn()=>(new ConnectorResult([$bad,$bad]))->validated($other->manifest()));
$reject(fn()=>(new ConnectorResult([['type'=>'server','external_id'=>'x','fields'=>['password'=>'secret']]]))->validated($other->manifest()));
$reject(fn()=>(new ConnectorResult([['type'=>'router','external_id'=>'x','fields'=>['name'=>'x']]]))->validated($other->manifest()));
$policy=new EndpointPolicy(['10.0.0.0/8','fd12::/16'],static fn()=>['10.2.3.4']);
$ok($policy->resolve('pve.internal')==='10.2.3.4','pinned permitted DNS address');
$ok($policy->permits('fd12::1')&&!$policy->permits('192.0.2.1'),'CIDR IPv6 and IPv4 enforcement');
$reject(fn()=>(new EndpointPolicy(['10.0.0.0/8'],static fn()=>['10.0.0.1','169.254.169.254']))->resolve('pve.internal'));
foreach(['127.0.0.1','::1','0:0:0:0:0:0:0:1','::ffff:127.0.0.1','169.254.169.254','fd00:ec2::254','100.100.100.200','fe80::1','224.1.1.1'] as $ip)$ok(!(new EndpointPolicy())->permits($ip),'default blocks '.$ip);
$ok(!(new EndpointPolicy([],null,true))->permits('10.0.0.1'),'production empty allowlist fails closed');
$ok((new EndpointPolicy(['127.0.0.1/32']))->permits('127.0.0.1'),'operator can explicitly allow loopback test fixture');
$reject(fn()=>new EndpointPolicy(['10.0.0.0/33']));
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application(dirname(__DIR__)))->boot();
try {
    $live=$app->container()->get(ConnectorRegistry::class);
    $ok(array_keys($live->manifests())===['proxmox'],'DI registers only working drivers');
    foreach([['GET','/api/v1/connectors/drivers'],['POST','/api/v1/connectors/targets/11111111-1111-4111-8111-111111111111/health']] as [$method,$path]){
        $response=$app->handle(new HttpRequest($method,$path));$ok(in_array($response->status,[401,503],true),'authenticated API only');
    }
}finally{$app->terminate();}
echo "Connector runtime smoke: $n PASS\n";
