<?php
declare(strict_types=1);

namespace Cajeer\Servers\Installer;

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Config\EnvironmentLoader;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use PDO;
use RuntimeException;

/** One-time installer: PostgreSQL migrations and first administrator are actually persisted. */
final class InstallService
{
    private InstallState $state;
    private EnvironmentWriter $writer;

    public function __construct(private readonly string $root)
    {
        $this->state = new InstallState($root);
        $this->writer = new EnvironmentWriter($root);
    }

    public function state(): InstallState { return $this->state; }

    /** @return array{ok:bool,checks:list<array{name:string,ok:bool}>} */
    public function preflight(): array
    {
        $checks = [];
        foreach (['json','pdo','pdo_pgsql','openssl','sodium'] as $extension) {
            $checks[] = ['name' => 'ext-' . $extension, 'ok' => extension_loaded($extension)];
        }
        $checks[] = ['name' => 'PHP>=8.4', 'ok' => PHP_VERSION_ID >= 80400];
        $checks[] = ['name' => 'Argon2id', 'ok' => defined('PASSWORD_ARGON2ID')];
        $checks[] = ['name' => 'writable .env', 'ok' => $this->writer->checkWritable()];
        $checks[] = ['name' => 'private storage', 'ok' => is_writable($this->state->directory())];
        $checks[] = ['name' => 'PostgreSQL migrations available', 'ok' => is_file($this->root . '/migrations/pgsql/0003_installation_state.sql')];
        $checks[] = ['name' => 'not installed', 'ok' => !$this->state->isInstalled()];
        return ['ok' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks];
    }

    /**
     * Supply APP_URL, APP_ENV, DB_* optionally (uses existing environment otherwise).
     * Required admin_email, admin_name, admin_password. Secrets never returned.
     * @param array<string,string> $input
     * @return array{installed:bool,installed_at:string,admin_email:string,migrations:list<string>}
     */
    public function install(array $input): array
    {
        return $this->state->exclusive(function () use ($input): array {
            if ($this->state->isInstalled()) throw new RuntimeException('CajeerServers is already installed.');
            $preflight = $this->preflight();
            if (!$preflight['ok']) {
                $failed = array_column(array_filter($preflight['checks'], static fn (array $c): bool => !$c['ok']), 'name');
                throw new RuntimeException('Installer prerequisites failed: ' . implode(', ', $failed));
            }
            $data = $this->normalize($input);
            $passwordHash = password_hash($data['admin_password'], PASSWORD_ARGON2ID, [
                'memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1,
            ]);
            if ($passwordHash === false) throw new RuntimeException('Administrator password hashing failed.');
            $env = $data['env'];
            // Use an explicit, independent DB connection: the already-booted app
            // must not silently continue using stale configuration after .env changes.
            $manager = new DatabaseManager(
                new ConfigRepository($this->root . '/config'), new ConnectionFactory(),
                [
                    'driver' => 'pgsql', 'host' => $env['DB_HOST'], 'port' => (int) $env['DB_PORT'],
                    'database' => $env['DB_DATABASE'], 'username' => $env['DB_USERNAME'],
                    'password' => $env['DB_PASSWORD'], 'sslmode' => $env['DB_SSLMODE'],
                    'sslrootcert' => $env['DB_SSLROOTCERT'], 'connect_timeout' => 5,
                ],
            );
            // Fail BEFORE overwriting .env if PostgreSQL is unavailable, outdated,
            // or an administrator/database-backed installation already exists.
            $pdo = $manager->connection();
            $version = $pdo->query("SELECT current_setting('server_version_num')")->fetchColumn();
            if (!ctype_digit((string) $version) || (int) $version < 170000) {
                throw new RuntimeException('PostgreSQL 17 or newer is required.');
            }
            $stateTable = $pdo->query("SELECT to_regclass('cajeer_installation')")->fetchColumn();
            if (!in_array($stateTable, [null, false], true)
                && (int) $pdo->query('SELECT count(*) FROM cajeer_installation')->fetchColumn() > 0) {
                throw new RuntimeException('Database is already installed. Run install:recover if the local marker was lost.');
            }
            $usersTable = $pdo->query("SELECT to_regclass('users')")->fetchColumn();
            if (!in_array($usersTable, [null, false], true)
                && (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn() > 0) {
                throw new RuntimeException('Database contains existing users; installation refused.');
            }
            $this->writer->write($env);
            $migrations = (new MigrationRunner($manager, $this->root . '/migrations/pgsql'))->run();
            $record = $manager->transaction(function (PDO $pdo) use ($data, $passwordHash): array {
                // Distinct from migration advisory lock; guards duplicate admin creation.
                $pdo->query('SELECT pg_advisory_xact_lock(1128352282, 1397904994)')?->fetchColumn();
                $record = $pdo->query('SELECT installation_id::text AS id, installed_at::text AS created FROM cajeer_installation WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC);
                if (is_array($record)) throw new RuntimeException('Database is already installed; refusing to create another administrator.');
                if ((int) $pdo->query('SELECT count(*) FROM users')->fetchColumn() !== 0) {
                    throw new RuntimeException('Database contains users; use an existing installation or reconcile manually.');
                }
                $role = $pdo->prepare('INSERT INTO roles (slug,name) VALUES (:slug,:name) ON CONFLICT (slug) DO NOTHING');
                $role->execute(['slug' => 'administrator', 'name' => 'Администратор']);
                $roleId = $pdo->query("SELECT id::text FROM roles WHERE slug = 'administrator'")->fetchColumn();
                if (!is_string($roleId) || $roleId === '') throw new RuntimeException('Could not initialize administrator role.');
                $user = $pdo->prepare('INSERT INTO users (email, name, password_hash) VALUES (:email,:name,:hash) RETURNING id::text');
                $user->execute(['email' => $data['admin_email'], 'name' => $data['admin_name'], 'hash' => $passwordHash]);
                $userId = $user->fetchColumn();
                if (!is_string($userId) || $userId === '') throw new RuntimeException('Could not create administrator.');
                $link = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
                $link->execute(['user_id' => $userId, 'role_id' => $roleId]);
                // Migration 0009 creates the default workspace before the first administrator exists.
                $pdo->prepare("INSERT INTO workspace_members (workspace_id,user_id,role) SELECT w.id,:user,'owner' FROM workspaces w JOIN organizations o ON o.id=w.organization_id WHERE o.slug='default' AND w.slug='default' ON CONFLICT DO NOTHING")->execute(['user'=>$userId]);
                $row = $pdo->prepare('INSERT INTO cajeer_installation (admin_user_id) VALUES (:user_id) RETURNING installation_id::text, installed_at::text');
                $row->execute(['user_id' => $userId]);
                $installed = $row->fetch(PDO::FETCH_NUM);
                if (!is_array($installed) || count($installed) < 2) throw new RuntimeException('Could not record installation state.');
                $audit = $pdo->prepare("INSERT INTO audit_log (actor_type, actor_id, action, target_type, target_id, result) VALUES ('system', :actor, 'installation.completed', 'user', :target, 'success')");
                $audit->execute(['actor' => $userId, 'target' => $userId]);
                return ['id' => (string) $installed[0], 'at' => (string) $installed[1]];
            });
            $this->state->finish($record['id'], $record['at']);
            return ['installed' => true, 'installed_at' => $record['at'], 'admin_email' => $data['admin_email'], 'migrations' => $migrations['applied']];
        });
    }

    /** Rebuild only the local marker after DB commit succeeded but file write failed. */
    public function recover(): array
    {
        return $this->state->exclusive(function (): array {
            $localInstalled = $this->state->isInstalled();
            $config = [
                'driver' => 'pgsql', 'host' => EnvironmentLoader::getString('DB_HOST', '127.0.0.1'),
                'port' => EnvironmentLoader::getString('DB_PORT', '5432'),
                'database' => EnvironmentLoader::getString('DB_DATABASE', 'cajeerservers'),
                'username' => EnvironmentLoader::getString('DB_USERNAME', 'cajeer'),
                'password' => EnvironmentLoader::getString('DB_PASSWORD'),
                'sslmode' => EnvironmentLoader::getString('DB_SSLMODE', 'prefer'),
                'sslrootcert' => EnvironmentLoader::getString('DB_SSLROOTCERT'),
                'connect_timeout' => 5,
            ];
            if (EnvironmentLoader::getString('DB_CONNECTION', 'pgsql') !== 'pgsql') {
                throw new RuntimeException('Recovery requires PostgreSQL.');
            }
            $pdo = (new ConnectionFactory())->make($config);
            $exists = $pdo->query("SELECT to_regclass('cajeer_installation')")->fetchColumn();
            if (in_array($exists, [null, false], true)) throw new RuntimeException('No installation record exists in PostgreSQL.');
            $stmt = $pdo->query('SELECT installation_id::text AS id, installed_at::text AS created FROM cajeer_installation WHERE singleton_id = 1');
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new RuntimeException('No completed installation exists in PostgreSQL.');
            if ($localInstalled) {
                $local = json_decode((string) file_get_contents($this->state->directory() . '/installed.json'), true);
                if (!is_array($local) || !isset($local['installation_id'])
                    || !hash_equals((string) $row['id'], (string) $local['installation_id'])) {
                    throw new RuntimeException('Local and database installation markers disagree; manual reconciliation required.');
                }
                return $this->state->status();
            }
            $this->state->finish((string) $row['id'], (string) $row['created']);
            return $this->state->status();
        });
    }

    /** @param array<string,string> $input @return array{admin_email:string,admin_name:string,admin_password:string,env:array<string,string>} */
    public function normalize(array $input): array
    {
        $get = static function (string $key, string $fallback = '') use ($input): string {
            $value = $input[$key] ?? EnvironmentLoader::getString($key, $fallback);
            if (!is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new RuntimeException('Invalid installer field: ' . $key);
            return $value;
        };
        $email = strtolower(trim($input['admin_email'] ?? ''));
        $name = trim($input['admin_name'] ?? '');
        $password = $input['admin_password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) throw new RuntimeException('Valid administrator email is required.');
        if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new RuntimeException('Administrator name is invalid.');
        if (!is_string($password) || strlen($password) < 14 || strlen($password) > 1024) throw new RuntimeException('Administrator password must be 14–1024 bytes.');
        $environment = $get('APP_ENV', 'production');
        if (!in_array($environment, ['local', 'staging', 'production'], true)) throw new RuntimeException('Invalid APP_ENV.');
        $url = $get('APP_URL', '');
        $urlParts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($urlParts) || !in_array($urlParts['scheme'] ?? '', ['http','https'], true)
            || isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['fragment'])
            || ($environment !== 'local' && ($urlParts['scheme'] ?? '') !== 'https')) {
            throw new RuntimeException('APP_URL must be a valid HTTPS URL (HTTP allowed for local environment only).');
        }
        $dbDriver = $get('DB_CONNECTION', 'pgsql');
        if ($dbDriver !== 'pgsql') throw new RuntimeException('Initial installation requires PostgreSQL (DB_CONNECTION=pgsql).');
        $port = $get('DB_PORT', '5432');
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) throw new RuntimeException('Invalid DB_PORT.');
        $key = $get('APP_KEY');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if ($key !== '' && (!is_string($decoded) || strlen($decoded) < 32 || strlen(count_chars($decoded, 3)) < 8 || str_contains(strtolower($key), 'change-me'))) throw new RuntimeException('APP_KEY must contain at least 32 random bytes.');
        if ($key === '') $key = 'base64:' . base64_encode(random_bytes(32));
        $env = [
            'APP_NAME' => $get('APP_NAME', 'CajeerServers'), 'APP_ENV' => $environment,
            'APP_DEBUG' => 'false', 'APP_URL' => $url, 'APP_KEY' => $key,
            'APP_LOCALE' => $get('APP_LOCALE', 'ru'), 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $get('DB_HOST', '127.0.0.1'), 'DB_PORT' => (string) (int) $port,
            'DB_DATABASE' => $get('DB_DATABASE', 'cajeerservers'), 'DB_USERNAME' => $get('DB_USERNAME', 'cajeer'),
            'DB_PASSWORD' => $get('DB_PASSWORD'), 'DB_SSLMODE' => $get('DB_SSLMODE', 'prefer'),
            'DB_SSLROOTCERT' => $get('DB_SSLROOTCERT'),
        ];
        if ($environment === 'production' && !in_array($env['DB_HOST'], ['127.0.0.1','localhost','::1'], true)
            && $env['DB_SSLMODE'] !== 'verify-full') {
            throw new RuntimeException('Production remote PostgreSQL requires DB_SSLMODE=verify-full.');
        }
        return ['admin_email' => $email, 'admin_name' => $name, 'admin_password' => $password, 'env' => $env];
    }
}
