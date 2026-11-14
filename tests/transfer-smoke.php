#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Inventory\TransferFormat;
use Cajeer\Servers\Inventory\TransferService;
use Cajeer\Servers\Inventory\CredentialBindingService;
use Cajeer\Servers\Inventory\TransferController;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Queue\JobProcessor;
use Cajeer\Servers\Database\SqlStatementParser;
$checks=0;
$ok=static function(bool $condition,string $label)use(&$checks):void{if(!$condition)throw new RuntimeException('FAIL: '.$label);$checks++;};
$denied=static function(int $code,callable $fn,string $label)use($ok):void{
    try{$fn();}catch(HttpException $e){$ok($e->status===$code,$label);return;}
    throw new RuntimeException('Expected '.$code.': '.$label);
};
$ws='a4441790-99cd-41c8-a5d1-4da04784ef11';
$rows=[['external_id'=>'ex-1','type'=>'server','fields'=>['name'=>'srv-1','status'=>'online','tags'=>['prod']]],
    ['external_id'=>'ex-2','type'=>'router','fields'=>['name'=>'=cmd','ip_addresses'=>['192.0.2.55']]]];
$json=json_encode($rows,JSON_THROW_ON_ERROR);
$parsed=TransferFormat::parse('json',$json,$ws);
$ok($parsed[0]===$rows[0] && $parsed[1]['fields']['name']==='=cmd','strict JSON normalized rows');
$ok(count($parsed)===2,'multiple records');
$denied(422,fn()=>TransferFormat::parse('json','{"not":"array"}',$ws),'JSON object denied');
$denied(422,fn()=>TransferFormat::parse('json','[]',$ws),'empty import denied');
$denied(422,fn()=>TransferFormat::parse('json',json_encode([$rows[0],$rows[0]]),$ws),'duplicate external ID rejected');
$denied(422,fn()=>TransferFormat::parse('json',json_encode([['external_id'=>'ex','type'=>'server','fields'=>['password'=>'bad']]]),$ws),'secret field denied');
$denied(422,fn()=>TransferFormat::parse('json',json_encode([['external_id'=>'ex','type'=>'server','fields'=>['name'=>'srv'],'source'=>'proxmox']]),$ws),'provider impersonation denied');
$denied(422,fn()=>TransferFormat::parse('json',$json.' ', 'invalid'),'wrong workspace');
$denied(422,fn()=>TransferFormat::parse('json',str_repeat('X',TransferFormat::MAX_BYTES+1),$ws),'size limit enforced');
$denied(422,fn()=>TransferFormat::parse('json',json_encode(array_fill(0,201,$rows[0])),$ws),'row count limit enforced');
$csv="external_id,type,name,status,os_family,environment,ip_addresses,tags\nex-1,server,srv-1,online,debian,production,\"[\"\"192.0.2.5\"\"]\",\"[\"\"prod\"\"]\"\n";
$csvParsed=TransferFormat::parse('csv',$csv,$ws);
$ok($csvParsed[0]['fields']['tags']===['prod'],'CSV array tags decoded');
$ok($csvParsed[0]['fields']['ip_addresses']===['192.0.2.5'],'CSV array IP decoded');
$denied(422,fn()=>TransferFormat::parse('csv',"external_id,type\nx,server\n",$ws),'unknown CSV schema refused');
$denied(422,fn()=>TransferFormat::parse('csv',"external_id,type,name,status,os_family,environment,ip_addresses,tags\nx,server,hi,,,,,not-json\n",$ws),'malformed array cell refused');
$ok(TransferFormat::cell('=1+1')==="'=1+1",'formula injection escaped');
$ok(TransferFormat::cell(" \t+2") === "' \t+2",'whitespace formula injection escaped');
$ok(TransferFormat::cell('normal')==='normal','safe CSV cell unchanged');
$export=[['id'=>$ws,'external_id'=>'e1','name'=>'=2+2','type'=>'server','status'=>'online','os_family'=>null,
    'environment'=>'production','tags'=>['prod'],'ip_addresses'=>['192.0.2.1'],'attributes'=>['password'=>'NEVER_EXPORT']]];
$text=TransferFormat::export('csv',$export);
$ok(str_contains($text,"'=2+2")&&!str_contains($text,'NEVER_EXPORT'),'safe CSV export excludes metadata');
$parsedAgain=TransferFormat::parse('csv',$text,$ws);
$ok($parsedAgain[0]['fields']['name']==="'=2+2",'formula neutralization retained');
$jsonExport=TransferFormat::export('json',$export);
$ok(!str_contains($jsonExport,'NEVER_EXPORT')&&str_contains($jsonExport,'192.0.2.1'),'JSON export redacts arbitrary metadata');
$ok(str_contains($jsonExport,'"fields"'),'JSON export schema roundtrip');
$schema=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0015_inventory_transfer_credentials.sql');
$ok(count((new SqlStatementParser())->split($schema))>=8,'transfer migration parses');
foreach(['inventory_import_batches','inventory_import_results','inventory_credential_scopes','inventory_asset_credential_bindings','inventory.transfer.manage','inventory.credentials.scope'] as $piece){$ok(str_contains($schema,$piece),'migration contains '.$piece);}
foreach(['inventory.transfer.read','inventory.transfer.manage','inventory.credentials.read','inventory.credentials.manage','inventory.credentials.scope'] as $permission){$ok(in_array($permission,Rbac::PERMISSIONS,true),'RBAC permission '.$permission);}
$previousEnv=getenv('APP_ENV');$previousUrl=getenv('APP_URL');putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application(dirname(__DIR__)))->boot();
try{
    $router=$app->container()->get(Router::class);$routes=$router->routes();
    $ok(count($routes)===214,'214 executable method routes');
    foreach(['/api/v1/inventory/imports/preview','/api/v1/inventory/imports','/api/v1/inventory/imports/{id}',
        '/api/v1/inventory/imports/{id}/dispatch','/api/v1/inventory/exports','/api/v1/inventory/credentials/scopes',
        '/api/v1/inventory/assets/{id}/credential-bindings','/api/v1/inventory/assets/{id}/credential-bindings/{bindingId}'] as $path)
        $ok(in_array($path,array_column($routes,'path'),true),'registered '.$path);
    $ok($app->container()->get(TransferService::class) instanceof TransferService,'real transfer service registered');
    $ok($app->container()->get(CredentialBindingService::class) instanceof CredentialBindingService,'real credential binding service registered');
    $ok($app->container()->get(TransferController::class) instanceof TransferController,'real transfer controller registered');
    $ok($app->container()->get(JobProcessor::class)->supports('inventory.import'),'registered queue worker job');
    foreach(['GET /api/v1/inventory/exports?workspace_id='.$ws,'GET /api/v1/inventory/assets/'.$ws.'/credential-bindings'] as $case){
        [$method,$url]=explode(' ',$case,2);$parts=parse_url($url);parse_str($parts['query']??'',$query);
        $response=$app->handle(new HttpRequest($method,$parts['path'],[], '',$query));
        $ok(in_array($response->status,[401,503],true),'protected '.$parts['path']);
    }
    $payload=['workspace_id'=>$ws,'format'=>'json','content'=>$json];
    foreach(['/api/v1/inventory/imports/preview','/api/v1/inventory/imports'] as $path){
        $response=$app->handle(new HttpRequest('POST',$path,['Content-Type'=>'application/json'],json_encode($payload)));
        $ok(in_array($response->status,[401,503],true),'unauthenticated import denied');
    }
    $csvAccept=$app->handle(new HttpRequest('GET','/api/v1/inventory/exports',['Accept'=>'text/csv'],'',['workspace_id'=>$ws,'format'=>'csv']));
    $ok(in_array($csvAccept->status,[401,503],true),'CSV Accept proceeds to authentication');
    $jsonAccept=$app->handle(new HttpRequest('GET','/api/v1/inventory/exports',['Accept'=>'application/json'],'',['workspace_id'=>$ws,'format'=>'csv']));
    $ok($jsonAccept->status===406,'CSV export rejects incompatible Accept header');
    $invalid=$app->handle(new HttpRequest('POST','/api/v1/inventory/imports/preview',['Content-Type'=>'application/json'],json_encode(['workspace_id'=>$ws,'format'=>'zip','content'=>'x'])));
    $ok($invalid->status===422,'invalid transfer format rejected at request validation');
}finally{
    $app->terminate();if($previousEnv===false)putenv('APP_ENV');else putenv('APP_ENV='.$previousEnv);
    if($previousUrl===false)putenv('APP_URL');else putenv('APP_URL='.$previousUrl);
}
echo "PASS: $checks Inventory Transfer/Credential Binding native smoke assertions.\n";
