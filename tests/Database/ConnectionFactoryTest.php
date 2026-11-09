<?php
declare(strict_types=1);

namespace Cajeer\Servers\Tests\Database;

use Cajeer\Servers\Database\ConnectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    public function testRejectsDsnInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConnectionFactory())->make([
            'driver' => 'pgsql', 'host' => '127.0.0.1;dbname=other',
            'database' => 'test', 'username' => 'tester',
        ]);
    }

    public function testRejectsInvalidSslMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ConnectionFactory())->make([
            'driver' => 'pgsql', 'host' => '127.0.0.1',
            'database' => 'test', 'username' => 'tester', 'sslmode' => 'arbitrary',
        ]);
    }
}
