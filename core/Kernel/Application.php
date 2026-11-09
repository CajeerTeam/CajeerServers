<?php
declare(strict_types=1);

namespace Cajeer\Servers\Kernel;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Auth\AuthController;
use Cajeer\Servers\Auth\SessionCookie;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Config\EnvironmentLoader;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\ConnectionFactoryInterface;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Logging\JsonLogger;
use Cajeer\Servers\Http\HttpKernel;
use Cajeer\Servers\Http\Router;
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
        $this->services->singleton(ConnectionFactoryInterface::class, fn (ServiceContainer $container): ConnectionFactoryInterface => new ConnectionFactory());
        $this->services->singleton(DatabaseManager::class, fn (ServiceContainer $container): DatabaseManager => new DatabaseManager(
            $container->get(ConfigRepository::class), $container->get(ConnectionFactoryInterface::class),
        ));
        $this->services->singleton(MigrationRunner::class, fn (ServiceContainer $container): MigrationRunner => new MigrationRunner(
            $container->get(DatabaseManager::class), $this->basePath('migrations/pgsql'),
        ));
        $this->services->singleton(JsonLogger::class, fn (ServiceContainer $container): JsonLogger => new JsonLogger($this->basePath('storage/logs')));
        $this->services->singleton(InstallState::class, fn (ServiceContainer $container): InstallState => new InstallState($this->basePath()));
        $this->services->singleton(SessionCookie::class, fn (ServiceContainer $container): SessionCookie => new SessionCookie($container->get(ConfigRepository::class)));
        $this->services->singleton(AuthenticationService::class, fn (ServiceContainer $container): AuthenticationService => new AuthenticationService(
            $container->get(DatabaseManager::class), $container->get(ConfigRepository::class),
            $container->get(InstallState::class), $container->get(SessionCookie::class),
        ));
        $this->services->singleton(AuthController::class, fn (ServiceContainer $container): AuthController => new AuthController($container->get(AuthenticationService::class)));
        $this->services->singleton(RuntimeDiagnostics::class, fn (ServiceContainer $container): RuntimeDiagnostics => new RuntimeDiagnostics($this->basePath(), $container->get(ConfigRepository::class)));
        $this->services->singleton(Router::class, function (ServiceContainer $container): Router {
            $router = new Router();
            $register = require $this->basePath('routes/api.php');
            $register($router, $container);
            return $router;
        });
        $this->services->singleton(HttpKernel::class, fn (ServiceContainer $container): HttpKernel => new HttpKernel(
            $container->get(Router::class), $container->get(JsonLogger::class), $this->errors(),
        ));

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
        return $this->container()->get(HttpKernel::class)->handle($request);
    }

    public function httpKernel(): HttpKernel
    {
        $this->ensureBooted();
        return $this->container()->get(HttpKernel::class);
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
