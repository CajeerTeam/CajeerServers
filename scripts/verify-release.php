#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Executable release test runner. No fake pass from empty/missing tools. */
$root = dirname(__DIR__);
$strict = in_array('--strict', $argv, true);
foreach (array_slice($argv, 1) as $option) {
    if ($option !== '--strict') { fwrite(STDERR, "Usage: php scripts/verify-release.php [--strict]\n"); exit(2); }
}
require $root . '/core/autoload.php';
$failures = 0;
$steps = 0;
$run = static function (string $name, array $command, string $cwd, ?array $environment = null) use (&$failures, &$steps): void {
    $steps++;
    fwrite(STDOUT, "== $name ==\n");
    if (!function_exists('proc_open')) { fwrite(STDERR, "FAIL: proc_open unavailable\n"); $failures++; return; }
    $pipes = [];
    $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
    if (!is_resource($process)) { fwrite(STDERR, "FAIL: cannot start $name\n"); $failures++; return; }
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($process);
    fwrite(STDOUT, $stdout ?: '');
    if ($stderr !== '') fwrite(STDERR, $stderr);
    if ($status !== 0) { fwrite(STDERR, "FAIL: $name (exit $status)\n"); $failures++; }
};

$phpFiles = [];
foreach (['core', 'app', 'api', 'bin', 'public', 'tests', 'installer', 'scripts', 'routes', 'components'] as $folder) {
    if (!is_dir($root . '/' . $folder)) continue;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if ($file->isFile() && !$file->isLink() && $file->getExtension() === 'php') $phpFiles[] = $file->getPathname();
    }
}
sort($phpFiles);
$lintErrors = 0;
foreach ($phpFiles as $file) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $file], [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $root);
    if (!is_resource($process)) { $lintErrors++; break; }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { $lintErrors++; fwrite(STDERR, "$file: $out $err\n"); }
}
$steps++;
fwrite(STDOUT, sprintf("PHP lint: %d files, %d failures\n", count($phpFiles), $lintErrors));
if ($lintErrors > 0 || $phpFiles === []) $failures++;

foreach (['runtime', 'http', 'database', 'installer', 'auth', 'security', 'infrastructure', 'observability', 'admin', 'release', 'inventory-readiness', 'workspaces', 'inventory', 'ipam', 'topology', 'catalog', 'history', 'transfer'] as $suite) {
    $run($suite . ' smoke', [PHP_BINARY, 'tests/' . $suite . '-smoke.php'], $root);
}
$run('Admin frontend static', ['node', 'tests/static-check.mjs'], $root . '/admin');
$run('Inventory navigation contract', ['node', '--experimental-strip-types', 'tests/navigation-contract.mjs'], $root . '/admin');

if ($strict) {
    $run('Production release gate', [PHP_BINARY, 'bin/cajeer', 'release:check', '--strict'], $root);
    if (getenv('CAJEER_PG_TEST') !== '1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D', getenv('CAJEER_PG_TEST_DATABASE') ?: '')) {
        fwrite(STDERR, "FAIL: strict verification requires CAJEER_PG_TEST=1 and an isolated cajeer_test_* database.\n"); $failures++;
    } else {
        foreach (['database','auth','security','infrastructure','admin','inventory','workspaces','inventory-crud','ipam','topology','catalog','history','transfer'] as $name) {
            $run("PostgreSQL $name", [PHP_BINARY, 'tests/' . $name . '-postgres-integration.php'], $root);
        }
        $installerDb = getenv('CAJEER_INSTALL_PG_TEST_DATABASE') ?: '';
        if (!preg_match('/^cajeer_test_[a-z0-9_]+_test$/D', $installerDb) || $installerDb === getenv('CAJEER_PG_TEST_DATABASE')) {
            fwrite(STDERR, "FAIL: installer integration requires a separate EMPTY CAJEER_INSTALL_PG_TEST_DATABASE=cajeer_test_*_test.\n");
            $failures++;
        } else {
            $testEnv = getenv();
            $testEnv['CAJEER_PG_TEST_DATABASE'] = $installerDb;
            $testEnv['CAJEER_INSTALL_PG_TEST'] = '1';
            $run('PostgreSQL installer', [PHP_BINARY, 'tests/installer-postgres-integration.php'], $root, $testEnv);
        }
    }
    if (getenv('CAJEER_REDIS_TEST') !== '1') {
        fwrite(STDERR, "FAIL: strict verification requires real Redis integration (CAJEER_REDIS_TEST=1).\n"); $failures++;
    } else {
        $run('Redis integration', [PHP_BINARY, 'tests/infrastructure-redis-integration.php'], $root);
    }
    $run('PHPUnit', [$root . '/vendor/bin/phpunit', '--configuration', 'phpunit.xml.dist'], $root);
    $run('Nuxt typecheck', ['npm', 'run', 'typecheck'], $root . '/admin');
    $run('Nuxt build', ['npm', 'run', 'build'], $root . '/admin');
    if (!getenv('CAJEER_SMOKE_URL')) {
        fwrite(STDERR, "FAIL: strict verification requires CAJEER_SMOKE_URL to test a real HTTP deployment.\n"); $failures++;
    } else {
        $run('Real deployed HTTP', [PHP_BINARY, 'scripts/smoke-http.php'], $root);
    }
    if (!getenv('E2E_ADMIN_EMAIL') || !getenv('E2E_ADMIN_PASSWORD') || !getenv('E2E_BASE_URL') || getenv('E2E_ALLOW_WRITES') !== '1' || getenv('E2E_ISOLATED_STAGING') !== '1') {
        fwrite(STDERR, "FAIL: strict verification requires disposable E2E staging, credentials, E2E_ALLOW_WRITES=1 and E2E_ISOLATED_STAGING=1.\n"); $failures++;
    } else {
        $run('Playwright E2E preflight', ['npm', 'run', 'test:e2e:preflight'], $root . '/admin');
        $run('Playwright E2E', ['npm', 'run', 'test:e2e'], $root . '/admin');
    }
}
fwrite(STDOUT, "Release verification: $steps steps; failures: $failures" . ($strict ? ' (strict)' : ' (native)') . "\n");
exit($failures === 0 ? 0 : 1);
