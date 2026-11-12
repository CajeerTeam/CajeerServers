#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorTransportException,ProxmoxConnector,ProxmoxProfile};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Integration\ConnectorRegistry;
$n=0;$ok=static function(bool $v,string $m)use(&$n):void{if(!$v)throw new RuntimeException($m);$n++;};
$reject=static function(callable $f,string $code)use($ok):void{try{$f();}catch(HttpException $e){$ok($e->errorCode===$code,'HTTP '.$code);return;}catch(ConnectorTransportException $e){$ok($e->category===$code,'transport '.$code);return;}throw new RuntimeException('Expected '.$code);};
$p=['cluster'=>'prod','anchor_node'=>'pve-a','anchor_id'=>1,'primary_pin'=>str_repeat('a',64),'fallbacks'=>[['host'=>'pve-b.test','port'=>8006,'pin'=>str_repeat('b',64)]]];
$ctx=new ConnectorContext('proxmox','pve-a.test',8006,'proxmox_api',$p);
$ok($ctx->proxmoxProfile===$p,'profile normalized');
foreach([['primary_pin'=>'x'],['cluster'=>null],['anchor_id'=>'1'],['fallbacks'=>[['host'=>'pve-a.test','port'=>8006,'pin'=>str_repeat('a',64)]]],['fallbacks'=>[['host'=>'https://evil','port'=>8006,'pin'=>str_repeat('b',64)]]],['secret'=>'bad']] as $patch)$reject(fn()=>ProxmoxProfile::normalize(array_replace($p,$patch),'pve-a.test',8006),'validation_failed');
$rows=[['type'=>'cluster','name'=>'prod','quorate'=>1],['type'=>'node','name'=>'pve-a','nodeid'=>1,'local'=>1],['type'=>'node','name'=>'pve-b','nodeid'=>2,'local'=>0]];
$ok(ProxmoxProfile::verifyIdentity($p,$rows)['quorate']===true,'cluster verified');
$bad=$rows;$bad[0]['name']='wrong';$reject(fn()=>ProxmoxProfile::verifyIdentity($p,$bad),'connector_trust_failed');
$bad=$rows;$bad[1]['nodeid']=2;$reject(fn()=>ProxmoxProfile::verifyIdentity($p,$bad),'connector_trust_failed');
$bad=$rows;$bad[2]['local']=1;$reject(fn()=>ProxmoxProfile::verifyIdentity($p,$bad),'connector_trust_failed');
$stand=ProxmoxProfile::normalize(['anchor_node'=>'solo','primary_pin'=>str_repeat('a',64)],'solo.test',8006);
$ok(ProxmoxProfile::verifyIdentity($stand,[['type'=>'node','name'=>'solo','local'=>1]])['cluster']===null,'standalone verified');
$mode='ok';$calls=[];
$driver=new ProxmoxConnector(static function(string $url,string $auth,?string $ca)use(&$mode,&$calls,$rows):string {
    $calls[]=$url;if($auth!=='PVEAPIToken=svc@pve!read=fixture-secret')throw new RuntimeException('auth');
    $path=parse_url($url,PHP_URL_PATH);$primary=str_contains($url,'pve-a.test');
    if($primary&&$mode==='auth')throw new ConnectorTransportException('connector_auth_failed');
    if($primary&&$mode==='late'&&$path==='/api2/json/cluster/resources')throw new ConnectorTransportException('connector_unavailable');
    if($primary&&$mode==='down')throw new ConnectorTransportException('connector_transport_failed');
    $r=$rows;if(!$primary){$r[1]['local']=0;$r[2]['local']=1;}
    if($mode==='identity')$r[0]['name']='wrong';
    return json_encode(['data'=>match($path){'/api2/json/version'=>['version'=>'9.0.3'],'/api2/json/cluster/status'=>$r,'/api2/json/access/permissions'=>['/'=>['Sys.Audit'=>1,'VM.PowerMgmt'=>0]],'/api2/json/nodes'=>[['node'=>'pve-a','status'=>'online']],'/api2/json/cluster/resources'=>[['type'=>'qemu','node'=>'pve-a','vmid'=>100,'status'=>'running']],default=>throw new RuntimeException('unexpected path')}],JSON_THROW_ON_ERROR);
});
$secret='{"token_id":"svc@pve!read","token_secret":"fixture-secret"}';$reg=new ConnectorRegistry($driver);
$r=$reg->execute($ctx,'health','api',$secret);$ok($r->connection['root_privileges']===['Sys.Audit']&&$r->connection['failover']===false,'health metadata');
$mode='late';$calls=[];$r=$reg->execute($ctx,'inspect','api',$secret);$ok(count($r->items)===2&&$r->connection['failover']&&count($calls)===8,'whole read restarts after late failure');
$mode='auth';$calls=[];$reject(fn()=>$reg->execute($ctx,'health','api',$secret),'connector_auth_failed');$ok(count($calls)===1,'auth failure does not failover');
$mode='identity';$calls=[];$reject(fn()=>$reg->execute($ctx,'inspect','api',$secret),'connector_trust_failed');$ok(count($calls)===2,'identity failure does not failover');
$mode='down';$calls=[];$reject(fn()=>$driver->prepareAction($ctx,'vm.start','qemu:100',$secret),'connector_transport_failed');$ok(count($calls)===1,'action preparation never fails over');
$reject(fn()=>$reg->execute($ctx,'validate_rotation','api',$secret),'connector_transport_failed');
$ok(!str_contains(json_encode($r->connection),'fixture-secret'),'no secret in diagnostics');
$mandatory=new ProxmoxConnector(static fn()=>throw new RuntimeException('must not connect'),null,null,true);
$reject(fn()=>$mandatory->execute(new ConnectorContext('proxmox','pve-a.test',8006,'proxmox_api'),'health',$secret),'connector_trust_failed');
$mode='ok';$calls=[];$ok($driver->execute($ctx,'inspect',$secret)->complete===false,'profiled inventory is deliberately partial');

echo "Proxmox profile smoke: $n PASS\n";
