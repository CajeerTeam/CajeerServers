#!/usr/bin/env php
<?php
declare(strict_types=1);

// The standalone CLI installer uses the exact same entrypoint as cajeer install.
$args = [$_SERVER['argv'][0] ?? __FILE__, 'install', ...array_slice($_SERVER['argv'] ?? [], 1)];
$app = null;
try {
    $app = require dirname(__DIR__, 2) . '/core/bootstrap.php';
    exit((new \Cajeer\Servers\Cli\CommandRunner($app))->run($args));
} catch (Throwable $e) {
    fwrite(STDERR, "Installation failed. Check prerequisites and server logs.\n");
    exit(1);
} finally {
    $app?->terminate();
}
