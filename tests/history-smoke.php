#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Inventory\ObservationInput;
use Cajeer\Servers\Inventory\HistoryService;
use Cajeer\Servers\Inventory\HistoryController;
use Cajeer\Servers\Inventory\InfrastructureAsset;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\Rbac;
$checks=0;
$ok=static function(bool $yes,string $label)use(&$checks):void{if(!$yes)throw new RuntimeException('FAIL: '.$label);$checks++;};
$reject=static function(int $code,callable $fn,string $label)use($ok):void{try{$fn();}catch(HttpException $e){$ok($e->status===$code,$label);return;}throw new RuntimeException('Expected HTTP '.$code.': '.$label);};
$id='1e06d25b-5fbe-488f-b027-25e092858146';
$input=['workspace_id'=>$id,'external_id'=>'inventory-poll-1','event_key'=>'sync_1','type'=>'server','fields'=>['name'=>'host','status'=>'online','tags'=>['prod']]];
$normalized=ObservationInput::normalize($input);
$ok($normalized['source']==='import','source defaults to untrusted import');
$ok($normalized['fields']['status']==='online','typed status');
$ok(strlen($normalized['payload_hash'])===64,'SHA-256 fingerprint');
$ok(ObservationInput::normalize($input)['payload_hash']===$normalized['payload_hash'],'missing observed_at idempotent across retries');
$ok(ObservationInput::normalize([...$input,'fields'=>['tags'=>['prod'],'status'=>'online','name'=>'host']])['payload_hash']===$normalized['payload_hash'],'order-independent field fingerprint');
$ok(ObservationInput::normalize([...$input,'observed_at'=>'2026-11-10T00:00:00Z'])['observed_at']==='2026-11-10T00:00:00.000000Z','UTC normalization');
$ok(ObservationInput::overrideFields(['fields'=>['status'=>'maintenance']])['status']==='maintenance','manual override validation');
foreach([
    ['fields'=>['name'=>'node'],'source'=>'proxmox'],['fields'=>['name'=>'node'],'source'=>'api'],['fields'=>['password'=>'oops']],
    ['fields'=>['attributes'=>['password'=>'x']]],['event_key'=>'bad space'],['external_id'=>''],['workspace_id'=>'invalid'],
    ['type'=>'sql'],['fields'=>[]],['fields'=>['ip_addresses'=>['not-an-ip']]],['fields'=>['tags'=>['BAD']]],
    ['observed_at'=>'not-a-date'],['observed_at'=>'2099-01-01T00:00:00Z'],['secret'=>'x'],
] as $invalid)$reject(422,fn()=>ObservationInput::normalize(array_replace($input,$invalid)),'invalid observation denied');
foreach([['fields'=>[]],['fields'=>['source'=>'proxmox']],['fields'=>['attributes'=>[]]],['fields'=>['name'=>123]],['fields'=>['status'=>'not-a-status']]] as $bad)
    $reject(422,fn()=>ObservationInput::overrideFields($bad),'invalid override denied');
$sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0014_inventory_history_provenance.sql');
$parsed=(new SqlStatementParser())->split($sql);
$ok(count($parsed)>=17,'history migration parses all PL/pgSQL function bodies');
foreach(['inventory_asset_revisions','inventory_observations','inventory_field_provenance','inventory_reconciliation_conflicts','inventory_history_capture','inventory_history_immutable','inventory.history.read','inventory.reconcile.manage','inventory.history.restore','attributes_redacted'] as $name)
    $ok(str_contains($sql,$name),'SQL contains '.$name);
foreach(['inventory.history.read','inventory.reconcile.manage','inventory.history.restore'] as $perm)$ok(in_array($perm,Rbac::PERMISSIONS,true),'registered RBAC '.$perm);
$oldEnv=getenv('APP_ENV');$oldUrl=getenv('APP_URL');putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application(dirname(__DIR__)))->boot();
try {
    $router=$app->container()->get(Router::class);$routes=$router->routes();
    $ok(count($routes)===205,'205 registered HTTP method routes');
    foreach(['/api/v1/inventory/assets/{id}/history','/api/v1/inventory/assets/{id}/provenance','/api/v1/inventory/assets/{id}/conflicts',
        '/api/v1/inventory/assets/{id}/observations','/api/v1/inventory/observations','/api/v1/inventory/assets/{id}/overrides',
        '/api/v1/inventory/assets/{id}/overrides/{field}','/api/v1/inventory/assets/{id}/restore-version'] as $path)
        $ok(in_array($path,array_column($routes,'path'),true),'route '.$path);
    $ok($app->container()->get(HistoryService::class) instanceof HistoryService,'real history DI');
    $ok($app->container()->get(HistoryController::class) instanceof HistoryController,'real history controller DI');
    foreach(['/api/v1/inventory/assets/'.$id.'/history','/api/v1/inventory/assets/'.$id.'/provenance',
        '/api/v1/inventory/assets/'.$id.'/conflicts','/api/v1/inventory/assets/'.$id.'/observations'] as $path){
        $response=$app->handle(new HttpRequest('GET',$path));$ok(in_array($response->status,[401,503],true),'unauthenticated access denied');
    }
    $response=$app->handle(new HttpRequest('POST','/api/v1/inventory/observations',['Content-Type'=>'application/json'],json_encode([...$input,'source'=>'proxmox'])));
    $ok($response->status===422,'provider impersonation rejected by JSON schema');
    $response=$app->handle(new HttpRequest('POST','/api/v1/inventory/observations',['Content-Type'=>'application/json'],json_encode($input)));
    $ok(in_array($response->status,[401,503],true),'unauthorized observation rejected');
}finally{$app->terminate();if($oldEnv===false)putenv('APP_ENV');else putenv('APP_ENV='.$oldEnv);if($oldUrl===false)putenv('APP_URL');else putenv('APP_URL='.$oldUrl);}
echo "PASS: $checks History/Provenance/Reconciliation native smoke assertions.\n";
