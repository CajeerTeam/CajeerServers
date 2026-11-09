<?php
declare(strict_types=1);

use Cajeer\Servers\Api\Controller\HealthController;
use Cajeer\Servers\Api\Controller\SystemController;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Kernel\ServiceContainer;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;

/** Register only executed, useful, safe endpoints. Unauthenticated destructive routes are NOT registered. */
return static function (Router $router, ServiceContainer $container): void {
    $router->get('/', static function (HttpRequest $request) use ($container): HttpResponse {
        $app = $container->get(Application::class);
        $ready = $container->get(RuntimeDiagnostics::class)->run()['ok'];
        if (!$ready) {
            return HttpResponse::json(['error' => 'Service Unavailable', 'request_id' => $request->attribute('request_id')], 503);
        }
        return HttpResponse::json(['name' => $app->name(), 'version' => $app->version(), 'type' => 'api-first web control shell']);
    });
    $router->group('/api/v1', static function (Router $routes) use ($container): void {
        $routes->get('/health', static fn (HttpRequest $request): HttpResponse => ($container->get(HealthController::class))($request));
        $routes->get('/system', static fn (HttpRequest $request): HttpResponse => $container->get(SystemController::class)->index($request));
    });
};
