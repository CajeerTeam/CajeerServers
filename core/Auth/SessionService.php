<?php
declare(strict_types=1);

namespace Cajeer\Servers\Auth;

use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\TwoFactorManager;
use PDO;

/** Shared local session lifecycle. Provider credentials never become session credentials. */
final class SessionService
{
    public const LIFETIME = 43200;

    public function __construct(private readonly SessionCookie $cookie, private readonly ?TwoFactorManager $twoFactor) {}

    /** The caller must have verified the primary credential and locked the user row
     * in the same transaction. Local MFA is always enforced, including external logins.
     * Returning null permits the caller to commit rate-limit and failure audit records.
     */
    public function issue(PDO $pdo, array $user, ?string $mfaCode, string $key, ?string $identityId = null): ?array
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Session issuance requires a transaction.');
        // Re-read the authoritative account under lock; never trust caller-supplied MFA flags.
        $lock=$pdo->prepare('SELECT id::text,email,name,is_active,two_factor_enabled FROM users WHERE id=:id FOR UPDATE');
        $lock->execute(['id'=>$user['id']]);
        $user=$lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user) || !self::dbBool($user['is_active'])) return null;
        if ($identityId !== null) {
            $binding=$pdo->prepare('SELECT 1 FROM auth_external_identities WHERE id=:id AND user_id=:user AND enabled FOR UPDATE');
            $binding->execute(['id'=>$identityId,'user'=>$user['id']]);
            if (!$binding->fetchColumn()) return null;
        }
        $mfa = self::dbBool($user['two_factor_enabled'] ?? false);
        if ($mfa && ($this->twoFactor === null || !$this->twoFactor->verifyLogin($pdo, (string) $user['id'], $mfaCode))) return null;
        $method = $identityId === null ? 'password' : 'external';
        $issued = TokenCodec::issue('cs1');
        $csrf = TokenCodec::csrf($issued['credential'], $key);
        $save = $pdo->prepare(<<<'SQL'
INSERT INTO auth_sessions (id,user_id,secret_hash,csrf_hash,expires_at,mfa_verified,auth_method,external_identity_id,authenticated_at)
VALUES (:id,:user,:secret,:csrf,now() + CAST(:ttl AS integer) * interval '1 second',:mfa,:method,:identity,now())
SQL);
        $save->execute(['id'=>$issued['id'], 'user'=>$user['id'], 'secret'=>$issued['hash'], 'csrf'=>TokenCodec::digest($csrf),
            'ttl'=>self::LIFETIME, 'mfa'=>$mfa ? 'true' : 'false', 'method'=>$method, 'identity'=>$identityId]);
        $audit = $pdo->prepare("INSERT INTO audit_log (actor_type,actor_id,action,target_type,target_id,result,payload) VALUES ('user',:user,'auth.login.succeeded','session',:id,'success',CAST(:payload AS jsonb))");
        $audit->execute(['user'=>$user['id'], 'id'=>$issued['id'], 'payload'=>json_encode(['auth_method'=>$method,'mfa_verified'=>$mfa], JSON_THROW_ON_ERROR)]);
        return ['user'=>self::publicUser($user), 'cookie'=>$this->cookie->set($issued['credential']), 'csrf_token'=>$csrf, 'expires_in'=>self::LIFETIME];
    }

    public function authenticate(PDO $pdo, HttpRequest $request): array
    {
        $raw = $this->cookie->read($request);
        $id = is_string($raw) ? TokenCodec::parse($raw, 'cs1') : null;
        if ($id === null) throw self::unauthorized();
        $stmt = $pdo->prepare(<<<'SQL'
SELECT s.id::text AS session_id,s.secret_hash,s.csrf_hash,s.mfa_verified,s.auth_method,s.authenticated_at::text,
       u.id::text AS user_id,u.email,u.name,u.is_active,u.two_factor_enabled
FROM auth_sessions s JOIN users u ON u.id=s.user_id
WHERE s.id=:id AND s.revoked_at IS NULL AND s.expires_at > clock_timestamp()
AND (s.external_identity_id IS NULL OR EXISTS (SELECT 1 FROM auth_external_identities e WHERE e.id=s.external_identity_id AND e.enabled))
SQL);
        $stmt->execute(['id'=>$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !self::dbBool($row['is_active']) || (self::dbBool($row['two_factor_enabled']) && !self::dbBool($row['mfa_verified'])) || !TokenCodec::validHash((string)$row['secret_hash'], $raw)) throw self::unauthorized();
        $pdo->prepare("UPDATE auth_sessions SET last_seen_at=now() WHERE id=:id AND revoked_at IS NULL AND expires_at>clock_timestamp() AND last_seen_at<now()-interval '5 minutes'")->execute(['id'=>$id]);
        return ['mode'=>'session','id'=>$id,'user'=>self::publicUser($row,'user_id'),'scopes'=>[], 'csrf_hash'=>(string)$row['csrf_hash'],
            'mfa_verified'=>self::dbBool($row['mfa_verified']), 'auth_level'=>self::dbBool($row['mfa_verified']) ? 'mfa' : 'single_factor', 'auth_method'=>$row['auth_method'],'authenticated_at'=>$row['authenticated_at']];
    }

    /** Lock in user -> session order; a stale principal cannot mutate security settings. */
    public static function assertLive(PDO $pdo, array $principal): void
    {
        if (($principal['mode'] ?? null) !== 'session' || !isset($principal['id'],$principal['user']['id'])) throw self::unauthorized();
        if (!$pdo->inTransaction()) throw new \LogicException('Session security checks require a transaction.');
        $stmt=$pdo->prepare('SELECT 1 FROM users WHERE id=:user AND is_active FOR UPDATE');
        $stmt->execute(['user'=>$principal['user']['id']]);
        if (!$stmt->fetchColumn()) throw self::unauthorized();
        $stmt=$pdo->prepare('SELECT 1 FROM auth_sessions s JOIN users u ON u.id=s.user_id WHERE s.id=:id AND s.user_id=:user AND s.revoked_at IS NULL AND s.expires_at>clock_timestamp() AND (NOT u.two_factor_enabled OR s.mfa_verified) AND (s.external_identity_id IS NULL OR EXISTS (SELECT 1 FROM auth_external_identities e WHERE e.id=s.external_identity_id AND e.enabled)) FOR UPDATE OF s');
        $stmt->execute(['id'=>$principal['id'],'user'=>$principal['user']['id']]);
        if (!$stmt->fetchColumn()) throw self::unauthorized();
    }

    public static function dbBool(mixed $value): bool { return in_array($value, [true,1,'1','t'], true); }
    public static function publicUser(array $row, string $idKey='id'): array { return ['id'=>(string)$row[$idKey],'email'=>(string)$row['email'],'name'=>(string)$row['name']]; }
    public static function unauthorized(): HttpException { return new HttpException(401,'Authentication required or credentials invalid.','unauthorized',['WWW-Authenticate'=>'Bearer realm="CajeerServers"']); }
}
