<?php
declare(strict_types=1);

namespace Cajeer\Servers\Tests\Http;

use Cajeer\Servers\Http\Router;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use PHPUnit\Framework\TestCase;

final class HttpKernelTest extends TestCase
{
    public function testRealRoutesAndProtocolErrors(): void
    {
        $app = (new Application(dirname(__DIR__, 2)))->boot();
        try {
            self::assertSame(200, $app->handle(new HttpRequest('GET', '/api/v1/system'))->status);
            self::assertSame('v1', json_decode($app->handle(new HttpRequest('GET', '/api/v1/system'))->body, true)['api_version']);
            self::assertSame(404, $app->handle(new HttpRequest('GET', '/api/v1/actions'))->status);
            self::assertSame(405, $app->handle(new HttpRequest('POST', '/api/v1/system'))->status);
            self::assertSame(406, $app->handle(new HttpRequest('GET', '/', ['Accept' => 'text/html']))->status);
            self::assertSame(204, $app->handle(new HttpRequest('OPTIONS', '/api/v1/system'))->status);
        } finally {
            $app->terminate();
        }
    }

    public function testRouteParametersAndJsonValidation(): void
    {
        $app = (new Application(dirname(__DIR__, 2)))->boot();
        try {
            $router = $app->container()->get(Router::class);
            $router->post('/__phpunit/{id}', static fn (HttpRequest $r): HttpResponse => HttpResponse::json([
                'id' => $r->attribute('id'), 'data' => $r->attribute('json'),
            ]), ['required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'minLength' => 3]]]);
            $response = $app->handle(new HttpRequest('POST', '/__phpunit/host01', ['Content-Type' => 'application/json'], '{"name":"node"}'));
            self::assertSame(200, $response->status);
            self::assertSame('host01', json_decode($response->body, true)['id']);
            self::assertSame(422, $app->handle(new HttpRequest('POST', '/__phpunit/host01', ['Content-Type' => 'application/json'], '{"name":1}'))->status);
        } finally {
            $app->terminate();
        }
    }
}
