<?php
declare(strict_types=1);

namespace Cajeer\Servers\Kernel;

use Cajeer\Servers\Cache\RedisClient;
use Cajeer\Servers\Cache\RedisCacheStore;
use Cajeer\Servers\Queue\QueueDriverInterface;
use Cajeer\Servers\Queue\RedisQueueDriver;
use Cajeer\Servers\Queue\PostgresQueueDriver;
use Cajeer\Servers\Queue\JobWorker;
use Cajeer\Servers\Queue\JobProcessor;
use Cajeer\Servers\Scheduler\Scheduler;
use Cajeer\Servers\Storage\StorageDiskInterface;
use Cajeer\Servers\Storage\LocalDisk;
use Cajeer\Servers\Storage\S3CompatibleDisk;
use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Auth\AuthController;
use Cajeer\Servers\Auth\SessionCookie;
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Security\SecretBox;
use Cajeer\Servers\Security\RateLimiter;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Security\TwoFactorManager;
use Cajeer\Servers\Security\WebhookManager;
use Cajeer\Servers\Security\SecurityController;
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

    private function localStorageRoot(string $configured): string
    {
        if ($configured === '') return $this->basePath('storage/files');
        if (!str_starts_with($configured, '/') || str_contains($configured, "\0")) {
            throw new RuntimeException('STORAGE_LOCAL_ROOT must be an absolute filesystem path.');
        }
        return $configured;
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
        $this->services->singleton(RedisClient::class, function (ServiceContainer $c): RedisClient {
            $r = $c->get(ConfigRepository::class)->get('cache.redis', []);
            $host = (string) ($r['host'] ?? '127.0.0.1');
            $tls = $r['tls'] ?? false;
            if ($c->get(ConfigRepository::class)->get('app.env') === 'production' && !$tls
                && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                throw new RuntimeException('Non-loopback Redis requires TLS in production.');
            }
            return new RedisClient($host, (int)($r['port']??6379), (string)($r['username']??''),
                (string)($r['password']??''), (int)($r['database']??0), (float)($r['timeout']??3),
                $tls === true, ($r['ca_file']??'') === '' ? null : (string)$r['ca_file']);
        });
        $this->services->singleton(RedisCacheStore::class, fn (ServiceContainer $c): RedisCacheStore => new RedisCacheStore(
            $c->get(RedisClient::class), (string)$c->get(ConfigRepository::class)->get('cache.redis.prefix','cajeer:cache:')));
        $this->services->singleton(QueueDriverInterface::class, function (ServiceContainer $c): QueueDriverInterface {
            return match ($c->get(ConfigRepository::class)->get('queue.default', 'redis')) {
                'redis' => new RedisQueueDriver($c->get(RedisClient::class), (string)$c->get(ConfigRepository::class)->get('queue.redis_prefix','cajeer:queue:')),
                'pgsql' => new PostgresQueueDriver($c->get(DatabaseManager::class)),
                default => throw new RuntimeException('Unsupported queue driver. Supported: redis, pgsql.'),
            };
        });
        $this->services->singleton(StorageDiskInterface::class, function (ServiceContainer $c): StorageDiskInterface {
            $cfg = $c->get(ConfigRepository::class);
            return match ($cfg->get('storage.default','local')) {
                'local' => new LocalDisk($this->localStorageRoot((string)$cfg->get('storage.local.root',''))),
                's3' => new S3CompatibleDisk((string)$cfg->get('storage.s3.endpoint',''),(string)$cfg->get('storage.s3.bucket',''),
                    (string)$cfg->get('storage.s3.region','us-east-1'),(string)$cfg->get('storage.s3.access_key',''),
                    (string)$cfg->get('storage.s3.secret_key',''),(string)$cfg->get('storage.s3.session_token',''),
                    $cfg->get('storage.s3.allow_local_http',false)===true,(int)$cfg->get('storage.s3.timeout',10)),
                default => throw new RuntimeException('Unsupported storage disk. Supported: local, s3.'),
            };
        });
        $this->services->singleton(JobProcessor::class, fn (ServiceContainer $c): JobProcessor => new JobProcessor(
            $c->get(RuntimeDiagnostics::class),$c->get(StorageDiskInterface::class),$c->get(QueueDriverInterface::class)));
        $this->services->singleton(JobWorker::class, fn (ServiceContainer $c): JobWorker => new JobWorker(
            $c->get(QueueDriverInterface::class),$c->get(JobProcessor::class),$c->get(JsonLogger::class)));
        $this->services->singleton(Scheduler::class, fn (ServiceContainer $c): Scheduler => new Scheduler(
            $c->get(DatabaseManager::class),$c->get(QueueDriverInterface::class),$c->get(JobProcessor::class)));

        $this->services->singleton(InstallState::class, fn (ServiceContainer $container): InstallState => new InstallState($this->basePath()));
        $this->services->singleton(SecretBox::class, fn (ServiceContainer $container): SecretBox => new SecretBox());
        $this->services->singleton(RateLimiter::class, fn (ServiceContainer $container): RateLimiter => new RateLimiter($container->get(SecretBox::class)));
        $this->services->singleton(AuditLogger::class, fn (ServiceContainer $container): AuditLogger => new AuditLogger($container->get(DatabaseManager::class)));
        $this->services->singleton(Rbac::class, fn (ServiceContainer $container): Rbac => new Rbac($container->get(DatabaseManager::class), $container->get(AuditLogger::class)));
        $this->services->singleton(TwoFactorManager::class, fn (ServiceContainer $container): TwoFactorManager => new TwoFactorManager(
            $container->get(DatabaseManager::class), $container->get(SecretBox::class), $container->get(AuditLogger::class), $container->get(RateLimiter::class),
        ));
        $this->services->singleton(WebhookManager::class, fn (ServiceContainer $container): WebhookManager => new WebhookManager(
            $container->get(DatabaseManager::class), $container->get(SecretBox::class), $container->get(AuditLogger::class), $container->get(RateLimiter::class),
        ));
        $this->services->singleton(SessionCookie::class, fn (ServiceContainer $container): SessionCookie => new SessionCookie($container->get(ConfigRepository::class)));
        $this->services->singleton(AuthenticationService::class, fn (ServiceContainer $container): AuthenticationService => new AuthenticationService(
            $container->get(DatabaseManager::class), $container->get(ConfigRepository::class),
            $container->get(InstallState::class), $container->get(SessionCookie::class),
            $container->get(TwoFactorManager::class), $container->get(RateLimiter::class),
        ));
        $this->services->singleton(AuthController::class, fn (ServiceContainer $container): AuthController => new AuthController($container->get(AuthenticationService::class)));
        $this->services->singleton(SecurityController::class, fn (ServiceContainer $container): SecurityController => new SecurityController(
            $container->get(AuthenticationService::class), $container->get(Rbac::class), $container->get(TwoFactorManager::class),
            $container->get(AuditLogger::class), $container->get(WebhookManager::class),
        ));
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
