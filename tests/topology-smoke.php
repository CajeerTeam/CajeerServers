#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Inventory\RelationshipService;
use Cajeer\Servers\Inventory\RelationshipController;
use Cajeer\Servers\Inventory\TopologyService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Security\Rbac;
$n=0;
$ok=static function(bool $value,string $why)use(&$n):void{if(!$value)throw new RuntimeException('FAIL: '.$why);$n++;};
$reject=static function(int $status,callable $run,string $why)use($ok):void{
    try{$run();}catch(HttpException $e){$ok($e->status===$status,$why);return;}
    throw new RuntimeException('Expected '.$status.': '.$why);
};
$id='00000000-0000-4000-8000-000000000001';
$other='00000000-0000-4000-8000-000000000002';
foreach(RelationshipService::KINDS as $kind)$ok(RelationshipService::kind($kind)===$kind,'known kind '.$kind);
foreach(['unknown','script_exec','',null,0] as $kind)$reject(422,fn()=>RelationshipService::kind($kind),'unsupported kind');
$reject(422,fn()=>RelationshipService::uuid('nope'),'invalid uuid');
$reject(422,fn()=>RelationshipService::uuid(33),'numeric uuid');
$ok(RelationshipService::uuid(strtoupper($id))===$id,'uuid canonical case');
$method=new ReflectionMethod(RelationshipController::class,'expected');
$reject(428,fn()=>$method->invoke(null,new HttpRequest('DELETE','/x'),$id),'missing If-Match');
$reject(400,fn()=>$method->invoke(null,new HttpRequest('DELETE','/x',['If-Match'=>'W/"'.$id.':1"']),$id),'weak ETag');
$reject(400,fn()=>$method->invoke(null,new HttpRequest('DELETE','/x',['If-Match'=>'"'.$other.':1"']),$id),'foreign ETag');
$ok($method->invoke(null,new HttpRequest('DELETE','/x',['If-Match'=>'"'.$id.':2"']),$id)===2,'valid ETag');
$method=new ReflectionMethod(RelationshipService::class,'canonical');
$ok($method->invoke(null,$other,$id,'connected_to')===[$id,$other],'undirected edge normalizes');
$ok($method->invoke(null,$other,$id,'depends_on')===[$other,$id],'directed edge retains direction');
$reject(422,fn()=>$method->invoke(null,$id,$id,'hosts'),'self-edge denied');
$method=new ReflectionMethod(RelationshipController::class,'limit');
$reject(422,fn()=>$method->invoke(null,new HttpRequest('GET','/topology',[], '', ['limit'=>'500']),'limit',100,200),'limit bounded');
$reject(422,fn()=>$method->invoke(null,new HttpRequest('GET','/topology',[], '', ['limit'=>'-1']),'limit',100,200),'negative limit');
$ok($method->invoke(null,new HttpRequest('GET','/topology',[], '', ['limit'=>'200']),'limit',100,200)===200,'valid bounded limit');
$root=dirname(__DIR__);$sql=file_get_contents($root.'/migrations/pgsql/0012_infrastructure_relationships.sql');
$queries=(new SqlStatementParser())->split($sql);
$ok(count($queries)>=10,'real DDL and PL/pgSQL parsed');
foreach(['inventory_relationships','cajeer_relationship_guard','WITH RECURSIVE reachable','inventory_asset_relationship_guard','inventory.relationships.read','inventory.relationships.manage'] as $needle)$ok(str_contains($sql,$needle),'DB rule '.$needle);
foreach(['inventory.relationships.read','inventory.relationships.manage'] as $scope)$ok(in_array($scope,Rbac::PERMISSIONS,true),'scope registered '.$scope);
$oldEnv=getenv('APP_ENV');$oldUrl=getenv('APP_URL');putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application($root))->boot();
try{
    $routes=$app->container()->get(Router::class)->routes();
    $ok(count($routes)===204,'204 registered HTTP method routes');
    foreach(['/api/v1/inventory/relationships','/api/v1/inventory/relationships/{id}','/api/v1/inventory/topology'] as $route)$ok(in_array($route,array_column($routes,'path'),true),'registered '.$route);
    foreach(['/api/v1/inventory/relationships','/api/v1/inventory/topology'] as $route){
        $res=$app->handle(new HttpRequest('GET',$route,[], '', ['workspace_id'=>$id]));
        $ok(in_array($res->status,[401,503],true),'guest cannot read '.$route);
    }
    $res=$app->handle(new HttpRequest('POST','/api/v1/inventory/relationships',['Content-Type'=>'application/json'],
        '{"workspace_id":"'.$id.'","source_asset_id":"'.$id.'","target_asset_id":"'.$other.'","kind":"run.shell"}'));
    $ok($res->status===422,'disallowed relation kind rejected before execution');
    $res=$app->handle(new HttpRequest('POST','/api/v1/inventory/relationships',['Content-Type'=>'application/json'],
        '{"workspace_id":"'.$id.'","source_asset_id":"'.$id.'","target_asset_id":"'.$other.'","kind":"hosts","credential":"secret"}'));
    $ok($res->status===422,'unknown payload field rejected');
    $ok($app->container()->get(TopologyService::class) instanceof TopologyService,'topology service wired');
    echo "PASS: $n topology native checks (database integration is separate).\n";
}finally{
    $app->terminate();
    if($oldEnv===false)putenv('APP_ENV');else putenv('APP_ENV='.$oldEnv);
    if($oldUrl===false)putenv('APP_URL');else putenv('APP_URL='.$oldUrl);
}
