<?php
declare(strict_types=1);

namespace Cajeer\Servers\Release;

use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Inventory\InventoryReadiness;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Observability\Diagnostics;
use Cajeer\Servers\Runtime\RuntimeDiagnostics;
use Throwable;

/** Fail-closed operational deployment gate. Emits labels, never credentials or exception messages. */
final class DeploymentVerifier
{
    public function __construct(private readonly Application $app) {}

    /** @return array{ok:bool,version:string,checks:list<array{name:string,ok:bool,detail:string}>} */
    public function verify(bool $strict = false): array
    {
        $result = (new ReleaseVerifier($this->app->basePath()))->verify($strict);
        $checks = &$result['checks'];
        $this->probe($checks, 'runtime', fn (): bool => $this->app->container()->get(RuntimeDiagnostics::class)->run()['ok'] === true,
            'Runtime prerequisite or configuration failed.');
        $this->probe($checks, 'pdo_pgsql', fn (): bool => extension_loaded('pdo_pgsql'), 'PostgreSQL PDO driver is required.');
        $root = realpath($this->app->basePath());
        $public = realpath($this->app->basePath('public'));
        $storage = realpath($this->app->basePath('storage'));
        $this->probe($checks, 'storage_isolation', static fn (): bool => $root !== false && $public !== false && $storage !== false
            && $storage !== $public && !str_starts_with($storage, rtrim($public, '/') . '/'),
            'Runtime storage must not be publicly served.');
        $envPath = $this->app->basePath('.env');
        $this->probe($checks, 'environment_file', static function () use ($envPath, $strict): bool {
            if (!$strict && !file_exists($envPath)) return true;
            if (!is_file($envPath) || is_link($envPath) || !is_readable($envPath)) return false;
            $permissions = fileperms($envPath);
            return $permissions !== false && ($permissions & 0077) === 0;
        }, 'Private .env must be a regular file with mode 0600 or more restrictive.');
        if ($strict) {
            $this->probe($checks, 'database_transport', function (): bool {
                $db = $this->app->config()->get('database.connections.pgsql', []);
                if (!is_array($db)) return false;
                $host = (string) ($db['host'] ?? '');
                $mode = (string) ($db['sslmode'] ?? 'prefer');
                if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_starts_with($host, '/')) return true;
                return $mode === 'verify-full' && is_string($db['sslrootcert'] ?? null)
                    && is_file($db['sslrootcert']) && is_readable($db['sslrootcert']);
            }, 'Remote PostgreSQL connections require verify-full TLS with a trusted CA.');
            $this->probe($checks, 'redis_transport', function (): bool {
                $cfg = $this->app->config()->get('cache.redis', []);
                if (!is_array($cfg)) return false;
                $host = (string)($cfg['host'] ?? '');
                return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
                    || ($cfg['tls'] ?? false) === true;
            }, 'Remote Redis connections require TLS.');
            $this->probe($checks, 'production_mode', fn (): bool => $this->app->config()->get('app.env') === 'production'
                && $this->app->config()->get('app.debug') === false, 'Strict deployment requires production and disabled debug.');
            $this->probe($checks, 'installation_marker', fn (): bool => $this->app->container()->get(InstallState::class)->isInstalled(),
                'Installer must have finished before deployment.');
            $this->probe($checks, 'database_version', function (): bool {
                $db = $this->app->container()->get(DatabaseManager::class);
                if ($db->driver() !== 'pgsql') return false;
                $version = $db->connection()->query("SELECT current_setting('server_version_num')")?->fetchColumn();
                return ctype_digit((string) $version) && (int) $version >= 170000;
            }, 'PostgreSQL 17+ is required.');
            $this->probe($checks, 'installation_consistency', function (): bool {
                $state = $this->app->container()->get(InstallState::class);
                $file = $state->directory() . '/installed.json';
                if (!is_file($file) || is_link($file)) return false;
                $marker = json_decode((string) @file_get_contents($file), true);
                if (!is_array($marker) || !is_string($marker['installation_id'] ?? null)) return false;
                $id = $this->app->container()->get(DatabaseManager::class)->connection()
                    ->query('SELECT installation_id::text FROM cajeer_installation WHERE singleton_id=1')?->fetchColumn();
                return is_string($id) && hash_equals($id, $marker['installation_id']);
            }, 'Installation marker must match the committed database installation ID.');
            $this->probe($checks, 'migrations', function (): bool {
                $migration = $this->app->container()->get(MigrationRunner::class);
                $validation = $migration->validate();
                $rows = $validation['rows'];
                return $validation['valid'] && count($rows) >= 8 && count(array_filter(
                    $rows, static fn (array $row): bool => $row['status'] !== 'applied')) === 0;
            }, 'Every shipped PostgreSQL migration must be applied with valid checksum.');
            $this->probe($checks, 'inventory_readiness', fn (): bool => $this->app->container()
                ->get(InventoryReadiness::class)->inspect(true)['ok'] === true,
                'Inventory contains invalid legacy data or upgrade constraints are unvalidated.');
            $this->probe($checks, 'dependencies', fn (): bool => $this->app->container()->get(Diagnostics::class)->run()['ok'] === true,
                'Required PostgreSQL, Redis, queue, scheduler and storage must pass readiness checks.');
        }
        $result['ok'] = !in_array(false, array_column($checks, 'ok'), true);
        return $result;
    }

    /** @param list<array{name:string,ok:bool,detail:string}> $checks */
    private function probe(array &$checks, string $name, callable $test, string $message): void
    {
        try { $ok = $test() === true; } catch (Throwable) { $ok = false; }
        $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $ok ? 'ok' : $message];
    }
}
