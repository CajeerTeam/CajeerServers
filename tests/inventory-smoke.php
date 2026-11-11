#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Inventory\InfrastructureAsset;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Http\Validation\JsonInput;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Database\SqlStatementParser;

$checks=0;$ok=static function(bool $condition,string $name)use(&$checks){if(!$condition)throw new RuntimeException('FAIL: '.$name);$checks++;};
$reject=static function(int $status,callable $run,string $name)use($ok){try{$run();}catch(HttpException $e){$ok($e->status===$status,$name);return;}throw new RuntimeException('Expected '.$status.': '.$name);};
$asset=InfrastructureAsset::create(['workspace_id'=>'00000000-0000-4000-8000-000000000001','type'=>'server','name'=>'  edge-01  ','ip_addresses'=>['192.0.2.10','2001:db8::1'],'tags'=>['prod','edge'],'attributes'=>['cpu_cores'=>8]]);
$ok($asset['name']==='edge-01','name trimmed');$ok($asset['source']==='manual','manual source enforced');$ok($asset['status']==='unknown','default status');
$ok($asset['owner_user_id']===null,'default owner');$ok($asset['ip_addresses'][0]==='192.0.2.10','ip validated');
$ok(InfrastructureAsset::changes(['status'=>'online'])===['status'=>'online'],'partial patch');
$ok(InfrastructureAsset::etag('00000000-0000-4000-8000-000000000001',2)==='"00000000-0000-4000-8000-000000000001:2"','strong ETag');
foreach(['bad','invalid','',str_repeat('a',82)] as $type){$reject(422,fn()=>InfrastructureAsset::create(['type'=>$type,'name'=>'node']),'reject unknown type');}
foreach([['name'=>''],['source'=>'proxmox'],['type'=>'router'],['attributes'=>['api_token'=>'oops']],['attributes'=>['nested'=>['password'=>'x']]],['tags'=>['x','x']],['tags'=>['Bad Tag']],['ip_addresses'=>['999.2.3.4']],['ip_addresses'=>['192.0.2.1','192.0.2.1']],['os_family'=>'ms-dos'],['owner_user_id'=>'nope'],[]] as $bad){$reject(422,fn()=>InfrastructureAsset::changes($bad),'reject invalid mutation');}
$reject(422,fn()=>InfrastructureAsset::create(['type'=>'server','name'=>'A','source'=>'untrusted']),'reject forged source');
$body='{"workspace_id":"00000000-0000-4000-8000-000000000001","type":"server","name":"test","attributes":{}}';
$schema=['required'=>['workspace_id','type','name'],'properties'=>['workspace_id'=>['type'=>'string'],'type'=>['type'=>'string'],'name'=>['type'=>'string'],'attributes'=>['type'=>'object']]];
$data=JsonInput::parseAndValidate(new HttpRequest('POST','/assets',['Content-Type'=>'application/json'],$body),$schema);
$ok($data['attributes']===[],'empty JSON object accepted');
$reject(422,fn()=>JsonInput::parseAndValidate(new HttpRequest('POST','/assets',['Content-Type'=>'application/json'],str_replace('"attributes":{}','"attributes":[]',$body)),$schema),'JSON array not object');
$etagId='00000000-0000-4000-8000-000000000001';
$etagMethod=new ReflectionMethod(\Cajeer\Servers\Inventory\InventoryController::class,'version');
$reject(428,fn()=>$etagMethod->invoke(null,new HttpRequest('PATCH','/inventory/assets/'.$etagId),$etagId),'missing If-Match rejected');
$reject(400,fn()=>$etagMethod->invoke(null,new HttpRequest('PATCH','/inventory/assets/'.$etagId,['If-Match'=>'W/"'.$etagId.':1"']),$etagId),'weak If-Match rejected');
$reject(400,fn()=>$etagMethod->invoke(null,new HttpRequest('PATCH','/inventory/assets/'.$etagId,['If-Match'=>'"00000000-0000-4000-8000-000000000002:1"']),$etagId),'foreign ETag ID rejected');
$ok($etagMethod->invoke(null,new HttpRequest('PATCH','/inventory/assets/'.$etagId,['If-Match'=>'"'.$etagId.':15"']),$etagId)===15,'strong If-Match version accepted');
$ok(InventoryRepository::hydrate(['id'=>'00000000-0000-4000-8000-000000000001','version'=>'4','tags'=>'[]','ip_addresses'=>'[]','attributes'=>'{}'])['etag']==='"00000000-0000-4000-8000-000000000001:4"','PDO hydration');
$root=dirname(__DIR__);$migration=file_get_contents($root.'/migrations/pgsql/0010_inventory_assets_crud.sql');$statements=(new SqlStatementParser())->split($migration);
$ok(count($statements)>=9,'real migration DDL');
$ok(str_contains($migration,'CREATE TRIGGER inventory_asset_bump_version'),'version enforced by DB trigger');
$ok(str_contains($migration,'inventory.assets.create'),'permissions migrated');
foreach(['inventory.assets.read','inventory.assets.create','inventory.assets.update','inventory.assets.delete'] as $permission)$ok(in_array($permission,Rbac::PERMISSIONS,true),'RBAC scope '.$permission);
$oldEnv=getenv('APP_ENV');$oldUrl=getenv('APP_URL');putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=null;
try{
  $app=(new Application($root))->boot();
  $registered=$app->container()->get(Router::class)->routes();
  foreach(['/api/v1/inventory/assets','/api/v1/inventory/assets/{id}','/api/v1/inventory/assets/{id}/restore'] as $path)$ok(in_array($path,array_column($registered,'path'),true),'route registered '.$path);
  $ok(count($registered)===126,'126 routes total');
  foreach(['GET','POST'] as $method){
    $r=$app->handle(new HttpRequest($method,'/api/v1/inventory/assets', $method==='POST'?['Content-Type'=>'application/json']:[], $method==='POST'?$body:''));
    $ok(in_array($r->status,[401,503],true),'unauthorized '.$method);
  }
  $invalid=$app->handle(new HttpRequest('POST','/api/v1/inventory/assets',['Content-Type'=>'application/json'],'{"type":"server"}'));
  $ok($invalid->status===422,'schema denies missing required fields');
  $invalid=$app->handle(new HttpRequest('POST','/api/v1/inventory/assets',['Content-Type'=>'application/json'],'{"workspace_id":"00000000-0000-4000-8000-000000000001","type":"server","name":"A","source":"proxmox"}'));
  $ok($invalid->status===422,'schema denies source spoofing');
  echo "PASS: {$checks} inventory native smoke assertions (real PostgreSQL test separate).\n";
}finally{
  $app?->terminate();
  if($oldEnv===false)putenv('APP_ENV');else putenv('APP_ENV='.$oldEnv);
  if($oldUrl===false)putenv('APP_URL');else putenv('APP_URL='.$oldUrl);
}
