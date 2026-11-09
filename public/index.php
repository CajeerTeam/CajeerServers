<?php
declare(strict_types=1);

use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

$app = null;
header_remove('X-Powered-By');
try {
    $app = require dirname(__DIR__) . '/core/bootstrap.php';
    $request = HttpRequest::fromGlobals();
    $app->handle($request)->send($request->method === 'HEAD');
} catch (Throwable $error) {
    if ($app !== null) {
        $app->errors()->respondHttp($error);
    } else {
        // Never expose PHP internals if bootstrapping itself fails.
        error_log('CajeerServers: HTTP bootstrap failed (' . $error::class . ').');
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo '{"error":"Internal Server Error"}';
    }
} finally {
    $app?->terminate();
}
