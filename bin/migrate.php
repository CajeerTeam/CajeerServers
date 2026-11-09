#!/usr/bin/env php
<?php
declare(strict_types=1);

use Cajeer\Servers\Cli\CommandRunner;

$app = null;
$code = 1;
try {
    $app = require dirname(__DIR__) . '/core/bootstrap.php';
    $code = (new CommandRunner($app))->run(['cajeer', 'migrate', ...array_slice($argv, 1)]);
} catch (Throwable $error) {
    fwrite(STDERR, "Migration bootstrap failed; check configuration and logs.\n");
} finally {
    $app?->terminate();
}
exit($code);
