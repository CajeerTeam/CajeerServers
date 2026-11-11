#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Connector\ConnectorRuntimeService;
use Cajeer\Servers\Credentials\CredentialVersionService;
use Cajeer\Servers\Credentials\VaultCrypto;
use Cajeer\Servers\Credentials\VaultMasterKey;
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;

$n=0;
$ok=static function(bool $valid,string $label)use(&$n):void{
    if (!$valid)throw new RuntimeException('Vault stable smoke: '.$label);
    $n++;
};
$root=dirname(__DIR__);
$sql=file_get_contents($root.'/migrations/pgsql/0022_vault_validated_rotation.sql');
$statements=(new SqlStatementParser())->split($sql);
$ok(count($statements)===2,'two executable PostgreSQL migration statements');
foreach (['vault_rotation_validations','credential_version','connector_version','validated_at',
    'FOREIGN KEY (credential_id, secret_version)','ON DELETE CASCADE'] as $token)
    $ok(str_contains($sql,$token),'validation proof schema: '.$token);
$old='base64:'.base64_encode(random_bytes(32));
$key=new VaultMasterKey($old);$crypto=new VaultCrypto($key);
$credential='11111111-1111-4111-8111-111111111111';
$workspace='22222222-2222-4222-8222-222222222222';
$active='{"token_id":"root@pam!test","token_secret":"active-private-test"}';
$pending='{"token_id":"root@pam!test","token_secret":"pending-private-test"}';
$activeEncrypted=$crypto->seal($active,$credential,$workspace,'proxmox_api');
$pendingEncrypted=$crypto->seal($pending,$credential,$workspace,'proxmox_api');
$ok(!hash_equals($activeEncrypted,$pendingEncrypted),'staged revision has independent ciphertext');
$ok($crypto->open($pendingEncrypted,$credential,$workspace,'proxmox_api')===$pending,'pending secret decrypts only for trusted context');
$ok(!str_contains($pendingEncrypted,'pending-private-test'),'pending secret encrypted at rest');
$failed=false;
try{$crypto->open($pendingEncrypted,$credential,$workspace,'api_token');}catch(RuntimeException){$failed=true;}
$ok($failed,'wrong credential profile cannot decrypt');
$ok(str_contains(file_get_contents($root.'/core/Credentials/SecretAccessService.php'),'consumePendingForAsset'),
    'server-side pending secret consumer is wired');
$ok(str_contains(file_get_contents($root.'/core/Credentials/CredentialVersionService.php'),'connector_validation_required'),
    'activation gate uses server proof');
$ok(str_contains(file_get_contents($root.'/api/openapi.yaml'),'/connectors/targets/{id}/validate-rotation:'),
    'OpenAPI operation described');
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new Application($root))->boot();
try{
    $container=$app->container();
    $ok($container->get(ConnectorRuntimeService::class) instanceof ConnectorRuntimeService,'real runtime registered');
    $ok($container->get(CredentialVersionService::class) instanceof CredentialVersionService,'version service registered');
    $routes=$container->get(Router::class)->routes();
    $path='/api/v1/connectors/targets/{id}/validate-rotation';
    $ok(count(array_filter($routes,static fn(array $route):bool=>$route['path']===$path))===1,
        'dedicated protected POST registered');
    $uuid='11111111-1111-4111-8111-111111111111';
    $resp=$app->handle(new HttpRequest('POST','/api/v1/connectors/targets/'.$uuid.'/validate-rotation',
        ['Content-Type'=>'application/json'],json_encode(['revision'=>2])));
    $ok(in_array($resp->status,[401,503],true),'anonymous users cannot validate a pending secret');
    $ok(!str_contains($resp->body,'pending-private-test') && !str_contains($resp->body,'encrypted_payload'),
        'no secret material in anonymous response');
    $resp=$app->handle(new HttpRequest('POST','/api/v1/connectors/targets/'.$uuid.'/validate-rotation',
        ['Content-Type'=>'application/json'],json_encode(['revision'=>'2'])));
    $ok($resp->status===422,'revision must be integer');
    $resp=$app->handle(new HttpRequest('POST','/api/v1/connectors/targets/'.$uuid.'/validate-rotation',
        ['Content-Type'=>'application/json'],json_encode(['revision'=>2,'success'=>true])));
    $ok($resp->status===422,'client cannot assert successful validation');
}finally{$app->terminate();}
echo "Vault stable integration native smoke: $n PASS; live PostgreSQL proof tests separate.\n";
