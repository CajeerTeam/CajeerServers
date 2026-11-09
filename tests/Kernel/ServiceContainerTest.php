<?php
declare(strict_types=1);

namespace Cajeer\Servers\Tests\Kernel;

use Cajeer\Servers\Kernel\ServiceContainer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ServiceContainerTest extends TestCase
{
    public function testSingletonAndTransient(): void
    {
        $container = new ServiceContainer();
        $container->singleton('singleton', static fn () => new \stdClass());
        $container->bind('transient', static fn () => new \stdClass());
        self::assertSame($container->get('singleton'), $container->get('singleton'));
        self::assertNotSame($container->get('transient'), $container->get('transient'));
    }

    public function testCircularDependencyFails(): void
    {
        $container = new ServiceContainer();
        $container->singleton('a', static fn (ServiceContainer $c) => $c->get('b'));
        $container->singleton('b', static fn (ServiceContainer $c) => $c->get('a'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular service dependency');
        $container->get('a');
    }
}
