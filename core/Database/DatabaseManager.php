<?php
declare(strict_types=1);

namespace Cajeer\Servers\Database;

use Cajeer\Servers\Config\ConfigRepository;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Lazy connections and savepoint-aware transactions for application services. */
final class DatabaseManager
{
    /** @var array<string, PDO> */
    private array $connections = [];
    /** @var array<string, int> */
    private array $levels = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ConnectionFactoryInterface $factory,
        private readonly ?array $connectionOverride = null,
    ) {}

    public function driver(): string
    {
        return $this->configuration()['driver'];
    }

    public function connection(): PDO
    {
        $name = $this->connectionName();
        return $this->connections[$name] ??= $this->factory->make($this->configuration());
    }

    /** @return array{ok: bool, driver: string, version: string} */
    public function ping(): array
    {
        $pdo = $this->connection();
        $sql = $this->driver() === 'pgsql' ? 'SELECT current_setting(\'server_version\')' : 'SELECT VERSION()';
        $version = $pdo->query($sql)?->fetchColumn();
        if (!is_string($version) || $version === '') {
            throw new RuntimeException('Database did not respond to version query.');
        }
        return ['ok' => true, 'driver' => $this->driver(), 'version' => $version];
    }

    /**
     * Transactional callback, nested calls use SQL savepoints. Retries only outermost
     * serialization/deadlock errors, never arbitrary application exceptions.
     */
    public function transaction(callable $callback, int $attempts = 1): mixed
    {
        if ($attempts < 1 || $attempts > 3) {
            throw new InvalidArgumentException('Transaction attempts must be between 1 and 3.');
        }
        $pdo = $this->connection();
        $name = $this->connectionName();
        $level = $this->levels[$name] ?? 0;
        if ($level > 0 && $attempts !== 1) {
            throw new InvalidArgumentException('Nested transactions cannot be retried.');
        }
        for ($try = 1; $try <= $attempts; $try++) {
            $savepoint = 'cajeer_sp_' . ($level + 1);
            if ($level === 0) {
                if (!$pdo->beginTransaction()) {
                    throw new RuntimeException('Could not begin database transaction.');
                }
            } else {
                $pdo->exec('SAVEPOINT ' . $savepoint);
            }
            $this->levels[$name] = $level + 1;
            try {
                $result = $callback($pdo);
                if ($level === 0) {
                    $pdo->commit();
                } else {
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                return $result;
            } catch (Throwable $error) {
                try {
                    if ($level === 0 && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    } elseif ($level > 0) {
                        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    }
                } catch (Throwable $rollbackError) {
                    throw new RuntimeException('Database transaction rollback failed.', 0, $rollbackError);
                } finally {
                    $this->levels[$name] = $level;
                }
                $sqlstate = $error instanceof PDOException ? (string) ($error->errorInfo[0] ?? $error->getCode()) : '';
                if ($level === 0 && $try < $attempts && in_array($sqlstate, ['40001', '40P01'], true)) {
                    usleep($try * 100_000);
                    continue;
                }
                throw $error;
            } finally {
                $this->levels[$name] = $level;
            }
        }
        throw new RuntimeException('Transaction retry limit exceeded.');
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        if ($this->connectionOverride !== null) return $this->connectionOverride;
        $name = $this->connectionName();
        $config = $this->config->get('database.connections.' . $name);
        if (!is_array($config) || !isset($config['driver']) || !is_string($config['driver'])) {
            throw new RuntimeException('Database connection is not configured: ' . $name);
        }
        return $config;
    }

    private function connectionName(): string
    {
        if ($this->connectionOverride !== null) return 'installer_pgsql';
        $name = $this->config->get('database.default', 'pgsql');
        if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_-]*$/D', $name)) {
            throw new RuntimeException('Invalid default database connection.');
        }
        return $name;
    }
}
