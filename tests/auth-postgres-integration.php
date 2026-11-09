#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ACTUAL PostgreSQL integration, not a PDO double. Refuses any DB not named
 * cajeer_test_* and only runs when explicitly enabled by the operator.
 *
 * CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_auth \
 *   CAJEER_PG_TEST_USERNAME=cajeer CAJEER_PG_TEST_PASSWORD=... \
 *   php tests/auth-postgres-integration.php
 */
require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Auth\SessionCookie;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Runtime\HttpRequest;

if (getenv('CAJEER_PG_TEST') !== '1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D', getenv('CAJEER_PG_TEST_DATABASE') ?: '')) {
    fwrite(STDERR, "SKIP: set CAJEER_PG_TEST=1 and CAJEER_PG_TEST_DATABASE=cajeer_test_* on a disposable DB.\n");
    exit(77);
}
if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "SKIP: ext-pdo_pgsql is unavailable.\n");
    exit(77);
}
$env = [
    'APP_ENV' => 'local', 'APP_URL' => 'http://127.0.0.1:8080',
    'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
    'DB_CONNECTION' => 'pgsql',
    'DB_HOST' => getenv('CAJEER_PG_TEST_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('CAJEER_PG_TEST_PORT') ?: '5432',
    'DB_DATABASE' => getenv('CAJEER_PG_TEST_DATABASE'),
    'DB_USERNAME' => getenv('CAJEER_PG_TEST_USERNAME') ?: 'cajeer',
    'DB_PASSWORD' => getenv('CAJEER_PG_TEST_PASSWORD') ?: '',
    'DB_SSLMODE' => getenv('CAJEER_PG_TEST_SSLMODE') ?: 'prefer',
];
$original = [];
foreach ($env as $name => $value) {
    $original[$name] = getenv($name);
    putenv($name . '=' . $value);
}
$root = sys_get_temp_dir() . '/cajeer-auth-integration-' . bin2hex(random_bytes(8));
mkdir($root . '/storage/app', 0700, true);
$pdo = null;
$ids = [];
$checks = 0;
$test = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('Assertion failed: ' . $message);
    $checks++;
};
$expectHttp = static function (int $status, callable $callback) use ($test): void {
    try {
        $callback();
    } catch (HttpException $e) {
        $test($e->status === $status, 'expected HTTP ' . $status . ', got ' . $e->status);
        return;
    }
    throw new RuntimeException('Expected HTTP ' . $status . ' but request succeeded.');
};
try {
    $config = new ConfigRepository(dirname(__DIR__) . '/config');
    $db = new DatabaseManager($config, new ConnectionFactory());
    $pdo = $db->connection();
    $test((int) $pdo->query("SELECT current_setting('server_version_num')")->fetchColumn() >= 170000, 'PostgreSQL 17+');
    (new MigrationRunner($db, dirname(__DIR__) . '/migrations/pgsql'))->run();
    $tables = $pdo->query("SELECT to_regclass('auth_sessions') IS NOT NULL AND to_regclass('auth_login_attempts') IS NOT NULL")->fetchColumn();
    $test($tables === true || $tables === 't' || $tables === '1' || $tables === 1, 'auth tables migrated');
    $state = new InstallState($root);
    $state->finish('00000000-0000-4000-8000-000000000001', gmdate('c'));
    $cookie = new SessionCookie($config);
    $auth = new AuthenticationService($db, $config, $state, $cookie);
    $email = 'auth-test-' . bin2hex(random_bytes(5)) . '@example.invalid';
    $password = 'initial-password-' . bin2hex(random_bytes(12));
    $newPassword = 'new-auth-password-' . bin2hex(random_bytes(12));
    $insert = $pdo->prepare('INSERT INTO users (email,name,password_hash) VALUES (:email,:name,:hash) RETURNING id::text');
    $insert->execute(['email' => $email, 'name' => 'Auth Test', 'hash' => password_hash($password, PASSWORD_ARGON2ID)]);
    $userId = (string) $insert->fetchColumn();
    $ids[] = $userId;
    $test($userId !== '', 'test user inserted');

    // Each failure must persist, and six failures lock out this identity+address.
    for ($i = 0; $i < 6; $i++) {
        $expectHttp(401, static fn () => $auth->loginOrReject($email, 'wrong password', '192.0.2.10'));
    }
    $expectHttp(429, static fn () => $auth->loginOrReject($email, $password, '192.0.2.10'));
    $login = $auth->loginOrReject($email, $password, '192.0.2.11');
    $test($login['user']['id'] === $userId && str_contains($login['cookie'], 'HttpOnly'), 'login creates session and cookie');
    $cookieHeader = explode(';', $login['cookie'], 2)[0];
    $browser = static fn (string $method, string $path, ?string $csrf = null): HttpRequest =>
        new HttpRequest($method, $path, array_filter(['Cookie' => $cookieHeader, 'X-CSRF-Token' => $csrf]));
    $session = $auth->authenticate($browser('GET', '/api/v1/auth/me'));
    $test($session['mode'] === 'session' && $session['user']['id'] === $userId, 'server verifies session secret');
    $expectHttp(403, static fn () => $auth->requireCsrf($browser('POST', '/api/v1/auth/tokens', 'invalid'), $session));
    $auth->requireCsrf($browser('POST', '/api/v1/auth/tokens', $login['csrf_token']), $session);
    $test(true, 'CSRF verifier accepts valid secret');

    $issued = $auth->createToken($session, 'integration client', ['profile:read','tokens:read'], 900);
    $test(str_starts_with($issued['token'], 'at1.') && strlen($issued['token']) === 84, 'API token generated once');
    $storedHash = $pdo->prepare('SELECT token_hash FROM api_tokens WHERE id = :id');
    $storedHash->execute(['id' => $issued['id']]);
    $test($storedHash->fetchColumn() === hash('sha256', $issued['token']), 'only token SHA256 persisted');
    $bearer = new HttpRequest('GET', '/api/v1/auth/me', ['Authorization' => 'Bearer ' . $issued['token']]);
    $tokenPrincipal = $auth->authenticate($bearer, ['profile:read']);
    $test($tokenPrincipal['mode'] === 'token' && $tokenPrincipal['user']['id'] === $userId, 'bearer authenticates without cookie');
    $test(count($auth->tokens($tokenPrincipal)) === 1, 'token can read own token list with scope');
    $expectHttp(403, static fn () => $auth->authenticate($bearer, ['sessions:read']));
    $expectHttp(401, static fn () => $auth->authenticate(new HttpRequest('GET', '/api/v1/auth/me', ['Authorization' => 'Bearer ' . $issued['token'] . 'oops'])));
    $expectHttp(401, static fn () => $auth->authenticate(new HttpRequest('GET', '/api/v1/auth/me', ['Authorization' => 'Bearer ' . $issued['token'], 'Cookie' => $cookieHeader]), [], true));

    $freshCsrf = $auth->rotateCsrf($session);
    $expectHttp(403, static fn () => $auth->requireCsrf($browser('POST', '/api/v1/auth/tokens', $login['csrf_token']), $auth->authenticate($browser('GET','/api/v1/auth/me'))));
    $session = $auth->authenticate($browser('GET', '/api/v1/auth/me'));
    $auth->requireCsrf($browser('POST', '/api/v1/auth/tokens', $freshCsrf), $session);
    $auth->revokeToken($session, $issued['id']);
    $expectHttp(401, static fn () => $auth->authenticate($bearer, ['profile:read']));
    $test($auth->tokens($session) === [], 'revoked API tokens vanish from active list');

    $newSession = $auth->loginOrReject($email, $password, '192.0.2.12');
    $test(count($auth->sessions($session)) === 2, 'multiple simultaneous sessions tracked');
    $thisCookie = explode(';', $newSession['cookie'], 2)[0];
    $other = $auth->authenticate(new HttpRequest('GET','/api/v1/auth/me',['Cookie' => $thisCookie]));
    $auth->revokeSession($session, $other['id']);
    $expectHttp(401, static fn () => $auth->authenticate(new HttpRequest('GET','/api/v1/auth/me',['Cookie' => $thisCookie])));
    $test(count($auth->sessions($session)) === 1, 'revoked session is removed');

    $auth->changePassword($session, $password, $newPassword);
    $expectHttp(401, static fn () => $auth->authenticate($browser('GET','/api/v1/auth/me')));
    $expectHttp(401, static fn () => $auth->loginOrReject($email, $password, '192.0.2.13'));
    $after = $auth->loginOrReject($email, $newPassword, '192.0.2.14');
    $test(is_array($after['user']) && $after['user']['id'] === $userId, 'new Argon2id password works');
    $afterCookie = explode(';', $after['cookie'], 2)[0];
    $afterPrincipal = $auth->authenticate(new HttpRequest('GET','/api/v1/auth/me',['Cookie' => $afterCookie]));
    $auth->logout($afterPrincipal);
    $expectHttp(401, static fn () => $auth->authenticate(new HttpRequest('GET','/api/v1/auth/me',['Cookie' => $afterCookie])));
    $stmt = $pdo->prepare('SELECT count(*) FROM audit_log WHERE actor_id = :id AND action IN (\'auth.login.succeeded\',\'auth.token.created\',\'auth.token.revoked\',\'auth.password.changed\')');
    $stmt->execute(['id' => $userId]);
    $test((int) $stmt->fetchColumn() >= 4, 'security audit events recorded');
    echo "PASS: {$checks} checks against real disposable PostgreSQL.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] PostgreSQL auth integration: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($pdo !== null) {
        try {
            foreach ($ids as $id) {
                // audit_log is append-only since 0.1.6; test actors leave durable audit records.
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
            }
        } catch (Throwable $e) { fwrite(STDERR, "WARNING: test user cleanup failed; disposable DB must be discarded.\n"); }
    }
    foreach (glob($root . '/storage/app/*') ?: [] as $file) @unlink($file);
    @rmdir($root . '/storage/app'); @rmdir($root . '/storage'); @rmdir($root);
    foreach ($original as $name => $value) {
        if ($value === false) putenv($name); else putenv($name . '=' . $value);
    }
}
