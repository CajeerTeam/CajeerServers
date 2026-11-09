#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Config\EnvironmentLoader;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\ConnectionFactoryInterface;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Database\SqlStatementParser;

$count = 0;
$check = static function (bool $condition, string $label) use (&$count): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $count++;
    echo "[OK] $label\n";
};
$throws = static function (callable $test, string $label) use ($check): void {
    try { $test(); } catch (Throwable) { $check(true, $label); return; }
    $check(false, $label);
};
$directory = sys_get_temp_dir() . '/cajeer-db-smoke-' . bin2hex(random_bytes(6));
mkdir($directory . '/config', 0700, true);
mkdir($directory . '/migrations', 0700, true);
file_put_contents($directory . '/config/database.php', <<<'PHP_CONFIG'
<?php return ['default'=>'pgsql','connections'=>['pgsql'=>['driver'=>'pgsql','host'=>'localhost','database'=>'cajeer_test','username'=>'tester']]];
PHP_CONFIG);
$cleanup = static function (string $path) use (&$cleanup): void {
    foreach (new FilesystemIterator($path) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) $cleanup($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($path);
};

try {
    $parser = new SqlStatementParser();
    $sql = <<<'SQL'
-- comment ;
CREATE TABLE t (name text DEFAULT 'a;b');
/* outer ; /* nested ; */ comment ; */
CREATE FUNCTION foo() RETURNS void AS $body$
BEGIN RAISE NOTICE 'a;b'; END;
$body$ LANGUAGE plpgsql;
SELECT E'one\'two;three';
SQL;
    $statements = $parser->split($sql);
    $check(count($statements) === 3, 'SQL parser handles semicolons in literals, comments and dollar blocks');
    $check(str_contains($statements[1], 'RAISE NOTICE'), 'PL/pgSQL function body is not split');
    $throws(static fn () => $parser->split('SELECT $body$unterminated;'), 'unterminated dollar quote rejected');
    $throws(static fn () => $parser->split('SELECT 1 /* missing'), 'unterminated block comment rejected');
    $throws(static fn () => $parser->split("SELECT 1\0"), 'NUL SQL rejected');
    $check($parser->split(" -- nothing\n/* hi */ ") === [], 'comment-only SQL ignored');

    putenv('CAJEER_TEST_SECRET=123456');
    $check(EnvironmentLoader::getString('CAJEER_TEST_SECRET') === '123456', 'numeric secrets remain strings');
    putenv('CAJEER_TEST_SECRET=false');
    $check(EnvironmentLoader::getString('CAJEER_TEST_SECRET') === 'false', 'boolean-looking secrets remain strings');
    putenv('CAJEER_TEST_SECRET');

    $factory = new ConnectionFactory();
    $throws(static fn () => $factory->make(['driver'=>'pgsql','database'=>'test;host=evil','username'=>'test']), 'DSN injection rejected');
    $throws(static fn () => $factory->make(['driver'=>'pgsql','database'=>'test','username'=>'test','sslmode'=>'invalid']), 'invalid SSL mode rejected');
    $throws(static fn () => $factory->make(['driver'=>'sqlite','database'=>'test','username'=>'test']), 'unsupported database driver rejected');
    $throws(static fn () => $factory->make(['driver'=>'pgsql','database'=>'test','username'=>'test','port'=>'-1']), 'invalid port rejected');

    $pdo = new FakeMigrationPDO();
    $fakeFactory = new class($pdo) implements ConnectionFactoryInterface {
        public function __construct(private readonly PDO $pdo) {}
        public function make(array $config): PDO { return $this->pdo; }
    };
    $db = new DatabaseManager(new ConfigRepository($directory . '/config'), $fakeFactory);
    $check($db->connection() === $pdo && $db->connection() === $pdo, 'lazy cached PDO connection');
    $check($db->driver() === 'pgsql', 'configuration resolves pgsql driver');

    $counter = 0;
    $db->transaction(function (PDO $handle) use ($db, &$counter): void {
        $counter++;
        $db->transaction(static function (PDO $handle) use (&$counter): void { $counter++; });
    });
    $check($counter === 2 && !$pdo->inTransaction(), 'nested transaction uses savepoint');
    $throws(static function () use ($db): void {
        $db->transaction(static function (): void { throw new RuntimeException('rollback'); });
    }, 'transaction error propagates');
    $check(!$pdo->inTransaction() && $pdo->rollbacks >= 1, 'failed transaction rolls back');

    file_put_contents($directory . '/migrations/0001_first.sql', 'CREATE TABLE example(id integer);');
    file_put_contents($directory . '/migrations/0002_second.sql', 'CREATE INDEX example_idx ON example(id);');
    $runner = new MigrationRunner($db, $directory . '/migrations');
    $check(count($runner->pending()) === 2, 'unapplied migrations detected');
    $check($runner->run(true)['pending'] === ['0001_first', '0002_second'], 'dry-run returns pending plan');
    $check($pdo->executedSchema === [], 'dry-run does not execute schema SQL');
    $result = $runner->run();
    $check($result['applied'] === ['0001_first', '0002_second'], 'migrations applied in filename order');
    $check(count($pdo->executedSchema) === 2, 'real SQL passed to PDO exec');
    $check(count($pdo->applied) === 2 && $pdo->commits >= 3, 'migration journal committed with schema');
    $check($runner->run()['applied'] === [], 'idempotent second execution');
    $check(count($pdo->executedSchema) === 2, 'already applied SQL not repeated');
    $check($runner->validate()['valid'], 'valid migration journal');
    file_put_contents($directory . '/migrations/0000_old.sql', 'SELECT 0;');
    $check(!$runner->validate()['valid'], 'new migrations older than applied history are invalid');
    $check(in_array('out_of_order', array_column($runner->status(), 'status'), true), 'out-of-order migrations labeled in status');
    $throws(static fn () => $runner->run(), 'out-of-order SQL blocked');
    unlink($directory . '/migrations/0000_old.sql');

    file_put_contents($directory . '/migrations/0001_first.sql', 'SELECT 999;');
    $check(!$runner->validate()['valid'], 'changed migration checksum detected');
    $throws(static fn () => $runner->run(), 'modified applied migration blocks execution');
    $check(count($pdo->executedSchema) === 2, 'checksum mismatch has no new SQL effect');
    file_put_contents($directory . '/migrations/0001_first.sql', 'CREATE TABLE example(id integer);');

    file_put_contents($directory . '/migrations/0003_failure.sql', 'CREATE TABLE bad(id integer); FAIL_SQL;');
    $pdo->failOn = 'FAIL_SQL';
    $throws(static fn () => $runner->run(), 'SQL failure aborts migration');
    $check(!isset($pdo->applied['0003_failure']), 'failed migration is not marked applied');
    $check(!in_array('CREATE TABLE bad(id integer)', $pdo->executedSchema, true), 'failed migration schema changes rolled back');
    $check(!$pdo->inTransaction() && !$pdo->locked, 'failed migration releases transaction and advisory lock');
    $pdo->failOn = null;
    unlink($directory . '/migrations/0003_failure.sql');

    $pdo->blockLock = true;
    $throws(static fn () => $runner->run(false, 0), 'concurrent migration lock denied');
    $pdo->blockLock = false;
    $check(!$pdo->locked, 'lock timeout never marks lock acquired');

    unlink($directory . '/migrations/0001_first.sql');
    $check(!$runner->validate()['valid'], 'missing applied migration detected');
    $throws(static fn () => $runner->run(), 'missing applied migration blocks execution');
    $check(!$pdo->locked, 'advisory lock always released');
    // Existing schema without migration journal must not be silently adopted.
    file_put_contents($directory . '/migrations/0001_first.sql', 'CREATE TABLE example(id integer);');
    $freshPdo = new FakeMigrationPDO();
    $freshPdo->untrackedUsers = true;
    $freshFactory = new class($freshPdo) implements ConnectionFactoryInterface {
        public function __construct(private readonly PDO $pdo) {}
        public function make(array $config): PDO { return $this->pdo; }
    };
    $freshRunner = new MigrationRunner(
        new DatabaseManager(new ConfigRepository($directory . '/config'), $freshFactory),
        $directory . '/migrations',
    );
    $throws(static fn () => $freshRunner->run(), 'untracked existing application schema refused');
    $check($freshPdo->executedSchema === [] && !$freshPdo->locked, 'baseline refusal has no schema effect');

    fwrite(STDOUT, "PASS: $count database smoke checks (in-memory PDO test double; not PostgreSQL integration).\n");
} catch (Throwable $error) {
    fwrite(STDERR, "[FAIL] " . $error->getMessage() . "\n");
    exit(1);
} finally {
    $cleanup($directory);
}

/** Simulates PDO control flow; intentionally does not parse SQL as a database server. */
final class FakeMigrationPDO extends PDO
{
    public bool $journal = false;
    public bool $locked = false;
    public bool $blockLock = false;
    public bool $untrackedUsers = false;
    public bool $inTransaction = false;
    public ?string $failOn = null;
    public int $commits = 0;
    public int $rollbacks = 0;
    public array $executedSchema = [];
    public array $applied = [];
    private array $snapshots = [];

    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new FakeMigrationStatement($this, $query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return new FakeMigrationStatement($this, $query); }
    public function exec(string $statement): int|false
    {
        if ($this->failOn !== null && str_contains($statement, $this->failOn)) {
            throw new PDOException('Simulated SQL execution failure', '42P01');
        }
        if (str_starts_with($statement, 'CREATE TABLE IF NOT EXISTS cajeer_schema_migrations')) {
            $this->journal = true;
        } elseif (str_starts_with($statement, 'SAVEPOINT ')) {
            $this->snapshots[] = [$this->journal, $this->applied, $this->executedSchema];
        } elseif (str_starts_with($statement, 'ROLLBACK TO SAVEPOINT ')) {
            [$this->journal, $this->applied, $this->executedSchema] = end($this->snapshots);
        } elseif (str_starts_with($statement, 'RELEASE SAVEPOINT ')) {
            array_pop($this->snapshots);
        } else {
            $this->executedSchema[] = trim($statement);
        }
        return 0;
    }
    public function beginTransaction(): bool
    {
        $this->snapshots[] = [$this->journal, $this->applied, $this->executedSchema];
        $this->inTransaction = true;
        return true;
    }
    public function commit(): bool { $this->inTransaction = false; $this->snapshots = []; $this->commits++; return true; }
    public function rollBack(): bool
    {
        [$this->journal, $this->applied, $this->executedSchema] = $this->snapshots[0];
        $this->inTransaction = false;
        $this->snapshots = [];
        $this->rollbacks++;
        return true;
    }
    public function inTransaction(): bool { return $this->inTransaction; }
}
final class FakeMigrationStatement extends PDOStatement
{
    private mixed $column = null;
    private array $rows = [];
    public function __construct(private readonly FakeMigrationPDO $pdo, private readonly string $sql)
    {
        if (str_starts_with($sql, 'SELECT version, checksum')) {
            foreach ($pdo->applied as $version => $row) {
                $this->rows[] = ['version'=>$version, 'checksum'=>$row['checksum'], 'applied_at'=>'2026-11-09 00:00:00+00'];
            }
        }
    }
    public function execute(?array $params = null): bool
    {
        if (str_contains($this->sql, 'to_regclass')) {
            $this->column = $this->pdo->journal && ($params['table_name'] ?? $params['name'] ?? '') === 'cajeer_schema_migrations' ? 'cajeer_schema_migrations'
                : ($this->pdo->untrackedUsers && ($params['name'] ?? '') === 'users' ? 'users' : null);
        } elseif (str_contains($this->sql, 'pg_try_advisory_lock')) {
            $this->column = $this->pdo->blockLock || $this->pdo->locked ? 'f' : 't';
            if ($this->column === 't') $this->pdo->locked = true;
        } elseif (str_contains($this->sql, 'pg_advisory_unlock')) {
            $this->column = $this->pdo->locked ? 't' : 'f';
            $this->pdo->locked = false;
        } elseif (str_starts_with($this->sql, 'INSERT INTO cajeer_schema_migrations')) {
            $this->pdo->applied[$params['version']] = ['checksum'=>$params['checksum']];
        }
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->column; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
