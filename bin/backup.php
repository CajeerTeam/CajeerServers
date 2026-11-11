#!/usr/bin/env php
<?php
declare(strict_types=1);

use Cajeer\Servers\Cli\CommandRunner;

$app = null;
try {
    $app = require dirname(__DIR__) . '/core/bootstrap.php';
    $code = (new CommandRunner($app))->run(['cajeer', 'backup:create', ...array_slice($argv, 1)]);
} catch (Throwable $error) {
    fwrite(STDERR, "Disaster recovery bootstrap failed. Check configuration and file permissions.\n");
    $code = 1;
} finally {
    $app?->terminate();
}
exit($code);
