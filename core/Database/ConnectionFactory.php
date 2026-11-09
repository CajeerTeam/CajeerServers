<?php
declare(strict_types=1);

namespace Cajeer\Servers\Database;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/** Creates hardened, non-persistent PDO connections from structured configuration. */
final class ConnectionFactory implements ConnectionFactoryInterface
{
    public function make(array $config): PDO
    {
        $driver = $config['driver'] ?? null;
        if (!is_string($driver) || !in_array($driver, ['pgsql', 'mysql'], true)) {
            throw new InvalidArgumentException('Unsupported database driver; expected pgsql or mysql.');
        }
        $host = $this->string($config, 'host', '127.0.0.1');
        $database = $this->string($config, 'database');
        $username = $this->string($config, 'username');
        $password = $config['password'] ?? '';
        if (!is_string($password)) {
            throw new InvalidArgumentException('Database password must be a string.');
        }
        $this->assertDsnValue($host, 'host');
        $this->assertDsnValue($database, 'database');
        if ($database === '' || $username === '') {
            throw new InvalidArgumentException('Database name and username must not be empty.');
        }
        $port = $this->integer($config['port'] ?? ($driver === 'pgsql' ? 5432 : 3306), 'port', 1, 65535);
        $timeout = $this->integer($config['connect_timeout'] ?? 5, 'connect_timeout', 1, 30);

        if ($driver === 'pgsql') {
            $sslmode = $this->string($config, 'sslmode', 'prefer');
            if (!in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
                throw new InvalidArgumentException('Invalid PostgreSQL sslmode.');
            }
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d;sslmode=%s', $host, $port, $database, $timeout, $sslmode);
            $rootCert = $this->string($config, 'sslrootcert', '');
            if ($rootCert !== '') {
                $this->assertDsnValue($rootCert, 'sslrootcert');
                if (!is_file($rootCert) || !is_readable($rootCert)) {
                    throw new InvalidArgumentException('PostgreSQL CA certificate is not readable.');
                }
                $dsn .= ';sslrootcert=' . $rootCert;
            }
        } else {
            $charset = $this->string($config, 'charset', 'utf8mb4');
            if (!in_array($charset, ['utf8mb4'], true)) {
                throw new InvalidArgumentException('Only utf8mb4 is supported for MySQL.');
            }
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        }

        if (!extension_loaded('pdo_' . $driver)) {
            throw new RuntimeException('Required PHP extension ext-pdo_' . $driver . ' is not installed.');
        }
        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ];
            if ($driver === 'mysql') {
                $options[PDO::ATTR_TIMEOUT] = $timeout;
            }
            return new PDO($dsn, $username, $password, $options);
        } catch (PDOException $error) {
            // PDO driver messages can contain hostnames/credentials/DSN details.
            throw new RuntimeException('Database connection failed. Verify DB_* configuration and database availability.', 0, $error);
        }
    }

    private function string(array $config, string $key, ?string $fallback = null): string
    {
        $value = $config[$key] ?? $fallback;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Database %s must be a string.', $key));
        }
        return $value;
    }

    private function integer(mixed $value, string $name, int $min, int $max): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/D', $value))) {
            throw new InvalidArgumentException(sprintf('Database %s must be an integer.', $name));
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < $min || $number > $max) {
            throw new InvalidArgumentException(sprintf('Database %s is out of range.', $name));
        }
        return $number;
    }

    private function assertDsnValue(string $value, string $name): void
    {
        if ($value === '' || preg_match('/[;\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Unsafe database DSN ' . $name . '.');
        }
    }
}
