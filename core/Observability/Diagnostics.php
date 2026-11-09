<?php
declare(strict_types=1);

namespace Cajeer\Servers\Observability;

use Cajeer\Servers\Cache\RedisClient;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Kernel\ServiceContainer;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;
use Cajeer\Servers\Storage\StorageDiskInterface;
use Throwable;

/** Operational probes: never return exception messages or connection credentials. */
final class Diagnostics
{
    public function __construct(
        private readonly RuntimeDiagnostics $runtime,
        private readonly ConfigRepository $config,
        private readonly ServiceContainer $container,
        private readonly InstallState $installation,
    ) {}

    /** @return array{ok:bool,status:string,checks:array<string,array{ok:bool,elapsed_ms:float,detail:string}>,checked_at:string} */
    public function run(bool $deep = false): array
    {
        $checks = [];
        $this->probe($checks, 'runtime', function (): void {
            if (!$this->runtime->run()['ok']) throw new \RuntimeException('runtime_not_ready');
        });
        $this->probe($checks, 'installation', function (): void {
            if (!$this->installation->isInstalled()) throw new \RuntimeException('installation_incomplete');
        });
        $this->probe($checks, 'database', function (): void {
            $this->container->get(DatabaseManager::class)->ping();
        });
        $this->probe($checks, 'redis', function (): void {
            if ($this->container->get(RedisClient::class)->command('PING') !== 'PONG') {
                throw new \RuntimeException('redis_unavailable');
            }
        });
        $this->probe($checks, 'queue', function (): void {
            $driver = (string) $this->config->get('queue.default', 'redis');
            if ($driver === 'redis') {
                if ($this->container->get(RedisClient::class)->command('PING') !== 'PONG') throw new \RuntimeException('queue_unavailable');
            } elseif ($driver === 'pgsql') {
                $this->requireTable('queue_jobs');
            } else {
                throw new \RuntimeException('unsupported_queue');
            }
        });
        if ($this->config->get('scheduler.enabled', true) === true) {
            $this->probe($checks, 'scheduler', fn () => $this->requireTable('scheduler_tasks'));
        }
        $this->probe($checks, 'storage', function () use ($deep): void {
            $disk = $this->container->get(StorageDiskInterface::class);
            if (!$deep && $this->config->get('storage.default', 'local') === 'local') return; // LocalDisk validates root.
            // For remote S3 readiness, verify actual read/write/delete instead of reporting configuration as healthy.
            $key = '_health/probe-' . bin2hex(random_bytes(12));
            $payload = random_bytes(32);
            $written = false;
            try {
                $disk->put($key, $payload);
                $written = true;
                if (!$disk->exists($key) || !hash_equals($payload, $disk->get($key))) {
                    throw new \RuntimeException('storage_readback_failed');
                }
            } finally {
                if ($written) $disk->delete($key);
            }
        });
        $ok = !in_array(false, array_column($checks, 'ok'), true);
        return ['ok' => $ok, 'status' => $ok ? 'ready' : 'not_ready', 'checks' => $checks,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z')];
    }

    /** @param array<string,array{ok:bool,elapsed_ms:float,detail:string}> $checks */
    private function probe(array &$checks, string $name, callable $operation): void
    {
        $start = hrtime(true);
        $ok = true;
        $detail = 'ok';
        try { $operation(); }
        catch (Throwable $error) {
            $ok = false;
            // Fixed labels only, never exception text (which might contain DSN/secrets).
            $detail = match ($name) {
                'installation' => 'not_installed',
                'runtime' => 'runtime_prerequisite_failed',
                'storage' => 'storage_unavailable_or_unverified',
                default => 'dependency_unavailable',
            };
        }
        $checks[$name] = ['ok' => $ok, 'elapsed_ms' => round((hrtime(true) - $start) / 1_000_000, 3), 'detail' => $detail];
    }

    private function requireTable(string $name): void
    {
        // Only hard-coded table names are permitted; values are never interpolated from user input.
        if (!in_array($name, ['queue_jobs', 'scheduler_tasks'], true)) throw new \LogicException('Invalid probe table.');
        $db = $this->container->get(DatabaseManager::class);
        if ($db->driver() !== 'pgsql') throw new \RuntimeException('PostgreSQL required for this probe.');
        if ($db->connection()->query("SELECT to_regclass('public.$name')")?->fetchColumn() !== $name) {
            throw new \RuntimeException('Dependency table not found.');
        }
    }
}
