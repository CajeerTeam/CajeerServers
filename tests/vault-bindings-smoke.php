#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\ConnectorBindingService;
use Cajeer\Servers\Connector\ConnectorBindingController;
use Cajeer\Servers\Credentials\BindingPolicy;
use Cajeer\Servers\Credentials\CredentialStore;
use Cajeer\Servers\Credentials\SecretAccessService;
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Inventory\CredentialBindingService;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\Rbac;
$n=0;
$ok=static function(bool $good,string $label)use(&$n):void{if(!$good)throw new RuntimeException('FAIL: '.$label);$n++;};
$reject=static function(int $status,callable $action,string $label)use($ok):void{
    try{$action();}catch(HttpException $e){$ok($e->status===$status,$label);return;}
    throw new RuntimeException('Expected '.$status.': '.$label);
};
foreach(BindingPolicy::PROVIDERS as $provider){
    $purposes=BindingPolicy::purposesFor($provider);
    $ok($purposes!==[],'purpose registry '.$provider);
}
$valid=[
 ['proxmox','proxmox_api','api'],['proxmox','api_token','api'],
 ['vmmanager','vmmanager_api','api'],['vmmanager','api_token','api'],
 ['ssh','ssh_password','ssh'],['ssh','ssh_private_key','ssh'],
 ['winrm','winrm','winrm'],['snmp','snmp_v2c','snmp'],['snmp','snmp_v3','snmp'],
 ['prometheus','api_token','api'],['custom','generic_secret','custom'],['custom','api_token','api'],
];
foreach($valid as [$p,$type,$purpose]){
    $ok(BindingPolicy::allowsConnector($p,$type,$purpose),'allowed '.$p.':'.$type);
    $ok(SecretAccessService::allowsPurpose($type,$purpose),'internal use shares same type rules '.$type);
}
foreach(BindingPolicy::PROVIDERS as $p)foreach(['api_token','proxmox_api','vmmanager_api','ssh_password','ssh_private_key','winrm','snmp_v2c','snmp_v3','generic_secret'] as $type){
    $good=false;foreach($valid as $case){if($case[0]===$p && $case[1]===$type)$good=true;}
    $matched=false;foreach(BindingPolicy::purposesFor($p) as $purpose) if(BindingPolicy::allowsConnector($p,$type,$purpose))$matched=true;
    $ok($matched===$good,'type allowlist '.$p.':'.$type);
}
foreach(['ssh','api','winrm','snmp','custom'] as $purpose)$ok(BindingPolicy::purpose($purpose)===$purpose,'allowed purpose '.$purpose);
foreach(['bad','proxmox','',null,'ssh\n'] as $purpose)$reject(422,fn()=>BindingPolicy::purpose($purpose),'bad purpose');
foreach(['',null,'windows','Proxmox'] as $provider)$reject(422,fn()=>BindingPolicy::provider($provider),'bad provider');
foreach(['127.0.0.1','2001:db8::1','example.org','api.internal.local'] as $host)$ok(ConnectorBindingService::validateHost($host)!=='','valid host '.$host);
foreach(['https://example.org','user@example.org','hostname/path','999.999.999.999','host:123','host\nHeader:bad','bad host','',str_repeat('x',254)] as $host)$reject(422,fn()=>ConnectorBindingService::validateHost($host),'invalid host');
foreach([1,22,443,65535] as $port)$ok(ConnectorBindingService::validatePort($port)===$port,'port '.$port);
foreach([0,65536,'443',null,-1] as $port)$reject(422,fn()=>ConnectorBindingService::validatePort($port),'bad port');
foreach(['Proxmox cluster','SSH node','Пример'] as $name)$ok(ConnectorBindingService::validateName($name)!=='','safe label');
foreach(['',"evil\nheader",str_repeat('x',121)] as $name)$reject(422,fn()=>ConnectorBindingService::validateName($name),'unsafe name');
$root=dirname(__DIR__);
$sql=file_get_contents($root.'/migrations/pgsql/0021_vault_connector_bindings.sql');
$instructions=(new SqlStatementParser())->split($sql);
$ok(count($instructions)>=12,'real PostgreSQL migration with triggers parses');
foreach(['connector_targets','connector_credential_bindings','cajeer_credential_binding_guard',
 'connector_binding_guard','inventory_binding_guard','connector_target_asset_guard',
 'REFERENCES inventory_credential_scopes','connectors.credentials.manage'] as $needle)
    $ok(str_contains($sql,$needle),'schema includes '.$needle);
foreach(['connectors.targets.read','connectors.targets.manage','connectors.credentials.read','connectors.credentials.manage'] as $scope)
    $ok(in_array($scope,Rbac::PERMISSIONS,true),'RBAC permission '.$scope);
$spec=file_get_contents($root.'/api/openapi.yaml');
$ok(str_contains($spec,'  /connectors/targets/{id}/sync:') && !preg_match('~^/connectors/targets~m',$spec),
    'OpenAPI connector operations belong inside paths, not at root');
$ok(str_contains(file_get_contents($root.'/core/Credentials/CredentialStore.php'),'connector_credential_bindings'),
    'Vault archive checks active connector bindings');
$ok(str_contains(file_get_contents($root.'/core/Inventory/CredentialBindingService.php'),'BindingPolicy::allows'),
    'Inventory binding enforces typed purpose');
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application($root))->boot();
try {
    $router=$app->container()->get(Router::class);$routes=$router->routes();
    $ok(count($routes)===187,'new protected routes are registered');
    $ok($app->container()->get(ConnectorBindingService::class) instanceof ConnectorBindingService,'connector service registered');
    $ok($app->container()->get(ConnectorBindingController::class) instanceof ConnectorBindingController,'connector controller registered');
    $ok($app->container()->get(CredentialBindingService::class) instanceof CredentialBindingService,'inventory binding service registered');
    foreach(['GET /api/v1/connectors/targets','POST /api/v1/connectors/targets',
        'GET /api/v1/connectors/targets/{id}','PATCH /api/v1/connectors/targets/{id}',
        'DELETE /api/v1/connectors/targets/{id}','GET /api/v1/connectors/targets/{id}/credential-bindings',
        'POST /api/v1/connectors/targets/{id}/credential-bindings',
        'DELETE /api/v1/connectors/targets/{id}/credential-bindings/{bindingId}',
        'GET /api/v1/inventory/credentials/scopes',
        'POST /api/v1/connectors/targets/{id}/inspect',
        'POST /api/v1/connectors/targets/{id}/validate-rotation',
        'POST /api/v1/connectors/targets/{id}/sync'] as $route){
        [$method,$path]=explode(' ',$route,2);
        $ok(count(array_filter($routes,static fn($r)=>$r['path']===$path&&in_array($method,$r['methods'],true)))===1,'route '.$route);
    }
    foreach(['/api/v1/connectors/targets','/api/v1/connectors/targets/11111111-1111-4111-8111-111111111111/credential-bindings','/api/v1/inventory/credentials/scopes'] as $path){
        $response=$app->handle(new HttpRequest('GET',$path));
        $ok(in_array($response->status,[401,503],true),'anonymous GET blocked '.$path);
        $ok(!str_contains($response->body,'encrypted_payload'),'no ciphertext in error');
    }
    $json=json_encode(['workspace_id'=>'11111111-1111-4111-8111-111111111111','provider'=>'ssh','name'=>'test','endpoint_host'=>'127.0.0.1','endpoint_port'=>22]);
    $response=$app->handle(new HttpRequest('POST','/api/v1/connectors/targets',['Content-Type'=>'application/json'],$json));
    $ok(in_array($response->status,[401,503],true),'anonymous connector create blocked');
    $response=$app->handle(new HttpRequest('POST','/api/v1/connectors/targets',['Content-Type'=>'application/json'],json_encode(['workspace_id'=>'bad'])));
    $ok($response->status===422,'invalid payload fails at HTTP validator');
}finally{$app->terminate();}
echo "Vault connector bindings native smoke: $n PASS. PostgreSQL integration separate.\n";
