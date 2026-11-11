<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Database\DatabaseManager;
use PDO;
use RuntimeException;

/** Postgres-backed key verifier and transactional encrypted storage probe. No plaintext HTTP interface. */
final class CredentialsVault
{
    private const CONTEXT_ID = '00000000-0000-0000-0000-000000000001';
    private const WORKSPACE_ID = '00000000-0000-0000-0000-000000000002';
    private const CONTEXT_TYPE = 'vault_key_verifier';
    private const CHALLENGE = 'cajeerservers:vault:verifier:v1';

    public function __construct(private readonly DatabaseManager $database, private readonly VaultCrypto $crypto, private readonly VaultMasterKey $keys) {}

    /** One-time enrollment, protected by PostgreSQL transaction-level advisory lock. */
    public function initialize(): array
    {
        $this->postgres();
        $keyId = $this->keys->keyId();
        return $this->database->transaction(function (PDO $pdo) use ($keyId): array {
            $pdo->query('SELECT pg_advisory_xact_lock(1128352282, 1397904981)');
            $present = $pdo->query('SELECT key_id FROM vault_key_verifiers WHERE id=1 FOR UPDATE')->fetchColumn();
            if ($present !== false) throw new RuntimeException('Vault is already initialized; never overwrite the verifier.');
            $blob = $this->crypto->seal(self::CHALLENGE, self::CONTEXT_ID, self::WORKSPACE_ID, self::CONTEXT_TYPE);
            $stmt = $pdo->prepare('INSERT INTO vault_key_verifiers(id,key_id,verifier) VALUES(1,:kid,decode(:blob,\'hex\'))');
            $stmt->execute(['kid' => $keyId, 'blob' => bin2hex($blob)]);
            return ['initialized' => true, 'key_id' => $keyId];
        });
    }

    /** Fails closed on missing migration, wrong key, damaged verifier, or unavailable PostgreSQL. */
    public function check(): array
    {
        $pdo = $this->postgres();
        $row = $pdo->query('SELECT key_id, encode(verifier,\'hex\') AS blob FROM vault_key_verifiers WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Vault has not been initialized.');
        $keyId = $this->keys->keyId();
        if (!is_string($row['key_id']) || !hash_equals($row['key_id'], $keyId)) {
            throw new RuntimeException('Vault key does not match initialized database.');
        }
        $blob = hex2bin((string) $row['blob']);
        if ($blob === false || !hash_equals(self::CHALLENGE, $this->crypto->open($blob, self::CONTEXT_ID, self::WORKSPACE_ID, self::CONTEXT_TYPE))) {
            throw new RuntimeException('Vault verifier authentication failed.');
        }
        return ['ok' => true, 'key_id' => $keyId];
    }

    /** On actual PostgreSQL, performs real encrypted INSERT/read/decrypt and ALWAYS rolls back. */
    public function probe(): array
    {
        $this->check();
        $pdo = $this->postgres();
        if ($pdo->inTransaction()) throw new RuntimeException('Vault probe cannot run inside another transaction.');
        $id = self::uuidV4();
        $plaintext = random_bytes(48);
        try {
            $cipher = $this->crypto->seal($plaintext, $id, self::WORKSPACE_ID, 'vault_probe');
            if (!$pdo->beginTransaction()) throw new RuntimeException('Could not start Vault storage probe transaction.');
            $insert = $pdo->prepare("INSERT INTO credentials(id,name,type,encrypted_payload,metadata) VALUES(CAST(:id AS uuid),'Vault ephemeral probe','vault_probe',decode(:blob,'hex'),'{}'::jsonb)");
            $insert->execute(['id' => $id, 'blob' => bin2hex($cipher)]);
            $select = $pdo->prepare("SELECT encode(encrypted_payload,'hex') FROM credentials WHERE id=CAST(:id AS uuid)");
            $select->execute(['id' => $id]);
            $stored = $select->fetchColumn();
            if (!is_string($stored) || !hash_equals($plaintext, $this->crypto->open(hex2bin($stored) ?: '', $id, self::WORKSPACE_ID, 'vault_probe'))) {
                throw new RuntimeException('Vault PostgreSQL encrypted round-trip failed.');
            }
            return ['ok' => true, 'encrypted_storage' => true];
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            sodium_memzero($plaintext);
        }
    }

    private function postgres(): PDO
    {
        if ($this->database->driver() !== 'pgsql') throw new RuntimeException('Vault 0.3.1 requires PostgreSQL.');
        return $this->database->connection();
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $s = bin2hex($bytes);
        return substr($s,0,8).'-'.substr($s,8,4).'-'.substr($s,12,4).'-'.substr($s,16,4).'-'.substr($s,20);
    }
}
