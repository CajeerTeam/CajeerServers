#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Config\EnvironmentLoader;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Kernel\ServiceContainer;
use Cajeer\Servers\Logging\JsonLogger;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    fwrite(STDOUT, '[OK] ' . $label . "\n");
};
$dir = sys_get_temp_dir() . '/cajeer-runtime-' . bin2hex(random_bytes(8));
mkdir($dir, 0700, true);
$cleanup = static function (string $path) use (&$cleanup): void {
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $target = $path . '/' . $name;
        if (is_dir($target)) $cleanup($target);
        else unlink($target);
    }
    rmdir($path);
};

try {
    mkdir($dir . '/config');
    file_put_contents($dir . '/config/test.php', '<?php return ["nested" => ["zero" => 0, "flag" => false], "nullable" => null];');
    $config = new ConfigRepository($dir . '/config');
    $assert($config->get('test.nested.zero') === 0, 'dot path integer');
    $assert($config->get('test.nested.flag', true) === false, 'dot path false is not default');
    $assert($config->has('test.nullable'), 'explicit null is present');
    $assert($config->get('test.missing', 'default') === 'default', 'config fallback');
    $assert(count($config->all()) === 1, 'configuration enumeration');
    $badKeyRejected = false;
    try { $config->get('../.env'); } catch (InvalidArgumentException) { $badKeyRejected = true; }
    $assert($badKeyRejected, 'configuration traversal denied');

    $dotenv = $dir . '/sample.env';
    $prefix = 'CJEER_TEST_' . strtoupper(bin2hex(random_bytes(3)));
    file_put_contents($dotenv, "$prefix" . "_BOOLEAN=true\n$prefix" . "_QUOTED=\"a # b\" # comment\n$prefix" . "_NUMBER=42\n$prefix" . "_RAW=abc#def\n");
    $loader = new EnvironmentLoader();
    $loader->load($dotenv);
    $assert(EnvironmentLoader::get($prefix . '_BOOLEAN') === true, 'environment bool');
    $assert(EnvironmentLoader::get($prefix . '_QUOTED') === 'a # b', 'quoted env and comments');
    $assert(EnvironmentLoader::get($prefix . '_NUMBER') === 42, 'environment integer');
    $assert(EnvironmentLoader::get($prefix . '_RAW') === 'abc#def', 'inline # in env values');
    foreach ($loader->loaded() as $key => $value) { putenv($key); unset($_ENV[$key]); }

    $existing = $prefix . '_PRESERVED';
    putenv($existing . '=external');
    file_put_contents($dotenv, "$existing=dotenv\n");
    (new EnvironmentLoader())->load($dotenv);
    $assert(getenv($existing) === 'external', 'external environment overrides dotenv');
    putenv($existing);
    file_put_contents($dotenv, "BROKEN INVALID LINE\n");
    $parseRejected = false;
    try { (new EnvironmentLoader())->load($dotenv); } catch (RuntimeException) { $parseRejected = true; }
    $assert($parseRejected, 'invalid dotenv rejected');

    $container = new ServiceContainer();
    $container->instance('null-service', null);
    $assert($container->has('null-service') && $container->get('null-service') === null, 'explicit null services');
    $container->singleton('shared', static fn (ServiceContainer $c): object => new stdClass());
    $assert($container->get('shared') === $container->get('shared'), 'singleton identity');
    $container->bind('fresh', static fn (ServiceContainer $c): object => new stdClass());
    $assert($container->get('fresh') !== $container->get('fresh'), 'transient identity');
    $container->alias('alias', 'shared');
    $assert($container->get('alias') === $container->get('shared'), 'alias resolution');
    $container->singleton('loop', static fn (ServiceContainer $c): mixed => $c->get('loop'));
    $loopRejected = false;
    try { $container->get('loop'); } catch (RuntimeException $e) { $loopRejected = str_contains($e->getMessage(), 'Circular'); }
    $assert($loopRejected, 'circular dependency rejected');
    $missingRejected = false;
    try { $container->get('missing-service'); } catch (RuntimeException) { $missingRejected = true; }
    $assert($missingRejected, 'unknown service rejected');
    $assert($container->get(RuntimeSmokeInjected::class)->dependency instanceof RuntimeSmokeDependency, 'constructor autowiring');

    $logger = new JsonLogger($dir . '/logs');
    $logger->info('test token=top-secret', ['api_token' => 'top-secret', 'nested' => ['password' => 'top-secret']]);
    $files = glob($dir . '/logs/*.jsonl') ?: [];
    $contents = (string) file_get_contents($files[0] ?? '');
    $assert($contents !== '' && !str_contains($contents, 'top-secret'), 'JSON log redacts tokens and passwords');
    $assert(json_decode(trim($contents), true, 512, JSON_THROW_ON_ERROR)['level'] === 'info', 'structured JSON log');
    $assert((fileperms($files[0]) & 0777) <= 0640, 'log permissions restricted');

    $app = (new Application(dirname(__DIR__)))->boot();
    try {
        $assert($app->boot() === $app, 'idempotent boot');
        $assert($app->container()->get(Application::class) === $app, 'application container binding');
        $assert($app->config()->get('app.name') !== null, 'project config loaded');
        $notFound = $app->handle(new HttpRequest('GET', '/unknown'));
        $assert($notFound->status === 404, 'unknown routes return 404');
        $notAllowed = $app->handle(new HttpRequest('POST', '/'));
        $assert($notAllowed->status === 405 && $notAllowed->headers['Allow'] === 'GET, HEAD', 'unsupported root method returns 405');
        $assert(strlen($notFound->headers['X-Request-Id']) === 24, 'request ID header exists');
    } finally {
        $app->terminate();
    }
    $assert($checks >= 27, 'smoke coverage threshold');
    fwrite(STDOUT, "PASS: $checks assertions\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n" );
    exit(1);
} finally {
    $cleanup($dir);
}

final class RuntimeSmokeDependency {}
final class RuntimeSmokeInjected
{
    public function __construct(public RuntimeSmokeDependency $dependency) {}
}
