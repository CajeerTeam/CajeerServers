#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Release\ReleaseVerifier;
use Cajeer\Servers\Release\DeploymentVerifier;
use Cajeer\Servers\Kernel\Application;

$root = dirname(__DIR__);
$count = 0;
$check = static function (bool $condition, string $label) use (&$count): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $count++;
};
$report = (new ReleaseVerifier($root))->verify();
$check($report['ok'], 'metadata and source match release version');
$check($report['version'] === '0.7.2', 'current version');
$check(count($report['checks']) >= 10, 'independent release checks');
$strict = (new ReleaseVerifier($root))->verify(true);
$check(count($strict['checks']) > count($report['checks']), 'deployment checks add installation and build verification');
foreach (['semver', 'release_version', 'admin_version', 'sdk_version', 'openapi_version', 'openapi_copies'] as $name) {
    $found = array_values(array_filter($report['checks'], static fn(array $x): bool => $x['name'] === $name));
    $check(count($found) === 1 && $found[0]['ok'], 'source identity: ' . $name);
}
foreach (['composer_lock', 'npm_lock', 'sdk_lock', 'sdk_installed', 'composer_installed', 'admin_built'] as $name) {
    $found = array_values(array_filter($strict['checks'], static fn(array $x): bool => $x['name'] === $name));
    $check(count($found) === 1, 'strict check exists: ' . $name);
}
$tmp = sys_get_temp_dir() . '/cajeer-release-' . bin2hex(random_bytes(6));
mkdir($tmp . '/admin', 0700, true);
try {
    foreach (['VERSION', 'release.json', 'composer.json', 'api/openapi.yaml', 'openapi.yaml', 'admin/package.json'] as $filename) {
        $target = $tmp . '/' . $filename;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
        copy($root . '/' . $filename, $target);
    }
    // A copied tree without code files must always fail.
    $check(!(new ReleaseVerifier($tmp))->verify()['ok'], 'missing executable files refused');
    $meta = json_decode((string)file_get_contents($tmp . '/release.json'), true);
    $meta['version'] = '0.1.5';
    file_put_contents($tmp . '/release.json', json_encode($meta, JSON_THROW_ON_ERROR));
    $rows = (new ReleaseVerifier($tmp))->verify()['checks'];
    $failed = array_values(array_filter($rows, static fn(array $x): bool => $x['name'] === 'release_version'));
    $check(count($failed) === 1 && !$failed[0]['ok'], 'version drift refused');
    file_put_contents($tmp . '/openapi.yaml', 'not valid openapi');
    $rows = (new ReleaseVerifier($tmp))->verify()['checks'];
    $failed = array_values(array_filter($rows, static fn(array $x): bool => $x['name'] === 'openapi_copies'));
    $check(count($failed) === 1 && !$failed[0]['ok'], 'OpenAPI drift refused');
} finally {
    foreach (['VERSION','release.json','composer.json','api/openapi.yaml','openapi.yaml','admin/package.json'] as $filename) @unlink($tmp . '/' . $filename);
    @rmdir($tmp . '/admin'); @rmdir($tmp . '/api'); @rmdir($tmp);
}
putenv('APP_ENV=local'); putenv('APP_DEBUG=false');
$app = (new Application($root))->boot();
try {
    $deployment = (new DeploymentVerifier($app))->verify(false);
    $check(count($deployment['checks']) > count($report['checks']), 'runtime checks are real');
    $check(count(array_filter($deployment['checks'], static fn(array $x): bool => $x['name'] === 'runtime')) === 1, 'runtime gate defined');
    $check(count(array_filter($deployment['checks'], static fn(array $x): bool => $x['name'] === 'storage_isolation' && $x['ok'])) === 1, 'storage is outside public');
} finally { $app->terminate(); }
echo "PASS: $count release smoke assertions\n";
