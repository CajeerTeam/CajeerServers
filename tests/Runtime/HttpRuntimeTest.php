<?php
declare(strict_types=1);

namespace Cajeer\Servers\Tests\Runtime;

use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use PHPUnit\Framework\TestCase;

final class HttpRuntimeTest extends TestCase
{
    public function testNotFoundAndMethodNotAllowed(): void
    {
        $app = (new Application(dirname(__DIR__, 2)))->boot();
        try {
            self::assertSame(404, $app->handle(new HttpRequest('GET', '/missing'))->status);
            $response = $app->handle(new HttpRequest('POST', '/'));
            self::assertSame(405, $response->status);
            self::assertSame('GET, HEAD', $response->headers['Allow']);
        } finally {
            $app->terminate();
        }
    }
}
