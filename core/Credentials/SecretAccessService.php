<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Credentials\BindingPolicy;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Security\RateLimiter;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Security\TwoFactorManager;
use PDO;
use RuntimeException;
use Throwable;

/**
 * One-use, MFA-step-up authorization for *internal* credential consumption.
 *
 * The only consumer API is a trusted PHP callback. Neither grants nor the
 * decrypted material are available in HTTP response bodies, queues or logs.
 * A grant is tied to the exact live session, actor, asset, credential, purpose
 * and credential version; it expires after 120 seconds and is consumed once.
 */
final class SecretAccessService
{
    private const TTL_SECONDS = 120;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryRepository $inventory,
        private readonly Rbac $rbac,
        private readonly TwoFactorManager $mfa,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
        private readonly CredentialsVault $vault,
        private readonly VaultCrypto $crypto,
    ) {}

    private static function uuid(mixed $value): string
    {
        return CredentialInput::uuid($value);
    }

    /** Accepted purposes are deliberately narrower than arbitrary binding labels. */
    public static function allowsPurpose(string $type, string $purpose): bool
    {
        return BindingPolicy::allows($type,$purpose);
    }

    /** @param array<string,mixed> $principal */
    private static function identity(array $principal): array
    {
        if (($principal['mode'] ?? null) !== 'session') {
            throw new HttpException(403, 'Active browser session required.', 'session_required');
        }
        return [self::uuid($principal['user']['id'] ?? null), self::uuid($principal['id'] ?? null)];
    }

    /** @return array{workspace:string,version:int,type:string} */
    private function binding(PDO $pdo, string $actor, string $asset, string $credential, string $purpose, bool $lock): array
    {
        $statement = $pdo->prepare(<<<'SQL'
SELECT c.workspace_id::text AS workspace, c.version, c.type
FROM inventory_asset_credential_bindings b
JOIN inventory_assets a ON a.id=b.asset_id AND a.workspace_id=b.workspace_id
JOIN credentials c ON c.id=b.credential_id AND c.workspace_id=b.workspace_id
JOIN inventory_credential_scopes sc ON sc.credential_id=c.id AND sc.workspace_id=c.workspace_id
WHERE b.asset_id=CAST(:asset AS uuid) AND b.credential_id=CAST(:credential AS uuid)
  AND b.purpose=:purpose AND a.deleted_at IS NULL
  AND c.vault_format='cjev1' AND c.state='active' AND c.deleted_at IS NULL
  AND EXISTS (SELECT 1 FROM workspace_members wm JOIN users u ON u.id=wm.user_id
              WHERE wm.workspace_id=b.workspace_id AND wm.user_id=CAST(:actor AS uuid)
                AND wm.role IN ('owner','manager') AND u.is_active=true)
SQL . ($lock ? ' FOR SHARE OF c, a, b' : ''));
        $statement->execute(['asset'=>$asset, 'credential'=>$credential, 'purpose'=>$purpose, 'actor'=>$actor]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !self::allowsPurpose((string)$row['type'], $purpose)) {
            throw new HttpException(403, 'Credential use is not authorized for this asset and purpose.', 'credential_use_denied');
        }
        return ['workspace'=>(string)$row['workspace'], 'version'=>(int)$row['version'], 'type'=>(string)$row['type']];
    }

    /** @param array<string,mixed> $principal */
    private function liveSession(PDO $pdo, array $principal): void
    {
        [$actor,$session] = self::identity($principal);
        $stmt=$pdo->prepare(<<<'SQL'
SELECT 1 FROM auth_sessions s JOIN users u ON u.id=s.user_id
WHERE s.id=CAST(:session AS uuid) AND s.user_id=CAST(:actor AS uuid)
 AND s.revoked_at IS NULL AND s.expires_at>now()
 AND s.mfa_verified=true AND u.two_factor_enabled=true AND u.is_active=true
FOR SHARE OF s,u
SQL);
        $stmt->execute(['session'=>$session, 'actor'=>$actor]);
        if (!$stmt->fetchColumn()) {
            throw new HttpException(403, 'Active MFA-enabled session required.', 'mfa_required');
        }
    }

    /**
     * Confirm password + fresh non-replayable 6-digit TOTP; issue a session-only
     * grant (no client-side bearer token) for the exact asset and purpose.
     *
     * @param array<string,mixed> $principal
     * @return array{authorized:bool,expires_in:int}
     */
    public function authorize(array $principal, string $asset, string $credential, string $purpose,
                              string $password, string $totp, ?string $requestId = null): array
    {
        [$actor,$session]=self::identity($principal);
        $asset=self::uuid($asset);
        $credential=self::uuid($credential);
        if (strlen($password) < 1 || strlen($password)>1024 || preg_match('/^[0-9]{6}$/D',$totp)!==1
            || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$purpose)!==1) {
            throw new HttpException(422,'Invalid authorization fields.','validation_failed');
        }
        // Must be a separate committed transaction, including unsuccessful attempts.
        $withinLimit=$this->db->transaction(fn(PDO $pdo):bool => $this->limiter->hit(
            $pdo, 'vault.stepup', $actor.':'.$session, 8, 900));
        if (!$withinLimit) throw new HttpException(429,'Too many authorization attempts.','rate_limited', ['Retry-After'=>'900']);
        try {
            $this->vault->check();
            return $this->db->transaction(function(PDO $pdo) use ($principal,$actor,$session,$asset,$credential,$purpose,$password,$totp,$requestId):array {
                $this->liveSession($pdo,$principal);
                $this->rbac->requirePermission($principal,'vault.credentials.use',$pdo);
                $binding=$this->binding($pdo,$actor,$asset,$credential,$purpose,true);
                $this->inventory->workspaceRole($pdo,$binding['workspace'],$actor,true);
                $stmt=$pdo->prepare('SELECT password_hash FROM users WHERE id=CAST(:actor AS uuid) AND is_active=true FOR SHARE');
                $stmt->execute(['actor'=>$actor]);
                $hash=$stmt->fetchColumn();
                // Compare fresh password AND fresh TOTP; recovery codes are not step-up factors.
                if (!is_string($hash) || !password_verify($password,$hash)
                    || !$this->mfa->verifyLogin($pdo,$actor,$totp)) {
                    throw new HttpException(403,'Step-up verification failed.','step_up_denied');
                }
                $stmt=$pdo->prepare(<<<'SQL'
INSERT INTO vault_use_grants(session_id,actor_id,asset_id,credential_id,workspace_id,purpose,credential_version,expires_at)
VALUES(CAST(:session AS uuid),CAST(:actor AS uuid),CAST(:asset AS uuid),CAST(:cred AS uuid),
       CAST(:ws AS uuid),:purpose,:version,now() + interval '120 seconds')
ON CONFLICT(session_id,asset_id,credential_id,purpose) DO UPDATE SET
 credential_version=EXCLUDED.credential_version,expires_at=EXCLUDED.expires_at,
 consumed_at=NULL,issued_at=clock_timestamp(),workspace_id=EXCLUDED.workspace_id
SQL);
                $stmt->execute(['session'=>$session,'actor'=>$actor,'asset'=>$asset,'cred'=>$credential,
                    'ws'=>$binding['workspace'],'purpose'=>$purpose,'version'=>$binding['version']]);
                $this->audit->record($pdo,$actor,'vault.credential.use_authorized','success','credential',$credential,
                    ['reason'=>'step_up'], $requestId);
                return ['authorized'=>true,'expires_in'=>self::TTL_SECONDS];
            });
        } catch (HttpException $failure) {
            $this->auditDenied($actor,$credential,$requestId);
            throw $failure;
        } catch (Throwable $failure) {
            // Do not disclose Vault status, cryptographic details, or DB exceptions.
            throw new HttpException(503,'Credential access temporarily unavailable.','vault_unavailable');
        }
    }

    private function auditDenied(string $actor,string $credential,?string $requestId):void
    {
        try {
            $this->db->transaction(fn(PDO $pdo) => $this->audit->record(
                $pdo,$actor,'vault.credential.use_denied','denied','credential',$credential,[], $requestId));
        } catch (Throwable) { /* Preserve original deny; never leak DB details. */ }
    }

    /**
     * Trusted, server-side consumption ONLY; never route this callable from HTTP.
     * The grant is spent and audited in PostgreSQL before plaintext is opened.
     * Callback is an application-owned adapter and MUST NEVER return or log secrets.
     * Handler return values are ignored; this method cannot return plaintext.
     *
     * @param array<string,mixed> $principal
     * @param callable(string,string):void $trustedConsumer (serialized secret, type)
     */
    public function consumeForAsset(array $principal, string $asset, string $credential,
                                   string $purpose, callable $trustedConsumer, ?string $requestId = null): void
    {
        $this->consumeInternal($principal, $asset, $credential, $purpose, $trustedConsumer, null, $requestId);
    }

    /**
     * Use a pending, encrypted revision solely for a trusted, read-only connector
     * validation. Requires the same MFA grant as active secret consumption and
     * atomically consumes that grant. Pending plaintext never reaches the HTTP API.
     *
     * @param array<string,mixed> $principal
     * @param callable(string,string):void $trustedConsumer
     */
    public function consumePendingForAsset(array $principal, string $asset, string $credential,
                                          string $purpose, int $revision, callable $trustedConsumer,
                                          ?string $requestId = null): void
    {
        if ($revision < 1) {
            throw new HttpException(422, 'Invalid pending revision.', 'validation_failed');
        }
        $this->consumeInternal($principal, $asset, $credential, $purpose, $trustedConsumer, $revision, $requestId);
    }

    /** Claim a fresh MFA grant without decrypting anything. Caller must be a trusted service. */
    public function consumeDelegation(array $principal,string $asset,string $credential,string $purpose,int $expectedVersion):void
    {
        [$actor,$session]=self::identity($principal);
        $asset=self::uuid($asset); $credential=self::uuid($credential);
        $this->vault->check();
        $this->db->transaction(function(PDO $pdo)use($principal,$actor,$session,$asset,$credential,$purpose,$expectedVersion):void {
            $binding=$this->claimGrant($pdo,$principal,$actor,$session,$asset,$credential,$purpose);
            if ($binding['version']!==$expectedVersion) throw new HttpException(409,'Credential version changed.','conflict');
            $this->audit->record($pdo,$actor,'vault.credential.delegated','success','credential',$credential,['reason'=>'service_grant']);
        });
    }

    /** Single-use claim shared by manual consumption and service delegation. */
    private function claimGrant(PDO $pdo,array $principal,string $actor,string $session,string $asset,string $credential,string $purpose):array
    {
            $this->liveSession($pdo,$principal);
            $this->rbac->requirePermission($principal,'vault.credentials.use',$pdo);
            $binding=$this->binding($pdo,$actor,$asset,$credential,$purpose,true);
            $this->inventory->workspaceRole($pdo,$binding['workspace'],$actor,true);
            $claim=$pdo->prepare(<<<'SQL'
UPDATE vault_use_grants SET consumed_at=clock_timestamp()
WHERE session_id=CAST(:session AS uuid) AND actor_id=CAST(:actor AS uuid)
 AND asset_id=CAST(:asset AS uuid) AND credential_id=CAST(:cred AS uuid)
 AND workspace_id=CAST(:ws AS uuid) AND purpose=:purpose AND credential_version=:version
 AND consumed_at IS NULL AND expires_at>clock_timestamp()
RETURNING credential_id
SQL);
            $claim->execute(['session'=>$session,'actor'=>$actor,'asset'=>$asset,'cred'=>$credential,
                'ws'=>$binding['workspace'],'purpose'=>$purpose,'version'=>$binding['version']]);
            if (!$claim->fetchColumn()) throw new HttpException(403,'Fresh step-up authorization required.','step_up_required');
        return $binding;
    }

    /** @param array<string,mixed> $principal */
    private function consumeInternal(array $principal, string $asset, string $credential,
                                     string $purpose, callable $trustedConsumer,
                                     ?int $pendingRevision, ?string $requestId): void
    {
        [$actor,$session]=self::identity($principal);
        $asset=self::uuid($asset); $credential=self::uuid($credential);
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$purpose)!==1) {
            throw new HttpException(422,'Invalid credential purpose.','validation_failed');
        }
        $this->vault->check();
        try {
            $material=$this->db->transaction(function(PDO $pdo) use ($principal,$actor,$session,$asset,$credential,$purpose,$pendingRevision,$requestId):array {
            $binding=$this->claimGrant($pdo,$principal,$actor,$session,$asset,$credential,$purpose);
            if ($pendingRevision === null) {
                $stmt=$pdo->prepare('SELECT encode(encrypted_payload,\'hex\') FROM credentials WHERE id=CAST(:id AS uuid)');
                $stmt->execute(['id'=>$credential]);
            } else {
                $stmt=$pdo->prepare("SELECT encode(encrypted_payload,'hex') FROM vault_credential_versions
                    WHERE credential_id=CAST(:id AS uuid) AND secret_version=:revision AND state='pending'
                    FOR SHARE");
                $stmt->execute(['id'=>$credential,'revision'=>$pendingRevision]);
            }
            $hex=$stmt->fetchColumn();
            if (!is_string($hex) || ($cipher=hex2bin($hex))===false) throw new RuntimeException('Invalid encrypted credential.');
            // Return ciphertext only across transaction boundary; no plaintext in DB/queue.
            $this->audit->record($pdo,$actor,'vault.credential.accessed','success','credential',$credential,[], $requestId);
            return ['cipher'=>$cipher,'ws'=>$binding['workspace'],'type'=>$binding['type']];
            });
        } catch (HttpException $failure) {
            $this->auditDenied($actor,$credential,$requestId);
            throw $failure;
        }
        $cipher=$material['cipher'];
        $plain='';
        try {
            $plain=$this->crypto->open($cipher,$credential,$material['ws'],$material['type']);
            // Deliberately ignore callback return value: never propagate a secret.
            $trustedConsumer($plain,$material['type']);
        } catch (Throwable) {
            try {
                $this->db->transaction(fn(PDO $pdo) => $this->audit->record(
                    $pdo,$actor,'vault.credential.operation_failed','failure','credential',$credential,[], $requestId));
            } catch (Throwable) { /* Avoid logging adapter exception or secret. */ }
            // Trusted adapter errors are not allowed to expose plaintext via logs.
            throw new RuntimeException('Internal credential operation failed.');
        } finally {
            if ($plain!=='') sodium_memzero($plain);
            sodium_memzero($cipher);
        }
    }
}
