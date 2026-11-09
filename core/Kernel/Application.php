<?php
declare(strict_types=1);

namespace Cajeer\Servers\Kernel;

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Config\EnvironmentLoader;
use Cajeer\Servers\Logging\JsonLogger;
use Cajeer\Servers\Runtime\ErrorHandler;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;
use RuntimeException;

/** Long-lived process-safe application lifecycle: boot, handle, terminate. */
final class Application
{
    private bool $booted = false;
    private bool $terminated = false;
    private ?ConfigRepository $configuration = null;
    private ?ServiceContainer $services = null;
    private ?ErrorHandler $errors = null;
    private string|false $previousDisplayErrors = false;

    public function __construct(private readonly string $root)
    {
        if (!is_dir($root)) {
            throw new RuntimeException('Application root does not exist.');
        }
    }

    public function basePath(string $path = ''): string
    {
        return rtrim($this->root, '/') . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    public function boot(): self
    {
        if ($this->terminated) {
            throw new RuntimeException('Cannot boot a terminated application.');
        }
        if ($this->booted) {
            return $this;
        }
        (new EnvironmentLoader())->load($this->basePath('.env'));
        $this->configuration = new ConfigRepository($this->basePath('config'));
        $this->services = new ServiceContainer();
        $this->services->instance(self::class, $this);
        $this->services->instance(ServiceContainer::class, $this->services);
        $this->services->instance(ConfigRepository::class, $this->configuration);
        $this->services->singleton(JsonLogger::class, fn (ServiceContainer $container): JsonLogger => new JsonLogger($this->basePath('storage/logs')));
        $this->services->singleton(RuntimeDiagnostics::class, fn (ServiceContainer $container): RuntimeDiagnostics => new RuntimeDiagnostics($this->basePath(), $container->get(ConfigRepository::class)));

        $env = (string) $this->configuration->get('app.env', 'production');
        $debug = $env !== 'production' && $this->configuration->get('app.debug', false) === true;
        if (!$debug) {
            $this->previousDisplayErrors = ini_get('display_errors');
            ini_set('display_errors', '0');
        }
        $this->errors = new ErrorHandler($this->services->get(JsonLogger::class), $debug);
        $this->errors->mode(PHP_SAPI === 'cli' ? 'cli' : 'http');
        $this->errors->register();
        $this->booted = true;
        return $this;
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $this->ensureBooted();
        $requestId = bin2hex(random_bytes(12));
        $this->errors()->mode('http', $requestId);
        $headers = ['X-Request-Id' => $requestId];
        if ($request->path !== '/') {
            return HttpResponse::json(['error' => 'Not Found', 'request_id' => $requestId], 404, $headers);
        }
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            return HttpResponse::json(['error' => 'Method Not Allowed', 'request_id' => $requestId], 405, [
                ...$headers, 'Allow' => 'GET, HEAD',
            ]);
        }
        $diagnostics = $this->container()->get(RuntimeDiagnostics::class)->run();
        if (!$diagnostics['ok']) {
            return HttpResponse::json(['error' => 'Service Unavailable', 'request_id' => $requestId], 503, $headers);
        }
        return HttpResponse::json([
            'name' => $this->name(),
            'version' => $this->version(),
            'type' => 'api-first web control shell',
        ], 200, $headers);
    }

    public function terminate(): void
    {
        if ($this->terminated) {
            return;
        }
        $this->errors?->unregister();
        if ($this->previousDisplayErrors !== false) {
            ini_set('display_errors', $this->previousDisplayErrors);
        }
        $this->terminated = true;
    }

    public function config(): ConfigRepository
    {
        $this->ensureBooted();
        return $this->configuration;
    }

    public function container(): ServiceContainer
    {
        $this->ensureBooted();
        return $this->services;
    }

    public function errors(): ErrorHandler
    {
        $this->ensureBooted();
        return $this->errors;
    }

    public function name(): string
    {
        return $this->booted ? (string) $this->config()->get('app.name', 'CajeerServers') : 'CajeerServers';
    }

    public function version(): string
    {
        $file = $this->basePath('VERSION');
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';
        return $version !== '' ? $version : '0.0.0-dev';
    }

    private function ensureBooted(): void
    {
        if (!$this->booted || $this->terminated) {
            throw new RuntimeException('Application is not active.');
        }
    }
}
