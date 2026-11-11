#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Api\Controller\AdminController;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Http\Router;

putenv('APP_ENV=local');
putenv('APP_URL=http://127.0.0.1:8080');
$checks=0;
$check = static function(bool $ok,string $name) use (&$checks): void { if (!$ok) throw new RuntimeException('FAIL '.$name); $checks++; };
$app=(new Application(dirname(__DIR__)))->boot();
try {
    $router = $app->container()->get(Router::class);
    $paths = array_column($router->routes(), 'path');
    $expected=['/api/v1/admin/session','/api/v1/admin/overview','/api/v1/admin/diagnostics','/api/v1/admin/jobs','/api/v1/admin/jobs/{id}','/api/v1/admin/scheduler'];
    foreach($expected as $path) $check(in_array($path,$paths,true),$path.' registered');
    $check(count($router->routes())===135,'total real routes 135');
    foreach(['/api/v1/admin/session','/api/v1/admin/overview','/api/v1/admin/diagnostics','/api/v1/admin/jobs','/api/v1/admin/scheduler'] as $path) {
        $response=$app->handle(new HttpRequest('GET',$path));
        $check(in_array($response->status,[401,503],true),$path.' denies guest');
        $check(($response->headers['Cache-Control']??'')==='no-store',$path.' not cached');
    }
    $response=$app->handle(new HttpRequest('POST','/api/v1/admin/jobs',['Content-Type'=>'application/json'],'{"type":"runtime.diagnostics"}'));
    $check(in_array($response->status,[401,503],true),'job creation denied to guest');
    $bad=$app->handle(new HttpRequest('POST','/api/v1/admin/jobs',['Content-Type'=>'application/json'],'{"type":"run.shell"}'));
    $check($bad->status===422,'arbitrary job handler rejected before execution');
    $check(!in_array('run.shell',['runtime.diagnostics','storage.probe'],true),'only allowlisted diagnostic types');
    $result=AdminController::publicJob(['id'=>'123','type'=>'runtime.diagnostics','status'=>'completed','attempts'=>1,
      'payload'=>['secret'=>'never'], 'result'=>['password'=>'hidden'], 'last_error'=>'secret connection dsn', 'lease_token'=>'token']);
    $check(!isset($result['payload'],$result['result'],$result['last_error'],$result['lease_token']),'job API data sanitized');
    $check(($result['completed']??false)===true,'completion visible without result content');
    foreach(['admin.dashboard.read','admin.jobs.read','admin.jobs.execute','admin.scheduler.read'] as $scope) {
        $check(in_array($scope,Rbac::PERMISSIONS,true),'permission registered: '.$scope);
    }
    $sql=(string)file_get_contents(dirname(__DIR__).'/migrations/pgsql/0007_admin_ui_permissions.sql');
    foreach(['admin.dashboard.read','admin.jobs.read','admin.jobs.execute','admin.scheduler.read','administrator'] as $scope) {
        $check(str_contains($sql,$scope),'migration includes '.$scope);
    }
    $check(!in_array('admin.jobs.execute',\Cajeer\Servers\Auth\AuthenticationService::TOKEN_SCOPES,true),'API tokens cannot execute jobs');
    echo "PASS: $checks admin smoke assertions (PostgreSQL + browser E2E separate).\n";
} finally { $app->terminate(); }
