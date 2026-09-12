<?php
namespace Cajeer\Servers\Tests\Kernel;

use Cajeer\Servers\Kernel\Application;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function testApplicationName(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        self::assertSame('CajeerServers', $app->name());
    }
}
