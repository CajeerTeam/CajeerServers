#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Workspace\WorkspaceService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Database\SqlStatementParser;

$count=0;
$assert=static function(bool $success,string $label)use(&$count):void {
    if(!$success)throw new RuntimeException('FAIL: '.$label);
    $count++;
};
$reject=static function(int $status,callable $handler,string $label)use($assert):void {
    try{$handler();}catch(HttpException $e){$assert($e->status===$status,$label);return;}
    throw new RuntimeException('Expected HTTP '.$status.': '.$label);
};
[$slug,$name]=WorkspaceService::details('production-01',' Main Workspace ');
$assert($slug==='production-01' && $name==='Main Workspace','name normalized');
foreach(['','x','UPPER','space name','space_','../evil',str_repeat('a',65)] as $bad) {
    $reject(422,static fn()=>WorkspaceService::details($bad,'My Space'),'invalid workspace slug');
}
foreach([''," \t ",str_repeat('a',256),"bad\nname"] as $bad) {
    $reject(422,static fn()=>WorkspaceService::details('example',$bad),'invalid workspace name');
}
foreach(['','invalid','00000000-0000-0000-0000-zzzzzzzzzzzz',"../etc"] as $bad) {
    $reject(404,static fn()=>WorkspaceService::uuid($bad),'invalid id rejected');
}
$assert(WorkspaceService::uuid('00000000-0000-4000-8000-000000000011')==='00000000-0000-4000-8000-000000000011','UUID accepted');
foreach(['organizations.manage','workspaces.read','workspaces.manage','workspaces.members.manage','inventory.ownership.manage'] as $scope) {
    $assert(in_array($scope,Rbac::PERMISSIONS,true),'scope registered: '.$scope);
}
$sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0009_organizations_workspaces.sql');
$statements=(new SqlStatementParser())->split($sql);
$assert(count($statements)>=18,'new migration contains executable SQL');
$assert(str_contains($sql,'UPDATE inventory_assets SET workspace_id='),'existing asset IDs retained during backfill');
$assert(str_contains($sql,'ON DELETE RESTRICT'),'workspace cannot be silently deleted while assets exist');
$assert(str_contains($sql,'CREATE TRIGGER inventory_owner_membership_check'),'owner membership trigger enforced');
$assert(str_contains($sql,'inventory_assets_workspace_source_external_uidx'),'external identity is workspace local');
$assert(str_contains($sql,"r.slug='administrator'"),'only active admins granted legacy access');
$oldEnv=getenv('APP_ENV');$oldUrl=getenv('APP_URL');putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=null;
try {
    $app=(new Application(dirname(__DIR__)))->boot();
    $paths=array_column($app->container()->get(Router::class)->routes(),'path');
    foreach(['/api/v1/organizations','/api/v1/organizations/{orgId}','/api/v1/workspaces',
      '/api/v1/workspaces/{workspaceId}','/api/v1/workspaces/{workspaceId}/members',
      '/api/v1/workspaces/{workspaceId}/members/{userId}','/api/v1/workspaces/{workspaceId}/assets',
      '/api/v1/workspaces/{workspaceId}/assets/{assetId}/owner',
      '/api/v1/workspaces/{workspaceId}/assets/{assetId}/transfer'] as $path) {
        $assert(in_array($path,$paths,true),'route registered: '.$path);
    }
    foreach(['/api/v1/organizations','/api/v1/workspaces',
      '/api/v1/workspaces/00000000-0000-4000-8000-000000000011/members',
      '/api/v1/workspaces/00000000-0000-4000-8000-000000000011/assets'] as $path) {
        $res=$app->handle(new HttpRequest('GET',$path));
        $assert($res->status===503,'uninstalled endpoint denies access');
        $assert(!str_contains($res->body,'postgres'),'internal credentials never disclosed');
    }
    $res=$app->handle(new HttpRequest('POST','/api/v1/workspaces',['Content-Type'=>'application/json'],'{"slug":"test"}'));
    $assert($res->status===422,'invalid schema rejected');
    $res=$app->handle(new HttpRequest('PUT','/api/v1/workspaces/00000000-0000-4000-8000-000000000011/members/00000000-0000-4000-8000-000000000022',['Content-Type'=>'application/json'],'{"role":"superuser"}'));
    $assert($res->status===422,'unknown workspace role rejected');
    $res=$app->handle(new HttpRequest('PATCH','/api/v1/workspaces/00000000-0000-4000-8000-000000000011/assets/00000000-0000-4000-8000-000000000033/owner',['Content-Type'=>'application/json'],'{"owner_user_id":{}}'));
    $assert($res->status===503,'uninstalled owner route denies request');
    
    $assert(in_array($app->handle(new HttpRequest('GET','/api/v1/inventory/assets'))->status,[401,503],true),'inventory CRUD denies unauthenticated requests');
    echo "PASS: $count Workspace native smoke checks (PostgreSQL integration separate).\n";
} finally {
    $app?->terminate();
    if($oldEnv===false)putenv('APP_ENV');else putenv('APP_ENV='.$oldEnv);
    if($oldUrl===false)putenv('APP_URL');else putenv('APP_URL='.$oldUrl);
}
