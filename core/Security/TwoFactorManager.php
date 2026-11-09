<?php
declare(strict_types=1);
namespace Cajeer\Servers\Security;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** RFC 6238 TOTP with encrypted-at-rest secrets, anti-replay counters and one-use recovery codes. */
final class TwoFactorManager
{
    public function __construct(private readonly DatabaseManager $db, private readonly SecretBox $secrets,
                                private readonly AuditLogger $audit, private readonly RateLimiter $limiter) {}

    public static function base32(string $binary): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = 0; $value = 0; $result = '';
        foreach (str_split($binary) as $byte) {
            $value = ($value << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= $alphabet[($value >> $bits) & 31];
            }
            $value &= (1 << $bits) - 1;
        }
        if ($bits > 0) $result .= $alphabet[($value << (5 - $bits)) & 31];
        return $result;
    }

    public static function decode32(string $text): string
    {
        $text = strtoupper($text);
        if ($text === '' || preg_match('/^[A-Z2-7]+$/D', $text) !== 1) throw new \InvalidArgumentException('Invalid base32 secret.');
        $bits = 0; $value = 0; $binary = '';
        foreach (str_split($text) as $char) {
            $n = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char);
            $value = ($value << 5) | $n;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $binary .= chr(($value >> $bits) & 255);
            }
            $value &= (1 << $bits) - 1;
        }
        return $binary;
    }

    public static function totp(string $secret, int $at): string
    {
        $counter = intdiv($at, 30);
        if ($counter < 0) throw new \InvalidArgumentException('Invalid TOTP time.');
        $digest = hash_hmac('sha1', pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff), $secret, true);
        $offset = ord($digest[19]) & 15;
        $part = unpack('N', substr($digest, $offset, 4));
        return str_pad((string) (($part[1] & 0x7fffffff) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function throttle(string $kind, string $userId, int $limit): void
    {
        $ok = $this->db->transaction(fn (PDO $pdo): bool => $this->limiter->hit($pdo, $kind, $userId, $limit, 900));
        if (!$ok) throw new HttpException(429, 'Too many security attempts.', 'rate_limited', ['Retry-After' => '900']);
    }

    /** @param array<string,mixed> $principal @return array{secret:string,otpauth_url:string} */
    public function enroll(array $principal, string $password): array
    {
        $userId = $principal['user']['id'];
        $this->throttle('mfa.enroll', $userId, 5);
        return $this->db->transaction(function (PDO $pdo) use ($principal, $userId, $password): array {
            $user = $pdo->prepare('SELECT password_hash,two_factor_enabled,email FROM users WHERE id=:id AND is_active=true FOR UPDATE');
            $user->execute(['id' => $userId]);
            $row = $user->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !password_verify($password, (string) $row['password_hash'])) throw new HttpException(403, 'Invalid current password.', 'invalid_password');
            if (in_array($row['two_factor_enabled'], [true, 't', '1', 1], true)) throw new HttpException(409, '2FA is already enabled.', 'mfa_enabled');
            $secret = self::base32(random_bytes(20));
            $cipher = $this->secrets->seal($secret, 'mfa:' . $userId);
            $stmt = $pdo->prepare('INSERT INTO user_mfa (user_id,secret_ciphertext) VALUES (:id,decode(:cipher,\'hex\')) ON CONFLICT (user_id) DO UPDATE SET secret_ciphertext=EXCLUDED.secret_ciphertext,enabled_at=NULL,last_counter=NULL');
            $stmt->bindValue(':id', $userId);
            $stmt->bindValue(':cipher', bin2hex($cipher));
            $stmt->execute();
            $this->audit->record($pdo, $userId, 'security.mfa.enrollment_started', 'success', 'user', $userId);
            $label = rawurlencode('CajeerServers:' . (string) $row['email']);
            return ['secret' => $secret, 'otpauth_url' => 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=CajeerServers&algorithm=SHA1&digits=6&period=30'];
        });
    }

    /** @param array<string,mixed> $principal @return list<string> */
    public function confirm(array $principal, string $code): array
    {
        $id = $principal['user']['id'];
        $this->throttle('mfa.confirm', $id, 10);
        return $this->db->transaction(function (PDO $pdo) use ($principal, $id, $code): array {
            $stmt = $pdo->prepare('SELECT secret_ciphertext,enabled_at,last_counter FROM user_mfa WHERE user_id=:id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['enabled_at'] !== null) throw new HttpException(409, 'No pending 2FA enrollment.', 'mfa_not_pending');
            if (!$this->consumeOtp($pdo, $id, $row, $code)) throw new HttpException(403, 'Invalid verification code.', 'invalid_mfa_code');
            $pdo->prepare('UPDATE user_mfa SET enabled_at=now() WHERE user_id=:id')->execute(['id' => $id]);
            $pdo->prepare('UPDATE users SET two_factor_enabled=true WHERE id=:id')->execute(['id' => $id]);
            $pdo->prepare('UPDATE auth_sessions SET revoked_at=now() WHERE user_id=:id AND id<>:session AND revoked_at IS NULL')->execute(['id' => $id, 'session' => $principal['id']]);
            $pdo->prepare('UPDATE auth_sessions SET mfa_verified=true WHERE id=:session AND user_id=:id AND revoked_at IS NULL')->execute(['id' => $id, 'session' => $principal['id']]);
            $pdo->prepare('UPDATE api_tokens SET revoked_at=now() WHERE user_id=:id AND revoked_at IS NULL')->execute(['id' => $id]);
            $codes = $this->newRecoveryCodes($pdo, $id);
            $this->audit->record($pdo, $id, 'security.mfa.enabled', 'success', 'user', $id);
            return $codes;
        });
    }

    /** Must be called in the SAME login transaction holding users row FOR UPDATE. */
    public function verifyLogin(PDO $pdo, string $userId, ?string $code): bool
    {
        if (!is_string($code) || strlen($code) > 64) return false;
        $stmt = $pdo->prepare('SELECT secret_ciphertext,enabled_at,last_counter FROM user_mfa WHERE user_id=:id FOR UPDATE');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['enabled_at'] === null) return false;
        return $this->consumeOtp($pdo, $userId, $row, $code) || $this->consumeRecovery($pdo, $userId, $code);
    }

    /** @param array<string,mixed> $principal */
    public function disable(array $principal, string $password, string $code): void
    {
        $id = $principal['user']['id'];
        $this->throttle('mfa.disable', $id, 10);
        $this->db->transaction(function (PDO $pdo) use ($id, $password, $code): void {
            $stmt = $pdo->prepare('SELECT password_hash,two_factor_enabled FROM users WHERE id=:id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user) || !password_verify($password, (string) $user['password_hash'])) throw new HttpException(403, 'Invalid current password.', 'invalid_password');
            if (!in_array($user['two_factor_enabled'], [true, 't', '1', 1], true)) throw new HttpException(409, '2FA is not enabled.', 'mfa_not_enabled');
            if (!$this->verifyLogin($pdo, $id, $code)) throw new HttpException(403, 'Invalid verification code.', 'invalid_mfa_code');
            $pdo->prepare('DELETE FROM user_mfa WHERE user_id=:id')->execute(['id' => $id]);
            $pdo->prepare('UPDATE users SET two_factor_enabled=false WHERE id=:id')->execute(['id' => $id]);
            $pdo->prepare('UPDATE auth_sessions SET revoked_at=now() WHERE user_id=:id AND revoked_at IS NULL')->execute(['id' => $id]);
            $pdo->prepare('UPDATE api_tokens SET revoked_at=now() WHERE user_id=:id AND revoked_at IS NULL')->execute(['id' => $id]);
            $this->audit->record($pdo, $id, 'security.mfa.disabled', 'success', 'user', $id);
        });
    }

    /** @param array<string,mixed> $principal @return array{enabled:bool,pending:bool,recovery_codes_left:int} */
    public function status(array $principal): array
    {
        $id = $principal['user']['id'];
        $stmt = $this->db->connection()->prepare('SELECT enabled_at FROM user_mfa WHERE user_id=:id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $enabled = is_array($row) && $row['enabled_at'] !== null;
        $count = $this->db->connection()->prepare('SELECT count(*) FROM user_mfa_recovery WHERE user_id=:id AND used_at IS NULL');
        $count->execute(['id' => $id]);
        return ['enabled' => $enabled, 'pending' => is_array($row) && !$enabled, 'recovery_codes_left' => (int) $count->fetchColumn()];
    }

    /** @param array<string,mixed> $record */
    private function consumeOtp(PDO $pdo, string $id, array $record, string $code): bool
    {
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) return false;
        $secret = self::decode32($this->secrets->open($this->pgBytea($record['secret_ciphertext']), 'mfa:' . $id));
        $current = intdiv(time(), 30);
        $last = $record['last_counter'] === null ? -1 : (int) $record['last_counter'];
        foreach ([$current - 1, $current, $current + 1] as $counter) {
            if ($counter <= $last || $counter < 0) continue;
            if (hash_equals(self::totp($secret, $counter * 30), $code)) {
                $pdo->prepare('UPDATE user_mfa SET last_counter=:counter WHERE user_id=:id')->execute(['counter' => $counter, 'id' => $id]);
                return true;
            }
        }
        return false;
    }

    private function consumeRecovery(PDO $pdo, string $id, string $code): bool
    {
        $code = strtoupper(str_replace('-', '', $code));
        if (preg_match('/^[A-Z2-7]{16}$/D', $code) !== 1) return false;
        $hash = $this->secrets->digest($code, 'mfa-recovery:' . $id);
        $stmt = $pdo->prepare('UPDATE user_mfa_recovery SET used_at=now() WHERE user_id=:id AND code_hash=:hash AND used_at IS NULL');
        $stmt->execute(['id' => $id, 'hash' => $hash]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<string> */
    private function newRecoveryCodes(PDO $pdo, string $id): array
    {
        $pdo->prepare('DELETE FROM user_mfa_recovery WHERE user_id=:id')->execute(['id' => $id]);
        $insert = $pdo->prepare('INSERT INTO user_mfa_recovery (user_id,code_hash) VALUES (:id,:hash)');
        $codes = [];
        for ($i = 0; $i < 10; ++$i) {
            $code = self::base32(random_bytes(10));
            $insert->execute(['id' => $id, 'hash' => $this->secrets->digest($code, 'mfa-recovery:' . $id)]);
            $codes[] = substr($code, 0, 8) . '-' . substr($code, 8);
        }
        return $codes;
    }

    private function pgBytea(mixed $value): string
    {
        if (is_resource($value)) return stream_get_contents($value) ?: '';
        if (!is_string($value)) throw new \RuntimeException('Invalid encrypted secret storage.');
        // PostgreSQL bytea is hex-encoded by PDO when retrieved as a string.
        if (str_starts_with($value, '\\x')) {
            $bytes = hex2bin(substr($value, 2));
            if ($bytes === false) throw new \RuntimeException('Invalid binary secret encoding.');
            return $bytes;
        }
        return $value;
    }
}
