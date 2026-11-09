<?php
declare(strict_types=1);

namespace Cajeer\Servers\Database;

use PDO;
use RuntimeException;
use Throwable;

/** PostgreSQL migrations with an advisory lock, checksum journal and atomic DDL. */
final class MigrationRunner
{
    private const LOCK_NAMESPACE = 1128352282;
    private const LOCK_RESOURCE = 1397904979;
    private const JOURNAL = 'cajeer_schema_migrations';
    private const MAX_FILE_SIZE = 2_097_152;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly string $directory,
        private readonly SqlStatementParser $parser = new SqlStatementParser(),
    ) {}

    /** @return list<array{version: string, checksum: string, status: string, applied_at: ?string}> */
    public function status(): array
    {
        $pdo = $this->postgres();
        $files = $this->files();
        $applied = $this->applied($pdo);
        $rows = [];
        $highestApplied = $applied === [] ? null : max(array_keys($applied));
        foreach ($files as $version => $file) {
            $previous = $applied[$version] ?? null;
            $rows[] = [
                'version' => $version,
                'checksum' => $file['checksum'],
                'status' => $previous === null
                    ? ($highestApplied !== null && strcmp($version, $highestApplied) <= 0 ? 'out_of_order' : 'pending')
                    : ($previous['checksum'] === $file['checksum'] ? 'applied' : 'modified'),
                'applied_at' => $previous['applied_at'] ?? null,
            ];
        }
        foreach ($applied as $version => $record) {
            if (!isset($files[$version])) {
                $rows[] = ['version' => $version, 'checksum' => $record['checksum'], 'status' => 'missing', 'applied_at' => $record['applied_at']];
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['version'], $b['version']));
        return $rows;
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_values(array_map(static fn (array $row): string => $row['version'], array_filter(
            $this->status(), static fn (array $row): bool => $row['status'] === 'pending',
        )));
    }

    /** @return array{valid: bool, rows: list<array{version: string, checksum: string, status: string, applied_at: ?string}>} */
    public function validate(): array
    {
        foreach ($this->files() as $file) {
            if ($this->parser->split($this->content($file['path'], $file['checksum'])) === []) {
                throw new RuntimeException('Migration SQL must contain at least one statement.');
            }
        }
        $rows = $this->status();
        $valid = !in_array('modified', array_column($rows, 'status'), true)
            && !in_array('missing', array_column($rows, 'status'), true)
            && !in_array('out_of_order', array_column($rows, 'status'), true);
        return ['valid' => $valid, 'rows' => $rows];
    }

    /**
     * @return array{applied: list<string>, pending: list<string>, dry_run: bool}
     */
    public function run(bool $dryRun = false, int $lockTimeoutSeconds = 10): array
    {
        if ($lockTimeoutSeconds < 0 || $lockTimeoutSeconds > 120) {
            throw new RuntimeException('Migration lock timeout must be 0..120 seconds.');
        }
        $pdo = $this->postgres();
        $this->acquireLock($pdo, $lockTimeoutSeconds);
        try {
            // Holding the advisory lock also protects against concurrent schema migrations.
            $files = $this->files();
            $applied = $this->applied($pdo);
            foreach ($applied as $version => $record) {
                if (!isset($files[$version])) {
                    throw new RuntimeException('Applied migration is missing from source: ' . $version);
                }
                if (!hash_equals($record['checksum'], $files[$version]['checksum'])) {
                    throw new RuntimeException('Migration checksum mismatch: ' . $version);
                }
            }
            $pending = array_values(array_diff(array_keys($files), array_keys($applied)));
            if ($applied === [] && !$this->journalExists($pdo)) {
                foreach (['users', 'roles', 'permissions', 'connectors', 'inventory_assets', 'audit_log'] as $table) {
                    $guard = $pdo->prepare('SELECT to_regclass(:name)');
                    if ($guard === false || !$guard->execute(['name' => $table])) {
                        throw new RuntimeException('Cannot inspect existing application schema.');
                    }
                    if (!in_array($guard->fetchColumn(), [null, false], true)) {
                        throw new RuntimeException('Existing application schema without migration journal; manual reconciliation required.');
                    }
                }
            }
            if ($pending !== [] && $applied !== [] && strcmp($pending[0], max(array_keys($applied))) <= 0) {
                throw new RuntimeException('Out-of-order migration detected; manual reconciliation required.');
            }
            if ($dryRun) {
                foreach ($pending as $version) {
                    if ($this->parser->split($this->content($files[$version]['path'], $files[$version]['checksum'])) === []) {
                        throw new RuntimeException('Empty SQL migration is not permitted: ' . $version);
                    }
                }
            }
            if ($dryRun || $pending === []) {
                return ['applied' => [], 'pending' => $pending, 'dry_run' => $dryRun];
            }
            $completed = [];
            foreach ($pending as $version) {
                // One transaction per migration: no partial schema or false journal entry on failure.
                $sql = $this->content($files[$version]['path'], $files[$version]['checksum']);
                $statements = $this->parser->split($sql);
                if ($statements === []) {
                    throw new RuntimeException('Empty SQL migration is not permitted: ' . $version);
                }
                $this->database->transaction(function (PDO $connection) use ($version, $statements, $files): void {
                    $this->createJournal($connection);
                    $start = hrtime(true);
                    foreach ($statements as $statement) {
                        $connection->exec($statement);
                    }
                    $ms = max(0, (int) round((hrtime(true) - $start) / 1_000_000));
                    $insert = $connection->prepare('INSERT INTO ' . self::JOURNAL . ' (version, checksum, execution_ms) VALUES (:version, :checksum, :execution_ms)');
                    if ($insert === false || !$insert->execute(['version' => $version, 'checksum' => $files[$version]['checksum'], 'execution_ms' => $ms])) {
                        throw new RuntimeException('Could not record successfully applied migration.');
                    }
                });
                $completed[] = $version;
            }
            return ['applied' => $completed, 'pending' => [], 'dry_run' => false];
        } finally {
            $this->releaseLock($pdo);
        }
    }

    private function postgres(): PDO
    {
        if ($this->database->driver() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL migration engine requires DB_CONNECTION=pgsql. MySQL migrations are not supported by the current PostgreSQL-first core.');
        }
        return $this->database->connection();
    }

    /** @return array<string, array{path: string, checksum: string}> */
    private function files(): array
    {
        if (!is_dir($this->directory) || !is_readable($this->directory)) {
            throw new RuntimeException('PostgreSQL migration directory is missing or unreadable.');
        }
        $files = [];
        foreach (new \DirectoryIterator($this->directory) as $item) {
            if ($item->isDot()) continue;
            $name = $item->getFilename();
            if (!str_ends_with($name, '.sql')) continue;
            if (!$item->isFile() || $item->isLink() || !preg_match('/^[0-9]{4,8}_[a-z0-9_]+\.sql$/D', $name)) {
                throw new RuntimeException('Invalid migration filename or non-regular file.');
            }
            $version = substr($name, 0, -4);
            $path = $item->getPathname();
            $content = $this->readFile($path);
            $files[$version] = ['path' => $path, 'checksum' => hash('sha256', $content)];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function readFile(string $path): string
    {
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new RuntimeException('Migration SQL must be nonempty and <=2 MiB.');
        }
        $sql = file_get_contents($path);
        if ($sql === false || strlen($sql) !== $size) {
            throw new RuntimeException('Cannot read complete migration SQL file.');
        }
        return $sql;
    }

    private function content(string $path, string $checksum): string
    {
        $content = $this->readFile($path);
        if (!hash_equals($checksum, hash('sha256', $content))) {
            throw new RuntimeException('Migration file changed during execution.');
        }
        return $content;
    }

    /** @return array<string, array{checksum: string, applied_at: string}> */
    private function applied(PDO $pdo): array
    {
        if (!$this->journalExists($pdo)) {
            return [];
        }
        $rows = $pdo->query('SELECT version, checksum, applied_at FROM ' . self::JOURNAL . ' ORDER BY version');
        if ($rows === false) {
            throw new RuntimeException('Cannot read migration journal.');
        }
        $result = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['version']] = ['checksum' => (string) $row['checksum'], 'applied_at' => (string) $row['applied_at']];
        }
        return $result;
    }

    private function journalExists(PDO $pdo): bool
    {
        $statement = $pdo->prepare('SELECT to_regclass(:table_name)');
        if ($statement === false || !$statement->execute(['table_name' => self::JOURNAL])) {
            throw new RuntimeException('Cannot inspect PostgreSQL migration journal.');
        }
        return !in_array($statement->fetchColumn(), [null, false], true);
    }

    private function createJournal(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::JOURNAL . ' (
            version varchar(200) PRIMARY KEY,
            checksum char(64) NOT NULL,
            execution_ms integer NOT NULL CHECK (execution_ms >= 0),
            applied_at timestamptz NOT NULL DEFAULT now()
        )');
    }

    private function acquireLock(PDO $pdo, int $seconds): void
    {
        $sql = 'SELECT pg_try_advisory_lock(:namespace, :resource)';
        $start = microtime(true);
        do {
            $stmt = $pdo->prepare($sql);
            if ($stmt === false || !$stmt->execute(['namespace' => self::LOCK_NAMESPACE, 'resource' => self::LOCK_RESOURCE])) {
                throw new RuntimeException('Cannot obtain migration lock.');
            }
            if (in_array($stmt->fetchColumn(), [true, 1, '1', 't', 'true'], true)) {
                return;
            }
            if ((microtime(true) - $start) >= $seconds) {
                break;
            }
            usleep(100_000);
        } while (true);
        throw new RuntimeException('Timed out waiting for PostgreSQL migration advisory lock.');
    }

    private function releaseLock(PDO $pdo): void
    {
        $stmt = $pdo->prepare('SELECT pg_advisory_unlock(:namespace, :resource)');
        if ($stmt === false || !$stmt->execute(['namespace' => self::LOCK_NAMESPACE, 'resource' => self::LOCK_RESOURCE]) || !in_array($stmt->fetchColumn(), [true, 1, '1', 't', 'true'], true)) {
            throw new RuntimeException('Could not release PostgreSQL migration advisory lock; restart the database connection.');
        }
    }
}
