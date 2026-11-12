#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Native input/route/SQL checks. Real DB semantics are tested by catalog-postgres-integration. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Inventory\CatalogService;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\InfrastructureAsset;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Database\SqlStatementParser;
$checks=0;
$ok=static function(bool $yes,string $label)use(&$checks):void{if(!$yes)throw new RuntimeException('FAIL: '.$label);$checks++;};
$reject=static function(int $code,callable $fn,string $label)use($ok):void{try{$fn();}catch(HttpException $e){$ok($e->status===$code,$label);return;}throw new RuntimeException('Expected '.$code.': '.$label);};
$id='e3cc3b3e-648d-47f1-9a39-9f9364d79102';
foreach(['prod','a.b_c-d','1','critical'] as $slug)$ok(CatalogService::slug($slug)===$slug,'valid slug '.$slug);
foreach(['Prod','-invalid','bad space','',str_repeat('x',65),'../../../etc'] as $slug)$reject(422,fn()=>CatalogService::slug($slug),'reject slug '.$slug);
$ok(CatalogService::fields(['workspace_id'=>$id,'slug'=>'prod','name'=>'Production'],true)['description']==='','default description');
$reject(422,fn()=>CatalogService::fields(['workspace_id'=>$id,'slug'=>'prod'],true),'missing name');
$reject(422,fn()=>CatalogService::fields(['slug'=>'prod','name'=>'Prod','secret'=>'x'],false),'unknown property');
$reject(422,fn()=>CatalogService::fields(['name'=>' '],false),'blank name');
$reject(422,fn()=>CatalogService::fields(['name'=>'a','workspace_id'=>$id],false),'workspace mutation forbidden');
$ok(InfrastructureAsset::create(['type'=>'server','name'=>'node'])['environment']==='unassigned','default environment');
$ok(InfrastructureAsset::changes(['environment'=>'production'])['environment']==='production','custom environment');
foreach (['Prod','pro.d','-production','',str_repeat('a',65)] as $bad)$reject(422,fn()=>InfrastructureAsset::changes(['environment'=>$bad]),'bad environment');
$cursor=InventoryRepository::encodeCursor('2026-11-09 21:22:00+00',$id);
$ok(InventoryRepository::decodeCursor($cursor)===['2026-11-09 21:22:00+00',$id],'cursor roundtrip');
foreach (['garbage','{}',base64_encode('["tomorrow","x"]'),str_repeat('x',300)] as $c)$reject(422,fn()=>InventoryRepository::decodeCursor($c),'bad cursor');
foreach(['inventory.catalog.read','inventory.catalog.manage'] as $permission)$ok(in_array($permission,Rbac::PERMISSIONS,true),'new permission '.$permission);
$root=dirname(__DIR__);
$sql=file_get_contents($root.'/migrations/pgsql/0013_inventory_catalog_search.sql');
$parts=(new SqlStatementParser())->split($sql);
$ok(count($parts)>20,'SQL statements parse including PL/pgSQL triggers');
foreach(['inventory_tags','inventory_groups','inventory_asset_tags','inventory_group_assets','to_tsvector','cajeer_inventory_sync_tags','inventory.catalog.manage'] as $marker)$ok(str_contains($sql,$marker),'migration: '.$marker);
$oldEnv=getenv('APP_ENV');$oldUrl=getenv('APP_URL');putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application($root))->boot();
try{
    $routes=$app->container()->get(Router::class)->routes();
    $ok(count($routes)===161,'161 production HTTP routes');
    foreach(['/api/v1/inventory/tags','/api/v1/inventory/groups','/api/v1/inventory/groups/{id}/assets','/api/v1/inventory/groups/{id}/assets/{assetId}'] as $route)$ok(in_array($route,array_column($routes,'path'),true),'real catalog route '.$route);
    $ok($app->container()->get(CatalogService::class) instanceof CatalogService,'catalog dependency injection');
    foreach(['/api/v1/inventory/tags','/api/v1/inventory/groups'] as $path){
        $resp=$app->handle(new HttpRequest('GET',$path,[], '', ['workspace_id'=>$id]));
        $ok(in_array($resp->status,[401,503],true),'unauthenticated read blocked');
    }
    $invalid=$app->handle(new HttpRequest('POST','/api/v1/inventory/tags',['Content-Type'=>'application/json'],json_encode(['workspace_id'=>$id,'slug'=>'prod','name'=>'Prod','api_key'=>'secret'])));
    $ok($invalid->status===422,'unknown sensitive payload denied');
    $invalid=$app->handle(new HttpRequest('POST','/api/v1/inventory/groups',['Content-Type'=>'application/json'],json_encode(['workspace_id'=>$id,'slug'=>'ops'])));
    $ok($invalid->status===422,'missing display name denied');
}finally{$app->terminate();if($oldEnv===false)putenv('APP_ENV');else putenv('APP_ENV='.$oldEnv);if($oldUrl===false)putenv('APP_URL');else putenv('APP_URL='.$oldUrl);}
echo "PASS: $checks Catalog & Search native smoke assertions.\n";
