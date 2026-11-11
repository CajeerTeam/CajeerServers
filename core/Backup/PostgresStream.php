<?php
declare(strict_types=1);

namespace Cajeer\Servers\Backup;

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use PDO;
use RuntimeException;

/** PostgreSQL custom-format archive over pipes; no unencrypted dump on disk. */
final class PostgresStream
{
    private mixed $process = null;
    private mixed $stream = null;
    private mixed $errors = null;
    private string $operation = '';

    public function __construct(private readonly ConfigRepository $config) {}

    /** @return array<string,mixed> */
    public function settings(): array
    {
        if ($this->config->get('database.default') !== 'pgsql') {
            throw new RuntimeException('Encrypted disaster recovery currently supports PostgreSQL only.');
        }
        $cfg = $this->config->get('database.connections.pgsql');
        if (!is_array($cfg) || ($cfg['driver'] ?? '') !== 'pgsql') throw new RuntimeException('PostgreSQL is not configured.');
        foreach (['host','database','username','password','sslmode'] as $key) {
            if (!is_string($cfg[$key] ?? null)) throw new RuntimeException('Invalid PostgreSQL connection setting.');
        }
        if ($this->config->get('app.env') === 'production'
            && !in_array($cfg['host'], ['localhost', '127.0.0.1', '::1'], true)
            && $cfg['sslmode'] !== 'verify-full') {
            throw new RuntimeException('Remote production PostgreSQL backups/restores require DB_SSLMODE=verify-full.');
        }
        return $cfg;
    }

    public function sourceDatabase(): string { return $this->settings()['database']; }

    /** @param array<string,mixed> $cfg */
    private function environment(array $cfg): array
    {
        $environment = [
            'PGPASSWORD' => $cfg['password'],
            'PGSSLMODE' => $cfg['sslmode'],
            'PGCONNECT_TIMEOUT' => (string) ($cfg['connect_timeout'] ?? 5),
            'LANG' => 'C', 'LC_ALL' => 'C',
        ];
        if (($cfg['sslrootcert'] ?? '') !== '') $environment['PGSSLROOTCERT'] = (string) $cfg['sslrootcert'];
        return $environment;
    }

    /** @return list<string> */
    private function connectionArgs(array $cfg, string $database): array
    {
        if ($database === '' || strlen($database) > 63 || str_contains($database, "\0")) {
            throw new RuntimeException('Invalid PostgreSQL database name.');
        }
        return [
            '--host=' . $cfg['host'], '--port=' . (string) $cfg['port'],
            '--username=' . $cfg['username'], '--dbname=' . $database,
            '--no-password',
        ];
    }

    private function executable(string $environmentName, string $default): string
    {
        $file = getenv($environmentName);
        $file = is_string($file) && $file !== '' ? $file : $default;
        if ($file[0] !== '/' || is_link($file) || !is_file($file) || !is_executable($file)) {
            throw new RuntimeException($environmentName . ' must point to an existing executable absolute path.');
        }
        return $file;
    }

    public function startDump(): void
    {
        $cfg = $this->settings();
        $args = [$this->executable('PG_DUMP_BINARY','/usr/bin/pg_dump'),
            '--format=custom','--compress=6','--no-owner','--no-acl',
            ...$this->connectionArgs($cfg, $cfg['database'])];
        $this->start($args, $cfg, 'dump');
    }

    /** Enforce a genuinely empty, operator-named restore target. */
    public function checkEmptyTarget(string $database): void
    {
        $cfg = $this->settings();
        $this->connectionArgs($cfg, $database);
        $cfg['database'] = $database;
        $pdo = (new ConnectionFactory())->make($cfg);
        $rows = (int) $pdo->query("SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
            WHERE LEFT(n.nspname, 3) <> 'pg_' AND n.nspname <> 'information_schema'
            AND c.relkind IN ('r','p','v','m','S','f')")->fetchColumn();
        $procedures = (int) $pdo->query("SELECT count(*) FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
            WHERE LEFT(n.nspname, 3) <> 'pg_' AND n.nspname <> 'information_schema'")->fetchColumn();
        $schemas = (int) $pdo->query("SELECT count(*) FROM pg_catalog.pg_namespace
            WHERE LEFT(nspname, 3) <> 'pg_' AND nspname NOT IN ('public','information_schema')")->fetchColumn();
        $extensions = (int) $pdo->query("SELECT count(*) FROM pg_catalog.pg_extension WHERE extname <> 'plpgsql'")->fetchColumn();
        $types = (int) $pdo->query("SELECT count(*) FROM pg_catalog.pg_type t JOIN pg_catalog.pg_namespace n ON n.oid=t.typnamespace
            WHERE LEFT(n.nspname, 3) <> 'pg_' AND n.nspname <> 'information_schema'
            AND t.typtype IN ('d','e','r','m')")->fetchColumn();
        if ($rows !== 0 || $procedures !== 0 || $schemas !== 0 || $extensions !== 0 || $types !== 0) {
            throw new RuntimeException('Restore target is not empty; refusing to overwrite production objects.');
        }
    }

    public function startRestore(string $database): void
    {
        $cfg = $this->settings();
        $args = [$this->executable('PG_RESTORE_BINARY','/usr/bin/pg_restore'),
            '--single-transaction','--exit-on-error','--no-owner','--no-acl',
            ...$this->connectionArgs($cfg, $database)];
        $this->start($args, $cfg, 'restore');
    }

    private function start(array $argv, array $cfg, string $mode): void
    {
        if ($this->process !== null) throw new RuntimeException('PostgreSQL operation already in progress.');
        $this->errors = tmpfile();
        if (!is_resource($this->errors)) throw new RuntimeException('Cannot allocate PostgreSQL diagnostic stream.');
        $pipes = [];
        $description = $mode === 'dump'
            ? [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => $this->errors]
            : [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => $this->errors];
        $process = @proc_open($argv, $description, $pipes, null, $this->environment($cfg), ['bypass_shell' => true]);
        if (!is_resource($process)) { fclose($this->errors); $this->errors = null; throw new RuntimeException('Cannot start PostgreSQL backup/restore client.'); }
        $this->process = $process; $this->operation = $mode;
        if ($mode === 'dump') { fclose($pipes[0]); $this->stream = $pipes[1]; }
        else { fclose($pipes[1]); $this->stream = $pipes[0]; }
    }

    /** @param callable(string):void $consume */
    public function readDump(callable $consume): array
    {
        if ($this->operation !== 'dump' || !is_resource($this->stream)) throw new RuntimeException('pg_dump was not started.');
        $hash = hash_init('sha256'); $bytes = 0; $signature = '';
        while (!feof($this->stream)) {
            $block = @fread($this->stream, EncryptedArchive::CHUNK);
            if ($block === false) throw new RuntimeException('pg_dump stream read failed.');
            if ($block === '') continue;
            if (strlen($signature) < 5) {
                $signature .= substr($block, 0, 5 - strlen($signature));
                if (strlen($signature) === 5 && $signature !== 'PGDMP') {
                    throw new RuntimeException('pg_dump did not produce a PostgreSQL custom-format archive.');
                }
            }
            $consume($block); hash_update($hash, $block); $bytes += strlen($block);
        }
        $this->finish();
        if ($bytes < 64) throw new RuntimeException('pg_dump emitted an invalid or empty custom-format dump.');
        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }

    public function writeRestore(string $data): void
    {
        if ($this->operation !== 'restore' || !is_resource($this->stream)) throw new RuntimeException('pg_restore was not started.');
        EncryptedArchive::writeAll($this->stream, $data);
    }

    public function finish(): void
    {
        if ($this->process === null) throw new RuntimeException('No PostgreSQL client is running.');
        if (is_resource($this->stream)) fclose($this->stream);
        $this->stream = null;
        $exit = proc_close($this->process); $this->process = null;
        if (is_resource($this->errors)) fclose($this->errors);
        $this->errors = null; $this->operation = '';
        if ($exit !== 0) throw new RuntimeException('PostgreSQL backup/restore client exited with an error; check database connectivity, privileges, schemas and client version.');
    }

    /** Kill on an authentication or IO failure; never let pg_restore commit on accidental EOF. */
    public function abort(): void
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process, 9);
            if (is_resource($this->stream)) @fclose($this->stream);
            @proc_close($this->process);
        }
        if (is_resource($this->errors)) fclose($this->errors);
        $this->process = $this->stream = $this->errors = null;
        $this->operation = '';
    }
}
