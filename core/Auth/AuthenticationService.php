<?php
declare(strict_types=1);

namespace Cajeer\Servers\Auth;

use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Config\EnvironmentLoader;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\TwoFactorManager;
use Cajeer\Servers\Security\RateLimiter;
use Cajeer\Servers\Security\ApiToken;
use Cajeer\Servers\Security\CsrfProtection;
use PDO;
use RuntimeException;

/** PostgreSQL-backed, fail-closed authentication and self-service credential management. */
final class AuthenticationService
{
    public const TOKEN_SCOPES = ['profile:read', 'sessions:read', 'tokens:read'];
    private const SESSION_SECONDS = 43200;
    private const MAX_TOKEN_SECONDS = 2592000;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly InstallState $state,
        private readonly SessionCookie $cookie,
        private readonly ?TwoFactorManager $twoFactor = null,
        private readonly ?RateLimiter $rateLimiter = null,
    ) {}

    public function cookie(): SessionCookie { return $this->cookie; }

    public function assertInstalled(): void
    {
        if ((string) $this->config->get('app.env', 'production') === 'production'
            && !in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)) {
            throw new HttpException(403, 'HTTPS is required for authentication.', 'https_required');
        }
        if (!$this->state->isInstalled()) {
            throw new HttpException(503, 'Authentication is unavailable until installation is complete.', 'not_installed');
        }
        if ($this->database->driver() !== 'pgsql') {
            throw new HttpException(503, 'Authentication storage is unavailable.', 'auth_storage_unavailable');
        }
    }

    /** @return array{user:array<string,string>,cookie:string,csrf_token:string,expires_in:int} */
    public function login(string $email, string $password, string $clientAddress, ?string $mfaCode = null): array
    {
        $this->assertInstalled();
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255 || strlen($password) > 1024) {
            throw self::unauthorized();
        }
        // Rate limits are bound to an HMAC of the address and canonical email;
        // do not trust unconfigured X-Forwarded-For headers.
        $key = hash_hmac('sha256', $email . "\0" . $clientAddress, $this->appKey());
        return $this->database->transaction(function (PDO $pdo) use ($key, $email, $password, $mfaCode, $clientAddress): array {
            if ($this->rateLimiter !== null && !$this->rateLimiter->hit($pdo, 'login.ip', $clientAddress, 30, 900)) {
                return ['ip_limited' => true];
            }
            $guard = $pdo->prepare('SELECT failed_count, locked_until > now() AS locked FROM auth_login_attempts WHERE subject_hash = :hash FOR UPDATE');
            $guard->execute(['hash' => $key]);
            $blocked = $guard->fetch(PDO::FETCH_ASSOC);
            if (is_array($blocked) && self::dbBool($blocked['locked'] ?? false)) {
                throw new HttpException(429, 'Too many login attempts. Try again later.', 'login_rate_limited', ['Retry-After' => '900']);
            }
            $stmt = $pdo->prepare('SELECT id::text, email, name, password_hash, is_active, two_factor_enabled FROM users WHERE lower(email) = :email LIMIT 1 FOR UPDATE');
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            static $dummyHash = null;
            $dummyHash ??= password_hash('dummy-nonexistent-user-password', PASSWORD_ARGON2ID);
            $valid = password_verify($password, is_array($user) ? (string) $user['password_hash'] : $dummyHash);
            $mfaEnabled = is_array($user) && self::dbBool($user['two_factor_enabled'] ?? false);
            $mfaValid = !$mfaEnabled || ($valid && $this->twoFactor !== null && $this->twoFactor->verifyLogin($pdo, (string) $user['id'], $mfaCode));
            if (!$valid || !is_array($user) || !self::dbBool($user['is_active'] ?? false) || !$mfaValid) {
                $failure = $pdo->prepare(<<<'SQL'
INSERT INTO auth_login_attempts (subject_hash, failed_count, window_started, locked_until)
VALUES (:hash, 1, now(), NULL)
ON CONFLICT (subject_hash) DO UPDATE SET
    failed_count = CASE WHEN auth_login_attempts.window_started < now() - interval '15 minutes' THEN 1 ELSE auth_login_attempts.failed_count + 1 END,
    window_started = CASE WHEN auth_login_attempts.window_started < now() - interval '15 minutes' THEN now() ELSE auth_login_attempts.window_started END,
    locked_until = CASE
        WHEN auth_login_attempts.window_started < now() - interval '15 minutes' THEN NULL
        WHEN auth_login_attempts.failed_count + 1 >= 6 THEN now() + interval '15 minutes'
        ELSE NULL END
SQL);
                $failure->execute(['hash' => $key]);
                $this->audit($pdo, null, 'auth.login.failed', 'denied');
                // Do not throw inside a transaction: failure counters must commit.
                return ['rejected' => true];
            }
            if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
                $rehash = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                $rehash->execute(['hash' => password_hash($password, PASSWORD_ARGON2ID), 'id' => $user['id']]);
            }
            $pdo->prepare('DELETE FROM auth_login_attempts WHERE subject_hash = :hash')->execute(['hash' => $key]);
            $issued = TokenCodec::issue('cs1');
            $csrf = self::randomSecret();
            $save = $pdo->prepare("INSERT INTO auth_sessions (id, user_id, secret_hash, csrf_hash, expires_at, mfa_verified) VALUES (:id,:user,:secret,:csrf,now() + interval '12 hours',:mfa)");
            $save->execute(['id' => $issued['id'], 'user' => $user['id'], 'secret' => $issued['hash'], 'csrf' => TokenCodec::digest($csrf), 'mfa' => $mfaEnabled ? 'true' : 'false']);
            $this->audit($pdo, (string) $user['id'], 'auth.login.succeeded', 'success', 'session', $issued['id']);
            return [
                'user' => $this->publicUser($user), 'cookie' => $this->cookie->set($issued['credential']),
                'csrf_token' => $csrf, 'expires_in' => self::SESSION_SECONDS,
            ];
        }, 2) ?: throw new RuntimeException('Login failed.');
    }

    /** Reject without leaking whether a given account exists. */
    public function loginOrReject(string $email, string $password, string $clientAddress, ?string $mfaCode = null): array
    {
        $result = $this->login($email, $password, $clientAddress, $mfaCode);
        if (($result['ip_limited'] ?? false) === true) throw new HttpException(429, 'Too many login attempts.', 'login_rate_limited', ['Retry-After' => '900']);
        if (($result['rejected'] ?? false) === true) throw self::unauthorized();
        return $result;
    }

    /** @return array{mode:string,user:array<string,string>,id:string,scopes:list<string>,csrf_hash?:string} */
    public function authenticate(HttpRequest $request, array $scopes = [], bool $sessionOnly = false): array
    {
        $this->assertInstalled();
        $authorization = $request->header('Authorization');
        if ($authorization !== null) {
            if ($sessionOnly || preg_match('/^Bearer (at1\.[^\s]{1,160})$/iD', $authorization, $match) !== 1) {
                throw self::unauthorized();
            }
            return $this->bearer($match[1], $scopes);
        }
        $raw = $this->cookie->read($request);
        $id = is_string($raw) ? TokenCodec::parse($raw, 'cs1') : null;
        if ($id === null) throw self::unauthorized();
        $stmt = $this->database->connection()->prepare(<<<'SQL'
SELECT s.id::text AS session_id, s.secret_hash, s.csrf_hash, s.mfa_verified,
       u.id::text AS user_id, u.email, u.name, u.is_active, u.two_factor_enabled
FROM auth_sessions s JOIN users u ON u.id = s.user_id
WHERE s.id = :id AND s.revoked_at IS NULL AND s.expires_at > now()
SQL);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !self::dbBool($row['is_active']) || (self::dbBool($row['two_factor_enabled']) && !self::dbBool($row['mfa_verified'])) || !TokenCodec::validHash((string) $row['secret_hash'], $raw)) {
            throw self::unauthorized();
        }
        $touch = $this->database->connection()->prepare("UPDATE auth_sessions SET last_seen_at = now() WHERE id = :id AND last_seen_at < now() - interval '5 minutes'");
        $touch->execute(['id' => $id]);
        return ['mode' => 'session', 'id' => $id, 'user' => $this->publicUser($row, 'user_id'), 'scopes' => [], 'csrf_hash' => (string) $row['csrf_hash'], 'mfa_verified' => self::dbBool($row['mfa_verified'])];
    }

    /** @return array{mode:string,user:array<string,string>,id:string,scopes:list<string>} */
    private function bearer(string $raw, array $required): array
    {
        $id = TokenCodec::parse($raw, 'at1');
        if ($id === null) throw self::unauthorized();
        $stmt = $this->database->connection()->prepare(<<<'SQL'
SELECT t.id::text AS token_id, t.token_hash, t.scopes::text, t.mfa_verified, u.id::text AS user_id, u.email, u.name, u.is_active, u.two_factor_enabled
FROM api_tokens t JOIN users u ON u.id = t.user_id
WHERE t.id = :id AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
SQL);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !self::dbBool($row['is_active']) || (self::dbBool($row['two_factor_enabled']) && !self::dbBool($row['mfa_verified'])) || !TokenCodec::validHash((string) $row['token_hash'], $raw)) {
            throw self::unauthorized();
        }
        $scopes = json_decode((string) $row['scopes'], true);
        if (!is_array($scopes) || !array_is_list($scopes)) throw self::unauthorized();
        foreach ($scopes as $scope) { if (!is_string($scope)) throw self::unauthorized(); }
        if (array_diff($scopes, self::TOKEN_SCOPES)) throw self::unauthorized();
        ApiToken::authorize($scopes, $required);
        $this->database->connection()->prepare("UPDATE api_tokens SET last_used_at = now() WHERE id = :id AND (last_used_at IS NULL OR last_used_at < now() - interval '5 minutes')")->execute(['id' => $id]);
        return ['mode' => 'token', 'id' => $id, 'user' => $this->publicUser($row, 'user_id'), 'scopes' => $scopes];
    }

    /** @param array<string,mixed> $principal */
    public function requireCsrf(HttpRequest $request, array $principal): void
    {
        if (($principal['mode'] ?? '') !== 'session') throw self::unauthorized();
        $token = $request->header('X-CSRF-Token', '');
        CsrfProtection::verify((string) ($principal['csrf_hash'] ?? ''), $token);
        $this->requireSameOrigin($request);
    }

    /** Cross-site browser requests are rejected even before user credentials are checked. */
    public function requireSameOrigin(HttpRequest $request): void
    {
        if ((string) $this->config->get('app.env', 'production') === 'production'
            && !in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)) {
            throw new HttpException(403, 'HTTPS is required for authentication.', 'https_required');
        }
        if (strtolower($request->header('Sec-Fetch-Site', '')) === 'cross-site') {
            throw new HttpException(403, 'Cross-origin request rejected.', 'origin_forbidden');
        }
        $origin = $request->header('Origin') ?? $request->header('Referer');
        if ($origin === null) return; // Non-browser JSON clients do not send an Origin.
        $expected = parse_url((string) $this->config->get('app.url', ''));
        $actual = parse_url($origin);
        $port = static fn (array $url): int => (int) ($url['port'] ?? (($url['scheme'] ?? '') === 'https' ? 443 : 80));
        if (!is_array($expected) || !is_array($actual)
            || strtolower($expected['scheme'] ?? '') !== strtolower($actual['scheme'] ?? '')
            || strtolower($expected['host'] ?? '') !== strtolower($actual['host'] ?? '')
            || $port($expected) !== $port($actual)
            || isset($actual['user']) || isset($actual['pass'])) {
            throw new HttpException(403, 'Cross-origin request rejected.', 'origin_forbidden');
        }
    }

    /** @param array<string,mixed> $principal */
    public function rotateCsrf(array $principal): string
    {
        $this->onlySession($principal);
        $csrf = self::randomSecret();
        $stmt = $this->database->connection()->prepare('UPDATE auth_sessions SET csrf_hash = :hash WHERE id = :id AND revoked_at IS NULL AND expires_at > now()');
        $stmt->execute(['hash' => TokenCodec::digest($csrf), 'id' => $principal['id']]);
        if ($stmt->rowCount() !== 1) throw self::unauthorized();
        return $csrf;
    }

    /** Rotate the password and invalidate every session and API token atomically. */
    public function changePassword(array $principal, string $current, string $replacement): void
    {
        $this->onlySession($principal);
        if (strlen($current) > 1024 || strlen($replacement) < 14 || strlen($replacement) > 1024) {
            throw new HttpException(422, 'New password must contain between 14 and 1024 bytes.', 'validation_failed');
        }
        $this->database->transaction(function (PDO $pdo) use ($principal, $current, $replacement): void {
            $this->assertLiveSession($pdo, $principal);
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id AND is_active = true FOR UPDATE');
            $stmt->execute(['id' => $principal['user']['id']]);
            $oldHash = $stmt->fetchColumn();
            if (!is_string($oldHash) || !password_verify($current, $oldHash)) {
                throw new HttpException(403, 'Current password is incorrect.', 'invalid_current_password');
            }
            if (password_verify($replacement, $oldHash)) {
                throw new HttpException(422, 'New password must differ from the current password.', 'validation_failed');
            }
            $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $update->execute(['hash' => password_hash($replacement, PASSWORD_ARGON2ID), 'id' => $principal['user']['id']]);
            $pdo->prepare('UPDATE auth_sessions SET revoked_at = now() WHERE user_id = :id AND revoked_at IS NULL')->execute(['id' => $principal['user']['id']]);
            $pdo->prepare('UPDATE api_tokens SET revoked_at = now() WHERE user_id = :id AND revoked_at IS NULL')->execute(['id' => $principal['user']['id']]);
            $this->audit($pdo, $principal['user']['id'], 'auth.password.changed', 'success', 'user', $principal['user']['id']);
        });
    }

    /** @param array<string,mixed> $principal */
    public function logout(array $principal): void
    {
        $this->onlySession($principal);
        $this->database->transaction(function (PDO $pdo) use ($principal): void {
            $stmt = $pdo->prepare('UPDATE auth_sessions SET revoked_at = now() WHERE id = :id AND user_id = :user AND revoked_at IS NULL');
            $stmt->execute(['id' => $principal['id'], 'user' => $principal['user']['id']]);
            if ($stmt->rowCount() !== 1) throw self::unauthorized();
            $this->audit($pdo, $principal['user']['id'], 'auth.logout', 'success', 'session', $principal['id']);
        });
    }

    /** @param array<string,mixed> $principal @return list<array<string,mixed>> */
    public function sessions(array $principal): array
    {
        $stmt = $this->database->connection()->prepare("SELECT id::text, created_at::text, last_seen_at::text, expires_at::text FROM auth_sessions WHERE user_id = :user AND revoked_at IS NULL AND expires_at > now() ORDER BY created_at DESC LIMIT 100");
        $stmt->execute(['user' => $principal['user']['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => [
            'id' => $r['id'], 'created_at' => $r['created_at'], 'last_seen_at' => $r['last_seen_at'],
            'expires_at' => $r['expires_at'], 'current' => $r['id'] === $principal['id'],
        ], $rows);
    }

    /** @param array<string,mixed> $principal */
    public function revokeSession(array $principal, string $sessionId): bool
    {
        $this->onlySession($principal);
        return $this->database->transaction(function (PDO $pdo) use ($principal, $sessionId): bool {
            $this->assertLiveSession($pdo, $principal);
            $stmt = $pdo->prepare('UPDATE auth_sessions SET revoked_at = now() WHERE id = :id AND user_id = :user AND revoked_at IS NULL');
            $stmt->execute(['id' => $sessionId, 'user' => $principal['user']['id']]);
            if ($stmt->rowCount() !== 1) throw new HttpException(404, 'Session not found.', 'not_found');
            $this->audit($pdo, $principal['user']['id'], 'auth.session.revoked', 'success', 'session', $sessionId);
            return $sessionId === $principal['id'];
        });
    }

    /** @param array<string,mixed> $principal @param list<string> $scopes @return array<string,mixed> */
    public function createToken(array $principal, string $name, array $scopes, int $expiresIn): array
    {
        $this->onlySession($principal);
        if ($name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name)
            || $expiresIn < 300 || $expiresIn > self::MAX_TOKEN_SECONDS
            || count($scopes) < 1) {
            throw new HttpException(422, 'Invalid API token settings.', 'validation_failed');
        }
        $scopes = ApiToken::validateScopes($scopes);
        return $this->database->transaction(function (PDO $pdo) use ($principal, $name, $scopes, $expiresIn): array {
            $this->assertLiveSession($pdo, $principal);
            $lock = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $principal['user']['id']]);
            if (!$lock->fetchColumn()) throw self::unauthorized();
            $count = $pdo->prepare('SELECT count(*) FROM api_tokens WHERE user_id = :user AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now())');
            $count->execute(['user' => $principal['user']['id']]);
            if ((int) $count->fetchColumn() >= 25) throw new HttpException(409, 'Too many active API tokens.', 'token_limit');
            $issued = TokenCodec::issue('at1');
            $stmt = $pdo->prepare("INSERT INTO api_tokens (id,user_id,name,token_hash,scopes,expires_at,mfa_verified) VALUES (:id,:user,:name,:hash,CAST(:scopes AS jsonb),now() + CAST(:ttl AS integer) * interval '1 second',CAST(:mfa AS boolean)) RETURNING created_at::text, expires_at::text");
            $stmt->execute([
                'id' => $issued['id'], 'user' => $principal['user']['id'], 'name' => $name,
                'hash' => $issued['hash'], 'scopes' => json_encode(array_values($scopes), JSON_THROW_ON_ERROR), 'ttl' => $expiresIn, 'mfa' => ($principal['mfa_verified'] ?? false) ? 'true' : 'false',
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new RuntimeException('Could not issue API token.');
            $this->audit($pdo, $principal['user']['id'], 'auth.token.created', 'success', 'api_token', $issued['id']);
            return ['id' => $issued['id'], 'name' => $name, 'scopes' => $scopes, 'created_at' => $row['created_at'],
                'expires_at' => $row['expires_at'], 'token' => $issued['credential']];
        });
    }

    /** @param array<string,mixed> $principal @return list<array<string,mixed>> */
    public function tokens(array $principal): array
    {
        $stmt = $this->database->connection()->prepare("SELECT id::text, name, scopes::text, created_at::text, expires_at::text, last_used_at::text FROM api_tokens WHERE user_id = :user AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now()) ORDER BY created_at DESC LIMIT 100");
        $stmt->execute(['user' => $principal['user']['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'scopes' => json_decode((string) $r['scopes'], true),
            'created_at' => $r['created_at'], 'expires_at' => $r['expires_at'], 'last_used_at' => $r['last_used_at'],
        ], $rows);
    }

    /** @param array<string,mixed> $principal */
    public function revokeToken(array $principal, string $id): void
    {
        $this->onlySession($principal);
        $this->database->transaction(function (PDO $pdo) use ($principal, $id): void {
            $this->assertLiveSession($pdo, $principal);
            $stmt = $pdo->prepare('UPDATE api_tokens SET revoked_at = now() WHERE id = :id AND user_id = :user AND revoked_at IS NULL');
            $stmt->execute(['id' => $id, 'user' => $principal['user']['id']]);
            if ($stmt->rowCount() !== 1) throw new HttpException(404, 'API token not found.', 'not_found');
            $this->audit($pdo, $principal['user']['id'], 'auth.token.revoked', 'success', 'api_token', $id);
        });
    }

    /** @param array<string,mixed> $principal */
    private function onlySession(array $principal): void
    {
        if (($principal['mode'] ?? '') !== 'session') throw self::unauthorized();
    }

    /** @param array<string,mixed> $principal */
    private function assertLiveSession(PDO $pdo, array $principal): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM auth_sessions WHERE id = :id AND user_id = :user AND revoked_at IS NULL AND expires_at > now() FOR UPDATE');
        $stmt->execute(['id' => $principal['id'], 'user' => $principal['user']['id']]);
        if (!$stmt->fetchColumn()) throw self::unauthorized();
    }

    /** @param array<string,mixed> $row @return array<string,string> */
    private function publicUser(array $row, string $idKey = 'id'): array
    {
        return ['id' => (string) $row[$idKey], 'email' => (string) $row['email'], 'name' => (string) $row['name']];
    }

    private function appKey(): string
    {
        $key = EnvironmentLoader::getString('APP_KEY');
        if (str_starts_with($key, 'base64:')) $key = base64_decode(substr($key, 7), true) ?: '';
        if (strlen($key) < 32 || strlen(count_chars($key, 3)) < 8) throw new RuntimeException('Application encryption key is not configured securely.');
        return $key;
    }

    private static function randomSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function dbBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private static function unauthorized(): HttpException
    {
        return new HttpException(401, 'Authentication required or credentials invalid.', 'unauthorized', ['WWW-Authenticate' => 'Bearer realm="CajeerServers"']);
    }

    private function audit(PDO $pdo, ?string $user, string $action, string $result, ?string $target = null, ?string $targetId = null): void
    {
        $stmt = $pdo->prepare('INSERT INTO audit_log (actor_type, actor_id, action, target_type, target_id, result) VALUES (:type,:user,:action,:target,:target_id,:result)');
        $stmt->execute(['type' => $user === null ? 'anonymous' : 'user', 'user' => $user,
            'action' => $action, 'target' => $target, 'target_id' => $targetId, 'result' => $result]);
    }
}
