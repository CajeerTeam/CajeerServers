#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Http\HttpKernel;
use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Http\Middleware\MiddlewareInterface;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

$count = 0;
$check = static function (bool $ok, string $name) use (&$count): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    $count++;
    echo "[OK] $name\n";
};

// Verify HTTP behavior with a valid local runtime; production requires an installed APP_KEY.
putenv('APP_ENV=local');
putenv('APP_URL=http://127.0.0.1:8080');
$app = (new Application(dirname(__DIR__)))->boot();
try {
    $router = $app->container()->get(Router::class);
    $kernel = $app->container()->get(HttpKernel::class);
    $check(count($router->routes()) === 42, 'real registered routes only');

    foreach (['/', '/api/v1/health', '/api/v1/system'] as $path) {
        $response = $app->handle(new HttpRequest('GET', $path));
        $check($response->status === 200, "GET $path returns 200");
        $check((bool) json_decode($response->body, true), "GET $path returns data");
        $check((bool) preg_match('/^[0-9a-f]{24}$/D', $response->headers['X-Request-Id'] ?? ''), 'valid request ID');
        $check(($response->headers['X-Frame-Options'] ?? '') === 'DENY', 'frame protection');
        $check(!isset($response->headers['Access-Control-Allow-Origin']), 'no permissive CORS default');
        $check(($response->headers['Cache-Control'] ?? '') === 'no-store', 'response not cached');
    }
    $check(json_decode($app->handle(new HttpRequest('GET', '/api/v1/system'))->body, true)['version'] === $app->version(), 'live version endpoint');
    $check(json_decode($app->handle(new HttpRequest('GET', '/api/v1/health'))->body, true)['scope'] === 'runtime', 'readiness scope accurately labeled');

    foreach (['/api/v1/actions','/api/v1/inventory/assets','/api/v1/connectors','/api/v1/webhooks/proxmox','/api/v1/scripts'] as $path) {
        $check($app->handle(new HttpRequest('GET', $path))->status === 404, "unfinished $path remains unavailable");
    }
    $post = $app->handle(new HttpRequest('POST', '/'));
    $check($post->status === 405 && $post->headers['Allow'] === 'GET, HEAD', '405 with accurate Allow');
    $check(str_contains($post->headers['Content-Type'], 'problem+json'), 'RFC 9457 error content-type');
    $check(json_decode($post->body, true)['code'] === 'method_not_allowed', 'structured method error');
    $check($app->handle(new HttpRequest('HEAD', '/api/v1/system'))->status === 200, 'HEAD falls back to GET');
    $options = $app->handle(new HttpRequest('OPTIONS', '/api/v1/health'));
    $check($options->status === 204 && str_contains($options->headers['Allow'], 'OPTIONS'), 'automatic OPTIONS');
    $check($app->handle(new HttpRequest('GET', '/api/v1/unknown'))->status === 404, 'unknown route 404');
    $check($app->handle(new HttpRequest('GET', '/', ['Accept' => 'text/html']))->status === 406, 'unsupported Accept returns 406');
    $check($app->handle(new HttpRequest('GET', '/', ['Accept' => 'application/json;q=0, */*;q=0']))->status === 406, 'Accept q=0 denied');
    $check($app->handle(new HttpRequest('GET', '/', ['Accept' => 'application/json;q=0, */*;q=0.5']))->status === 406, 'explicit JSON exclusion overrides wildcard');
    $check($app->handle(new HttpRequest('GET', '/', ['Accept' => 'text/html, */*;q=0.1']))->status === 200, 'wildcard accept allowed');
    $check($app->handle(new HttpRequest('GET', '/', ['Content-Length' => '1048577']))->status === 413, 'Content-Length limit');
    $check($app->handle(new HttpRequest('GET', '/', [], str_repeat('x', HttpRequest::MAX_BODY_BYTES + 1)))->status === 413, 'actual body limit');

    $router->get('/__tests/asset/{id}', static fn (HttpRequest $r): HttpResponse => HttpResponse::json(['id' => $r->attribute('id')]));
    $check(json_decode($app->handle(new HttpRequest('GET', '/__tests/asset/node_01'))->body, true)['id'] === 'node_01', 'named route parameters');
    $check($app->handle(new HttpRequest('GET', '/__tests/asset/nested/name'))->status === 404, 'route parameter cannot contain slashes');

    $router->post('/__tests/json', static fn (HttpRequest $r): HttpResponse => HttpResponse::json(['data' => $r->attribute('json')], 201), [
        'required' => ['name', 'enabled'],
        'properties' => ['name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 20], 'enabled' => ['type' => 'boolean']],
        'additionalProperties' => false,
    ]);
    $postJson = static fn (string $body, string $type = 'application/json'): HttpRequest => new HttpRequest('POST', '/__tests/json', ['Content-Type' => $type], $body);
    $check($app->handle($postJson('{"name":"lab01","enabled":true}'))->status === 201, 'validated JSON results in real handler');
    $check($app->handle($postJson('{"name":"x","enabled":true}'))->status === 422, 'minLength checked');
    $check($app->handle($postJson('{"name":"lab","enabled":"yes"}'))->status === 422, 'invalid type rejected');
    $check($app->handle($postJson('{"name":"lab"}'))->status === 422, 'missing required rejected');
    $check($app->handle($postJson('{"name":"lab","enabled":true,"extra":1}'))->status === 422, 'unknown field rejected');
    $check($app->handle($postJson('['))->status === 400, 'malformed JSON rejected');
    $check($app->handle($postJson('[]'))->status === 422, 'JSON array root rejected');
    $check($app->handle($postJson('{"name":"lab","enabled":true}', 'text/plain'))->status === 415, 'unsupported Content-Type rejected');
    $check($app->handle($postJson('{"name":"lab","enabled":true}', 'application/merge-patch+json'))->status === 201, 'JSON subtype accepted');
    $bad = $app->handle($postJson('{}'));
    $check(count(json_decode($bad->body, true)['errors'] ?? []) === 2, 'machine-readable validation errors');

    $router->get('/__tests/crash', static function (): never { throw new RuntimeException('password=private-value'); });
    $crash = $app->handle(new HttpRequest('GET', '/__tests/crash'));
    $check($crash->status === 500, 'exception mapped to 500');
    $check(!str_contains($crash->body, 'private-value'), 'exception details never exposed');
    $check(($crash->headers['X-Frame-Options'] ?? '') === 'DENY', 'error paths include security headers');
    $check((bool) preg_match('/^[0-9a-f]{24}$/D', $crash->headers['X-Request-Id'] ?? ''), 'error paths include request id');

    $router->get('/__tests/middleware', static fn (HttpRequest $r): HttpResponse => HttpResponse::json(['marker' => $r->attribute('middleware_marker')]));
    $kernel->pipe(new class implements MiddlewareInterface {
        public function process(HttpRequest $request, callable $next): HttpResponse
        {
            return $next($request->withAttribute('middleware_marker', 'executed'));
        }
    });
    $check(json_decode($app->handle(new HttpRequest('GET', '/__tests/middleware'))->body, true)['marker'] === 'executed', 'real middleware pipeline');

    foreach (['/api/%2fsecret', '/api/%5csecret', '/a/../b', '/a//b', '/a/%00'] as $badPath) {
        $rejected = false;
        try { HttpRequest::normalizePath($badPath); } catch (\Cajeer\Servers\Http\Exception\HttpException $e) { $rejected = $e->status === 400; }
        $check($rejected, 'unsafe path rejected: ' . $badPath);
    }
    $rejected = false;
    try { $router->get('/__tests/crash', static fn (): HttpResponse => new HttpResponse(200, '')); } catch (LogicException) { $rejected = true; }
    $check($rejected, 'duplicate route registration rejected');
    $rejected = false;
    try { (new HttpResponse(200, ''))->withHeader('X-Unsafe', "oops\r\nEvil: yes"); } catch (InvalidArgumentException) { $rejected = true; }
    $check($rejected, 'response header injection rejected');
    echo "PASS: $count HTTP checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, "[FAIL] " . $error->getMessage() . "\n");
    exit(1);
} finally {
    $app->terminate();
}
