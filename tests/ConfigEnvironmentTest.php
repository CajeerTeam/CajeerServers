<?php
declare(strict_types=1);

namespace Cajeer\Servers\Tests;

use Cajeer\Servers\Config\EnvironmentLoader;
use PHPUnit\Framework\TestCase;

final class ConfigEnvironmentTest extends TestCase
{
    public function testProcessEnvironmentWinsOverDotenv(): void
    {
        $key = 'CAJEER_TEST_ENV_PRECEDENCE';
        $before = getenv($key);
        $path = tempnam(sys_get_temp_dir(), 'cajeer-env-');
        self::assertIsString($path);
        try {
            putenv($key . '=from-process');
            file_put_contents($path, $key . '=from-dotenv' . "\n");
            (new EnvironmentLoader())->load($path);
            self::assertSame('from-process', getenv($key));
        } finally {
            if ($before === false) putenv($key);
            else putenv($key . '=' . $before);
            unlink($path);
        }
    }
}
