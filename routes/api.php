<?php
declare(strict_types=1);

use Cajeer\Servers\Auth\AuthController;
use Cajeer\Servers\Api\Controller\AdminController;
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
        $routes->get('/live', static fn (HttpRequest $r): HttpResponse => $container->get(\Cajeer\Servers\Api\Controller\OperationalHealthController::class)->live($r));
        $routes->get('/ready', static fn (HttpRequest $r): HttpResponse => $container->get(\Cajeer\Servers\Api\Controller\OperationalHealthController::class)->ready($r));
        $routes->get('/metrics', static fn (HttpRequest $r): HttpResponse => ($container->get(\Cajeer\Servers\Observability\MetricsController::class))($r));
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
        $routes->group('/vault', static function (Router $vault) use ($container): void {
            $handler=static fn (): \Cajeer\Servers\Credentials\CredentialController => $container->get(\Cajeer\Servers\Credentials\CredentialController::class);
            $vault->get('/profiles',static fn (HttpRequest $r): HttpResponse => $handler()->profiles($r));
            $vault->get('/credentials',static fn (HttpRequest $r): HttpResponse => $handler()->index($r));
            $vault->post('/credentials',static fn (HttpRequest $r): HttpResponse => $handler()->create($r),[
                'required'=>['workspace_id','name','type','secret'],
                'properties'=>[
                    'workspace_id'=>['type'=>'string','format'=>'uuid'],
                    'name'=>['type'=>'string','minLength'=>1,'maxLength'=>255],
                    'description'=>['type'=>'string','maxLength'=>1024],
                    'type'=>['type'=>'string','enum'=>\Cajeer\Servers\Credentials\CredentialProfile::types()],
                    // Profile service validates scalar or structured payload, field-level limits, and cross-field rules.
                    'secret'=>[],
                ],'additionalProperties'=>false,
            ]);
            $vault->get('/credentials/{id}',static fn (HttpRequest $r): HttpResponse => $handler()->show($r));
            $vault->patch('/credentials/{id}',static fn (HttpRequest $r): HttpResponse => $handler()->update($r),[
                'properties'=>[
                    'name'=>['type'=>'string','minLength'=>1,'maxLength'=>255],
                    'description'=>['type'=>'string','maxLength'=>1024],
                    'status'=>['type'=>'string','enum'=>['active','disabled']],
                ],'additionalProperties'=>false,
            ]);
            $vault->put('/credentials/{id}/secret',static fn (HttpRequest $r): HttpResponse => $handler()->replaceSecret($r),[
                'required'=>['secret'],
                'properties'=>['secret'=>[]],
                'additionalProperties'=>false,
            ]);
            $vault->delete('/credentials/{id}',static fn (HttpRequest $r): HttpResponse => $handler()->delete($r));
            $versions=static fn (): \Cajeer\Servers\Credentials\CredentialVersionController => $container->get(\Cajeer\Servers\Credentials\CredentialVersionController::class);
            $vault->get('/credentials/{id}/versions',static fn (HttpRequest $r): HttpResponse => $versions()->index($r));
            $vault->post('/credentials/{id}/rotation',static fn (HttpRequest $r): HttpResponse => $versions()->stage($r),[
                'required'=>['secret'],'properties'=>['secret'=>[]],'additionalProperties'=>false,
            ]);
            $vault->post('/credentials/{id}/rotation/{revision}/activate',static fn (HttpRequest $r): HttpResponse => $versions()->activate($r),[
                'required'=>['confirmed'],'properties'=>['confirmed'=>['type'=>'boolean']],'additionalProperties'=>false,
            ]);
            $vault->post('/credentials/{id}/rotation/{revision}/cancel',static fn (HttpRequest $r): HttpResponse => $versions()->cancel($r));
            // Only grants one short-lived, single-use internal permission. NEVER returns secrets.
            $vault->post('/credentials/{id}/authorize-use',
                static fn (HttpRequest $r): HttpResponse => $container->get(\Cajeer\Servers\Credentials\SecretAccessController::class)->authorize($r), [
                    'required'=>['asset_id','purpose','password','totp'],
                    'properties'=>[
                        'asset_id'=>['type'=>'string','format'=>'uuid'],
                        'purpose'=>['type'=>'string','minLength'=>1,'maxLength'=>64],
                        'password'=>['type'=>'string','minLength'=>1,'maxLength'=>1024],
                        'totp'=>['type'=>'string','minLength'=>6,'maxLength'=>6,'pattern'=>'^[0-9]{6}$'],
                    ],'additionalProperties'=>false,
                ]);

        });
        $routes->group('/admin', static function (Router $admin) use ($container): void {
            $controller = static fn (): AdminController => $container->get(AdminController::class);
            $admin->get('/session', static fn (HttpRequest $r): HttpResponse => $controller()->session($r));
            $admin->get('/overview', static fn (HttpRequest $r): HttpResponse => $controller()->overview($r));
            $admin->get('/diagnostics', static fn (HttpRequest $r): HttpResponse => $controller()->diagnostics($r));
            $admin->get('/cmdb-integrity', static fn (HttpRequest $r): HttpResponse => $controller()->cmdbIntegrity($r));
            $admin->get('/jobs', static fn (HttpRequest $r): HttpResponse => $controller()->jobs($r));
            $admin->get('/jobs/{id}', static fn (HttpRequest $r): HttpResponse => $controller()->job($r));
            $admin->post('/jobs', static fn (HttpRequest $r): HttpResponse => $controller()->dispatch($r), [
                'required' => ['type'],
                'properties' => ['type' => ['type' => 'string', 'enum' => ['runtime.diagnostics','storage.probe']]],
                'additionalProperties' => false,
            ]);
            $admin->get('/scheduler', static fn (HttpRequest $r): HttpResponse => $controller()->scheduler($r));
        });
        $routes->group('/organizations', static function (Router $org) use ($container): void {
            $controller = static fn (): \Cajeer\Servers\Workspace\WorkspaceController => $container->get(\Cajeer\Servers\Workspace\WorkspaceController::class);
            $nameSchema = ['required'=>['name'],'properties'=>['name'=>['type'=>'string','minLength'=>1,'maxLength'=>255]],'additionalProperties'=>false];
            $org->get('/', static fn (HttpRequest $r): HttpResponse => $controller()->organizations($r));
            $org->post('/', static fn (HttpRequest $r): HttpResponse => $controller()->createOrganization($r), [
                'required'=>['slug','name'], 'properties'=>['slug'=>['type'=>'string','minLength'=>3,'maxLength'=>64], 'name'=>['type'=>'string','minLength'=>1,'maxLength'=>255]],'additionalProperties'=>false,
            ]);
            $org->patch('/{orgId}', static fn (HttpRequest $r): HttpResponse => $controller()->renameOrganization($r), $nameSchema);
        });
        $routes->group('/workspaces', static function (Router $ws) use ($container): void {
            $controller = static fn (): \Cajeer\Servers\Workspace\WorkspaceController => $container->get(\Cajeer\Servers\Workspace\WorkspaceController::class);
            $nameSchema = ['required'=>['name'],'properties'=>['name'=>['type'=>'string','minLength'=>1,'maxLength'=>255]],'additionalProperties'=>false];
            $ws->get('/', static fn (HttpRequest $r): HttpResponse => $controller()->workspaces($r));
            $ws->post('/', static fn (HttpRequest $r): HttpResponse => $controller()->createWorkspace($r), [
                'required'=>['organization_id','slug','name'], 'properties'=>[
                    'organization_id'=>['type'=>'string','format'=>'uuid'],'slug'=>['type'=>'string','minLength'=>3,'maxLength'=>64],
                    'name'=>['type'=>'string','minLength'=>1,'maxLength'=>255]],'additionalProperties'=>false,
            ]);
            $ws->patch('/{workspaceId}', static fn (HttpRequest $r): HttpResponse => $controller()->renameWorkspace($r), $nameSchema);
            $ws->get('/{workspaceId}/members', static fn (HttpRequest $r): HttpResponse => $controller()->members($r));
            $ws->put('/{workspaceId}/members/{userId}', static fn (HttpRequest $r): HttpResponse => $controller()->setMember($r), [
                'required'=>['role'],'properties'=>['role'=>['type'=>'string','enum'=>['owner','manager','viewer']]],'additionalProperties'=>false,
            ]);
            $ws->delete('/{workspaceId}/members/{userId}', static fn (HttpRequest $r): HttpResponse => $controller()->removeMember($r));
            $ws->get('/{workspaceId}/assets', static fn (HttpRequest $r): HttpResponse => $controller()->assets($r));
            $ws->patch('/{workspaceId}/assets/{assetId}/owner', static fn (HttpRequest $r): HttpResponse => $controller()->assignOwner($r), [
                'required'=>['owner_user_id'], 'properties'=>['owner_user_id'=>[]], 'additionalProperties'=>false,
            ]);
            $ws->post('/{workspaceId}/assets/{assetId}/transfer', static fn (HttpRequest $r): HttpResponse => $controller()->transferAsset($r), [
                'required'=>['destination_workspace_id'],'properties'=>['destination_workspace_id'=>['type'=>'string','format'=>'uuid']],'additionalProperties'=>false,
            ]);
        });
        $routes->group('/inventory', static function (Router $inventory) use ($container): void {
            $controller = static fn (): \Cajeer\Servers\Inventory\InventoryController => $container->get(\Cajeer\Servers\Inventory\InventoryController::class);
            $createFields = [
                'workspace_id'=>['type'=>'string','format'=>'uuid'],
                'type'=>['type'=>'string','enum'=>\Cajeer\Servers\Inventory\InfrastructureAsset::TYPES],
                'name'=>['type'=>'string','minLength'=>1,'maxLength'=>255],
                'status'=>['type'=>'string','enum'=>\Cajeer\Servers\Inventory\InfrastructureAsset::STATUSES],
                'os_family'=>[], 'owner_user_id'=>[], 'tags'=>['type'=>'array'],
                'ip_addresses'=>['type'=>'array'], 'attributes'=>['type'=>'object'], 'environment'=>['type'=>'string','maxLength'=>64],
            ];
            $patchFields = $createFields;
            unset($patchFields['workspace_id'], $patchFields['type']);
            $inventory->get('/assets', static fn (HttpRequest $r): HttpResponse => $controller()->index($r));
            $inventory->post('/assets', static fn (HttpRequest $r): HttpResponse => $controller()->create($r), [
                'required'=>['workspace_id','type','name'], 'properties'=>$createFields,'additionalProperties'=>false,
            ]);
            $inventory->get('/assets/{id}', static fn (HttpRequest $r): HttpResponse => $controller()->show($r));
            $inventory->patch('/assets/{id}', static fn (HttpRequest $r): HttpResponse => $controller()->update($r), [
                'properties'=>$patchFields,'additionalProperties'=>false,
            ]);
            $inventory->delete('/assets/{id}', static fn (HttpRequest $r): HttpResponse => $controller()->delete($r));
            $inventory->post('/assets/{id}/restore', static fn (HttpRequest $r): HttpResponse => $controller()->restore($r));
        });
        // 0.2.7: append-only revision history, provenance and workspace-scoped reconciliation.
        $routes->group('/inventory',static function(Router $history)use($container):void{
            $handler=static fn():\Cajeer\Servers\Inventory\HistoryController => $container->get(\Cajeer\Servers\Inventory\HistoryController::class);
            $history->get('/assets/{id}/history',static fn(HttpRequest $r):HttpResponse=>$handler()->revisions($r));
            $history->get('/assets/{id}/provenance',static fn(HttpRequest $r):HttpResponse=>$handler()->provenance($r));
            $history->get('/assets/{id}/conflicts',static fn(HttpRequest $r):HttpResponse=>$handler()->conflicts($r));
            $history->get('/assets/{id}/observations',static fn(HttpRequest $r):HttpResponse=>$handler()->observations($r));
            $history->post('/observations',static fn(HttpRequest $r):HttpResponse=>$handler()->receive($r),[
                'required'=>['workspace_id','external_id','event_key','type','fields'],
                'properties'=>['workspace_id'=>['type'=>'string','format'=>'uuid'],'source'=>['type'=>'string','enum'=>['import']],
                    'external_id'=>['type'=>'string','minLength'=>1,'maxLength'=>255],
                    'event_key'=>['type'=>'string','minLength'=>1,'maxLength'=>120],
                    'type'=>['type'=>'string','enum'=>\Cajeer\Servers\Inventory\InfrastructureAsset::TYPES],
                    'fields'=>['type'=>'object'],'observed_at'=>['type'=>'string','maxLength'=>40]],'additionalProperties'=>false,
            ]);
            $history->patch('/assets/{id}/overrides',static fn(HttpRequest $r):HttpResponse=>$handler()->override($r),[
                'required'=>['fields'],'properties'=>['fields'=>['type'=>'object']],'additionalProperties'=>false,
            ]);
            $history->delete('/assets/{id}/overrides/{field}',static fn(HttpRequest $r):HttpResponse=>$handler()->clearOverride($r));
            $history->post('/assets/{id}/restore-version',static fn(HttpRequest $r):HttpResponse=>$handler()->restore($r),[
                'required'=>['revision'],'properties'=>['revision'=>['type'=>'integer']],'additionalProperties'=>false,
            ]);
        });
        // Catalog permissions are distinct from asset read/write scopes. Workspace authorization is checked in the service.
        $routes->group('/inventory', static function (Router $catalog) use ($container): void {
            $controller=static fn (): \Cajeer\Servers\Inventory\CatalogController => $container->get(\Cajeer\Servers\Inventory\CatalogController::class);
            $create=['workspace_id'=>['type'=>'string','format'=>'uuid'],'slug'=>['type'=>'string','maxLength'=>64],
                'name'=>['type'=>'string','maxLength'=>128],'description'=>['type'=>'string','maxLength'=>1024]];
            $patch=['name'=>['type'=>'string','maxLength'=>128],'description'=>['type'=>'string','maxLength'=>1024]];
            foreach (['tags','groups'] as $kind) {
                $catalog->get('/'.$kind, static fn(HttpRequest $r):HttpResponse => $controller()->index($r,$kind));
                $catalog->post('/'.$kind, static fn(HttpRequest $r):HttpResponse => $controller()->create($r,$kind),
                    ['required'=>['workspace_id','slug','name'],'properties'=>$create,'additionalProperties'=>false]);
                $catalog->get('/'.$kind.'/{id}', static fn(HttpRequest $r):HttpResponse => $controller()->show($r,$kind));
                $catalog->patch('/'.$kind.'/{id}', static fn(HttpRequest $r):HttpResponse => $controller()->update($r,$kind),
                    ['properties'=>$patch,'additionalProperties'=>false]);
                $catalog->delete('/'.$kind.'/{id}', static fn(HttpRequest $r):HttpResponse => $controller()->delete($r,$kind));
            }
            $catalog->get('/groups/{id}/assets',static fn(HttpRequest $r):HttpResponse => $controller()->assets($r));
            $catalog->post('/groups/{id}/assets/{assetId}',static fn(HttpRequest $r):HttpResponse => $controller()->member($r,true));
            $catalog->delete('/groups/{id}/assets/{assetId}',static fn(HttpRequest $r):HttpResponse => $controller()->member($r,false));
        });
        // 0.2.8: real bounded CSV/JSON import/export and credential references (never secret bytes).
        $routes->group('/inventory', static function (Router $transfer) use ($container): void {
            $controller=static fn (): \Cajeer\Servers\Inventory\TransferController => $container->get(\Cajeer\Servers\Inventory\TransferController::class);
            $body=['required'=>['workspace_id','format','content'],
                'properties'=>['workspace_id'=>['type'=>'string','format'=>'uuid'],
                    'format'=>['type'=>'string','enum'=>['csv','json']],
                    'content'=>['type'=>'string','minLength'=>1,'maxLength'=>524288]],'additionalProperties'=>false];
            $transfer->post('/imports/preview',static fn(HttpRequest $r):HttpResponse=>$controller()->preview($r),$body);
            $transfer->post('/imports',static fn(HttpRequest $r):HttpResponse=>$controller()->submit($r),$body);
            $transfer->get('/imports/{id}',static fn(HttpRequest $r):HttpResponse=>$controller()->batch($r));
            $transfer->post('/imports/{id}/dispatch',static fn(HttpRequest $r):HttpResponse=>$controller()->dispatch($r));
            $transfer->get('/exports',static fn(HttpRequest $r):HttpResponse=>$controller()->export($r));
            $transfer->post('/credentials/scopes',static fn(HttpRequest $r):HttpResponse=>$controller()->scope($r),[
                'required'=>['workspace_id','credential_id'],
                'properties'=>['workspace_id'=>['type'=>'string','format'=>'uuid'],'credential_id'=>['type'=>'string','format'=>'uuid']],
                'additionalProperties'=>false,
            ]);
            $transfer->get('/assets/{id}/credential-bindings',static fn(HttpRequest $r):HttpResponse=>$controller()->bindings($r));
            $transfer->post('/assets/{id}/credential-bindings',static fn(HttpRequest $r):HttpResponse=>$controller()->attach($r),[
                'required'=>['credential_id','purpose'],
                'properties'=>['credential_id'=>['type'=>'string','format'=>'uuid'],'purpose'=>['type'=>'string','maxLength'=>64]],
                'additionalProperties'=>false,
            ]);
            $transfer->delete('/assets/{id}/credential-bindings/{bindingId}',static fn(HttpRequest $r):HttpResponse=>$controller()->detach($r));
        });
        // Inventory topology and typed asset relationships. PostgreSQL owns integrity and cycle detection.
        $routes->group('/inventory', static function (Router $graph) use ($container): void {
            $controller=static fn (): \Cajeer\Servers\Inventory\RelationshipController => $container->get(\Cajeer\Servers\Inventory\RelationshipController::class);
            $types=\Cajeer\Servers\Inventory\RelationshipService::KINDS;
            $graph->get('/relationships',static fn(HttpRequest $r):HttpResponse => $controller()->index($r));
            $graph->post('/relationships',static fn(HttpRequest $r):HttpResponse => $controller()->create($r),[
                'required'=>['workspace_id','source_asset_id','target_asset_id','kind'],
                'properties'=>['workspace_id'=>['type'=>'string','format'=>'uuid'],
                    'source_asset_id'=>['type'=>'string','format'=>'uuid'],
                    'target_asset_id'=>['type'=>'string','format'=>'uuid'],
                    'kind'=>['type'=>'string','enum'=>$types]],'additionalProperties'=>false,
            ]);
            $graph->get('/relationships/{id}',static fn(HttpRequest $r):HttpResponse => $controller()->show($r));
            $graph->patch('/relationships/{id}',static fn(HttpRequest $r):HttpResponse => $controller()->update($r),[
                'required'=>['kind'],'properties'=>['kind'=>['type'=>'string','enum'=>$types]],'additionalProperties'=>false,
            ]);
            $graph->delete('/relationships/{id}',static fn(HttpRequest $r):HttpResponse => $controller()->delete($r));
            $graph->get('/topology',static fn(HttpRequest $r):HttpResponse => $controller()->topology($r));
        });
        // IPAM uses workspace membership + global RBAC and is not exposed to bearer tokens.
        $routes->group('/ipam', static function (Router $ipam) use ($container): void {
            $controller=static fn (): \Cajeer\Servers\Inventory\IpamController => $container->get(\Cajeer\Servers\Inventory\IpamController::class);
            $schemas=[
                'networks'=>[
                    'create'=>['name'=>['type'=>'string'],'cidr'=>['type'=>'string'],'vlan_id'=>[],'description'=>['type'=>'string']],
                    'patch'=>['name'=>['type'=>'string'],'vlan_id'=>[],'description'=>['type'=>'string']],
                    'required'=>['name','cidr'], 'kind'=>'network',
                ],
                'interfaces'=>[
                    'create'=>['asset_id'=>['type'=>'string','format'=>'uuid'],'name'=>['type'=>'string'],'mac_address'=>[]],
                    'patch'=>['name'=>['type'=>'string'],'mac_address'=>[]],
                    'required'=>['asset_id','name'], 'kind'=>'interface',
                ],
                'addresses'=>[
                    'create'=>['network_id'=>['type'=>'string','format'=>'uuid'],'address'=>['type'=>'string'],'hostname'=>[],'description'=>['type'=>'string']],
                    'patch'=>['hostname'=>[],'description'=>['type'=>'string']],
                    'required'=>['network_id','address'], 'kind'=>'address',
                ],
            ];
            foreach ($schemas as $prefix=>$config) {
                $kind=$config['kind'];
                $ipam->get('/'.$prefix, static fn (HttpRequest $r): HttpResponse => $controller()->index($r,$kind));
                $ipam->post('/'.$prefix, static fn (HttpRequest $r): HttpResponse => $controller()->create($r,$kind),[
                    'required'=>$config['required'],'properties'=>$config['create'],'additionalProperties'=>false,
                ]);
                $ipam->get('/'.$prefix.'/{id}', static fn (HttpRequest $r): HttpResponse => $controller()->show($r,$kind));
                $ipam->patch('/'.$prefix.'/{id}', static fn (HttpRequest $r): HttpResponse => $controller()->update($r,$kind),[
                    'properties'=>$config['patch'],'additionalProperties'=>false,
                ]);
                $ipam->delete('/'.$prefix.'/{id}', static fn (HttpRequest $r): HttpResponse => $controller()->delete($r,$kind));
            }
            $ipam->post('/addresses/{id}/assign', static fn (HttpRequest $r): HttpResponse => $controller()->assign($r),[
                'required'=>['interface_id'], 'properties'=>['interface_id'=>['type'=>'string','format'=>'uuid']], 'additionalProperties'=>false,
            ]);
            $ipam->post('/addresses/{id}/unassign', static fn (HttpRequest $r): HttpResponse => $controller()->assign($r,true));
        });
        // A verified webhook is persisted to the inbox; it is never executed as a script.
        $routes->post('/webhooks/{id}/events', static fn (HttpRequest $r): HttpResponse => $container->get(SecurityController::class)->ingestWebhook($r));
    });
};
