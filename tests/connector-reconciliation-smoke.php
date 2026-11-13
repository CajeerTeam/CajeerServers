#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorResult,ProxmoxConnector};
use Cajeer\Servers\Inventory\{ObservationInput,TransferFormat};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
$n=0;
$ok=static function(bool $v,string $label)use(&$n):void{if(!$v)throw new RuntimeException('FAIL: '.$label);$n++;};
$reject=static function(callable $f,string $label)use($ok):void{try{$f();}catch(Throwable){$ok(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);};
$node=['type'=>'hypervisor_node','external_id'=>'node:a','fields'=>['name'=>'a','status'=>'online']];
$guest=['type'=>'virtual_machine','external_id'=>'qemu:42','parent_external_id'=>'node:a','fields'=>['name'=>'vm','status'=>'online']];
$pve=new ProxmoxConnector();$m=$pve->manifest();
$ok(!(new ConnectorResult([$node]))->validated($m)->complete,'default partial');
$ok(!(new ConnectorResult([],true))->validated($m)->complete,'empty full downgraded');
$ok((new ConnectorResult([$node,$guest],true))->validated($m)->complete,'explicit nonempty full');
$ok((new ConnectorResult([$guest]))->validated($m)->items[0]['parent_external_id']==='node:a','partial parent outside current snapshot allowed');
$reject(fn()=>(new ConnectorResult([$guest],true))->validated($m),'full must include parent');
$reject(fn()=>(new ConnectorResult([$node,$node],true))->validated($m),'duplicates rejected');
$reject(fn()=>(new ConnectorResult([array_replace($guest,['parent_external_id'=>'qemu:42'])]))->validated($m),'self-parent rejected');
$reject(fn()=>(new ConnectorResult([array_replace($node,['parent_external_id'=>'qemu:42']),$guest]))->validated($m),'cycle rejected');
$reject(fn()=>(new ConnectorResult([array_replace($guest,['password'=>'secret'])]))->validated($m),'unexpected property rejected');
$reject(fn()=>(new ConnectorResult([array_replace($guest,['fields'=>['name'=>'a','secret'=>'no']])]))->validated($m),'untrusted fields rejected');
$rawNodes=[['node'=>'a','status'=>'online'],['node'=>'b','status'=>'online']];
$rawGuest=[['node'=>'a','type'=>'qemu','vmid'=>42,'name'=>'first','status'=>'running']];
$a=ProxmoxConnector::observations($rawNodes,$rawGuest);$rawGuest[0]['node']='b';$rawGuest[0]['name']='renamed';$b=ProxmoxConnector::observations($rawNodes,$rawGuest);
$ok($a[2]['external_id']===$b[2]['external_id']&&$b[2]['parent_external_id']==='node:b'&&$b[2]['fields']['name']==='renamed','VM move and rename preserve ID');
$reject(fn()=>ProxmoxConnector::observations([['node'=>'a']],$rawGuest),'inconsistent node snapshot rejected');
$driver=new ProxmoxConnector(static fn(string $url)=>str_contains($url,'/nodes')?' {"data":[{"node":"a","status":"online"}]}':'{"data":[]}');
$registry=new ConnectorRegistry($driver);$ctx=new ConnectorContext('proxmox','pve.internal',8006,'api_token');
$ok(!$registry->execute($ctx,'sync','api','user@pve!token=secret')->complete,'successful visible PVE snapshot is not proof of full ACL visibility');
$ok(!$registry->execute($ctx,'health','api','user@pve!token=secret')->complete,'health not full');
$id='proxmox:11111111-1111-4111-8111-111111111111:qemu:42';
$ok(ObservationInput::reserved($id)&&!ObservationInput::reserved('external-server-42'),'reserved connector namespace');
$reject(fn()=>TransferFormat::parse('json',json_encode([['external_id'=>$id,'type'=>'virtual_machine','fields'=>['name'=>'a']]]),'11111111-1111-4111-8111-111111111111'),'file import cannot impersonate connector');
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application(dirname(__DIR__)))->boot();
try{foreach([['GET','resources'],['POST','resources/resolve']] as [$method,$path]){
    $response=$app->handle(new HttpRequest($method,'/api/v1/connectors/targets/11111111-1111-4111-8111-111111111111/'.$path,$method==='POST'?['Content-Type'=>'application/json']:[], $method==='POST'?json_encode(['external_id'=>'qemu:42','mode'=>'create','generation'=>1]):''));
    $ok(in_array($response->status,[401,503],true),'requires authenticated session '.$path);
}}finally{$app->terminate();}
echo "Connector reconciliation smoke: $n PASS\n";
