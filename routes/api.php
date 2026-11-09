<?php
declare(strict_types=1);

use Cajeer\Servers\Auth\AuthController;
use Cajeer\Servers\Security\SecurityController;
use Cajeer\Servers\Api\Controller\HealthController;
use Cajeer\Servers\Installer\InstallService;
use Cajeer\Servers\Installer\WebInstaller;
use Cajeer\Servers\Api\Controller\SystemController;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Kernel\ServiceContainer;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;

/** Register only executed, useful, safe endpoints. Unauthenticated destructive routes are NOT registered. */
return static function (Router $router, ServiceContainer $container): void {
    $webInstaller = new WebInstaller(new InstallService($container->get(Application::class)->basePath()));
    $router->get('/install', $webInstaller);
    $router->post('/install', $webInstaller);
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
        $routes->group('/auth', static function (Router $auth) use ($container): void {
            $handler = static fn (): AuthController => $container->get(AuthController::class);
            $auth->post('/login', static fn (HttpRequest $r): HttpResponse => $handler()->login($r), [
                'required' => ['email', 'password'],
                'properties' => ['email' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 255], 'password' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024], 'mfa_code' => ['type' => 'string', 'minLength' => 6, 'maxLength' => 64]],
                'additionalProperties' => false,
            ]);
            $auth->get('/me', static fn (HttpRequest $r): HttpResponse => $handler()->me($r));
            $auth->get('/csrf', static fn (HttpRequest $r): HttpResponse => $handler()->csrf($r));
            $auth->post('/logout', static fn (HttpRequest $r): HttpResponse => $handler()->logout($r));
            $auth->post('/password', static fn (HttpRequest $r): HttpResponse => $handler()->changePassword($r), [
                'required' => ['current_password', 'new_password'],
                'properties' => ['current_password' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024], 'new_password' => ['type' => 'string', 'minLength' => 14, 'maxLength' => 1024]],
                'additionalProperties' => false,
            ]);
            $auth->get('/sessions', static fn (HttpRequest $r): HttpResponse => $handler()->sessions($r));
            $auth->delete('/sessions/{id}', static fn (HttpRequest $r): HttpResponse => $handler()->revokeSession($r));
            $auth->get('/tokens', static fn (HttpRequest $r): HttpResponse => $handler()->tokens($r));
            $auth->post('/tokens', static fn (HttpRequest $r): HttpResponse => $handler()->createToken($r), [
                'required' => ['name', 'scopes', 'expires_in'],
                'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100], 'scopes' => ['type' => 'array'], 'expires_in' => ['type' => 'integer']],
                'additionalProperties' => false,
            ]);
            $auth->delete('/tokens/{id}', static fn (HttpRequest $r): HttpResponse => $handler()->revokeToken($r));
            $sec = static fn (): SecurityController => $container->get(SecurityController::class);
            $password = ['required' => ['password'], 'properties' => ['password' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024]], 'additionalProperties' => false];
            $code = ['required' => ['code'], 'properties' => ['code' => ['type' => 'string', 'minLength' => 6, 'maxLength' => 64]], 'additionalProperties' => false];
            $auth->get('/mfa', static fn (HttpRequest $r): HttpResponse => $sec()->mfaStatus($r));
            $auth->post('/mfa/enroll', static fn (HttpRequest $r): HttpResponse => $sec()->mfaEnroll($r), $password);
            $auth->post('/mfa/confirm', static fn (HttpRequest $r): HttpResponse => $sec()->mfaConfirm($r), $code);
            $auth->post('/mfa/disable', static fn (HttpRequest $r): HttpResponse => $sec()->mfaDisable($r), [
                'required' => ['password', 'code'],
                'properties' => ['password' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024], 'code' => ['type' => 'string', 'minLength' => 6, 'maxLength' => 64]],
                'additionalProperties' => false,
            ]);

        });
        $routes->group('/security', static function (Router $security) use ($container): void {
            $handler = static fn (): SecurityController => $container->get(SecurityController::class);
            $security->get('/roles', static fn (HttpRequest $r): HttpResponse => $handler()->roles($r));
            $security->post('/roles', static fn (HttpRequest $r): HttpResponse => $handler()->createRole($r), [
                'required' => ['slug', 'name', 'permissions'],
                'properties' => ['slug' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 64], 'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255], 'permissions' => ['type' => 'array']],
                'additionalProperties' => false,
            ]);
            $security->get('/users', static fn (HttpRequest $r): HttpResponse => $handler()->users($r));
            $security->post('/users', static fn (HttpRequest $r): HttpResponse => $handler()->createUser($r), [
                'required' => ['email', 'name', 'password'],
                'properties' => ['email' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 255], 'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255], 'password' => ['type' => 'string', 'minLength' => 14, 'maxLength' => 1024]],
                'additionalProperties' => false,
            ]);
            $security->patch('/users/{id}', static fn (HttpRequest $r): HttpResponse => $handler()->setActive($r), [
                'required' => ['active'], 'properties' => ['active' => ['type' => 'boolean']], 'additionalProperties' => false,
            ]);
            $security->post('/users/{id}/roles/{slug}', static fn (HttpRequest $r): HttpResponse => $handler()->grantRole($r));
            $security->delete('/users/{id}/roles/{slug}', static fn (HttpRequest $r): HttpResponse => $handler()->revokeRole($r));
            $security->get('/audit', static fn (HttpRequest $r): HttpResponse => $handler()->audit($r));
            $security->get('/webhooks', static fn (HttpRequest $r): HttpResponse => $handler()->webhooks($r));
            $security->post('/webhooks', static fn (HttpRequest $r): HttpResponse => $handler()->createWebhook($r), [
                'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]], 'additionalProperties' => false,
            ]);
            $security->delete('/webhooks/{id}', static fn (HttpRequest $r): HttpResponse => $handler()->revokeWebhook($r));
            $security->get('/webhooks/events', static fn (HttpRequest $r): HttpResponse => $handler()->webhookEvents($r));
        });
        // A verified webhook is persisted to the inbox; it is never executed as a script.
        $routes->post('/webhooks/{id}/events', static fn (HttpRequest $r): HttpResponse => $container->get(SecurityController::class)->ingestWebhook($r));
    });
};
