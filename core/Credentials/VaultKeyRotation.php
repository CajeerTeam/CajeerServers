<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use PDO;
use RuntimeException;

/**
 * Offline, all-or-nothing rotation of the KEK: decrypt and rewrap DEKs only.
 * Requires a maintenance window and a securely staged new master key file.
 * After COMMIT operators must switch VAULT_MASTER_KEY_FILE to the staged file.
 * Until then check() fails closed, leaving the new key available for recovery.
 */
final class VaultKeyRotation
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly VaultCrypto $crypto,
        private readonly VaultMasterKey $current,
        private readonly CredentialsVault $vault,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{rotated:bool,from_key_id:string,to_key_id:string,credentials:int,versions:int,generation:int} */
    public function rotate(VaultMasterKey $next): array
    {
        $this->vault->check();
        $previousId = $this->current->keyId();
        $newId = $next->keyId();
        if (hash_equals($previousId, $newId)) {
            throw new RuntimeException('Next key must be distinct from the active master key.');
        }
        $nextCrypto = new VaultCrypto($next);
        // No secret material enters the audit log, CLI result or prepared SQL text.
        return $this->db->transaction(function (PDO $pdo) use ($next,$nextCrypto,$previousId,$newId): array {
            $pdo->query('SELECT pg_advisory_xact_lock(1128352282, 1397904981)')->fetchColumn();
            // These locks serialize other writes and readers during the crypto transition.
            $pdo->exec('LOCK TABLE credentials, vault_credential_versions, vault_use_grants, connector_webhook_sources, data_ingestion_sources IN ACCESS EXCLUSIVE MODE');
            $row = $pdo->query('SELECT key_id,generation FROM vault_key_state WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !hash_equals((string)$row['key_id'],$previousId)) {
                throw new RuntimeException('Vault key changed during offline rotation.');
            }
            $pdo->exec("SET LOCAL cajeer.vault_rewrap = 'on'");
            $live = $pdo->query("SELECT id::text,workspace_id::text,type,encode(encrypted_payload,'hex') AS blob
                FROM credentials WHERE vault_format='cjev1' ORDER BY id");
            $update = $pdo->prepare("UPDATE credentials SET encrypted_payload=decode(:blob,'hex') WHERE id=CAST(:id AS uuid)");
            $count = 0;
            while (($item = $live->fetch(PDO::FETCH_ASSOC)) !== false) {
                $blob = hex2bin((string)$item['blob']);
                if ($blob === false) throw new RuntimeException('Invalid credential ciphertext.');
                $rewrapped = $this->crypto->rewrap($blob,(string)$item['id'],(string)$item['workspace_id'],(string)$item['type'],$next);
                $this->verifyNewEnvelope($nextCrypto,$rewrapped,$item);
                $update->execute(['blob'=>bin2hex($rewrapped),'id'=>$item['id']]);
                if ($update->rowCount() !== 1) throw new RuntimeException('Credential disappeared during rotation.');
                $count++;
                sodium_memzero($blob); sodium_memzero($rewrapped);
            }
            // Historical envelopes, including discarded and retired versions, must also be recoverable.
            $versions = $pdo->query("SELECT v.credential_id::text AS id,v.secret_version,
                       c.workspace_id::text,c.type,encode(v.encrypted_payload,'hex') AS blob
                FROM vault_credential_versions v JOIN credentials c ON c.id=v.credential_id
                ORDER BY v.credential_id,v.secret_version");
            $replace = $pdo->prepare("UPDATE vault_credential_versions SET encrypted_payload=decode(:blob,'hex')
                WHERE credential_id=CAST(:id AS uuid) AND secret_version=:revision");
            $revisionCount = 0;
            while (($item = $versions->fetch(PDO::FETCH_ASSOC)) !== false) {
                $blob = hex2bin((string)$item['blob']);
                if ($blob === false) throw new RuntimeException('Invalid version ciphertext.');
                $rewrapped = $this->crypto->rewrap($blob,(string)$item['id'],(string)$item['workspace_id'],(string)$item['type'],$next);
                $this->verifyNewEnvelope($nextCrypto,$rewrapped,$item);
                $replace->execute(['blob'=>bin2hex($rewrapped),'id'=>$item['id'],'revision'=>(int)$item['secret_version']]);
                if ($replace->rowCount() !== 1) throw new RuntimeException('Historical version disappeared during rotation.');
                $revisionCount++;
                sodium_memzero($blob); sodium_memzero($rewrapped);
            }
            // Connector signing keys use the same master key and must survive offline rotation.
            $sources=$pdo->query("SELECT id::text,workspace_id::text,'connector_webhook' AS type,encode(secret_ciphertext,'hex') AS blob FROM connector_webhook_sources ORDER BY id");
            $replaceSource=$pdo->prepare("UPDATE connector_webhook_sources SET secret_ciphertext=decode(:blob,'hex') WHERE id=:id");
            while (($item=$sources->fetch(PDO::FETCH_ASSOC))!==false) {
                $blob=hex2bin($item['blob']);
                if ($blob===false) throw new RuntimeException('Invalid webhook ciphertext.');
                $rewrapped=$this->crypto->rewrap($blob,$item['id'],$item['workspace_id'],$item['type'],$next);
                $this->verifyNewEnvelope($nextCrypto,$rewrapped,$item);
                $replaceSource->execute(['blob'=>bin2hex($rewrapped),'id'=>$item['id']]);
                if ($replaceSource->rowCount()!==1) throw new RuntimeException('Webhook source disappeared during rotation.');
                sodium_memzero($blob);sodium_memzero($rewrapped);
            }
            $sources=$pdo->query("SELECT id::text,workspace_id::text,'data_ingestion' AS type,encode(secret_ciphertext,'hex') AS blob FROM data_ingestion_sources ORDER BY id");
            $replaceSource=$pdo->prepare("UPDATE data_ingestion_sources SET secret_ciphertext=decode(:blob,'hex') WHERE id=:id");
            while (($item=$sources->fetch(PDO::FETCH_ASSOC))!==false) {
                $blob=hex2bin($item['blob']);
                if ($blob===false) throw new RuntimeException('Invalid ingestion ciphertext.');
                $rewrapped=$this->crypto->rewrap($blob,$item['id'],$item['workspace_id'],$item['type'],$next);
                $this->verifyNewEnvelope($nextCrypto,$rewrapped,$item);
                $replaceSource->execute(['blob'=>bin2hex($rewrapped),'id'=>$item['id']]);
                if ($replaceSource->rowCount()!==1) throw new RuntimeException('Ingestion source disappeared during rotation.');
                sodium_memzero($blob);sodium_memzero($rewrapped);
            }
            $challenge = $nextCrypto->seal('cajeerservers:vault:verifier:v1',
                '00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-000000000002','vault_key_verifier');
            $state = $pdo->prepare("UPDATE vault_key_state SET key_id=:kid,verifier=decode(:blob,'hex'),
                generation=generation+1,changed_at=clock_timestamp() WHERE id=1 AND key_id=:previous");
            $state->execute(['kid'=>$newId,'blob'=>bin2hex($challenge),'previous'=>$previousId]);
            if ($state->rowCount()!==1) throw new RuntimeException('Active verifier update failed.');
            // Previously issued one-time grants must not survive an operational key switch.
            $pdo->exec('UPDATE vault_use_grants SET consumed_at=clock_timestamp() WHERE consumed_at IS NULL');
            $event = $pdo->prepare("INSERT INTO vault_key_rotation_events(from_key_id,to_key_id,credentials_rewrapped,versions_rewrapped)
                VALUES(:before,:after,:credentials,:versions)");
            $event->execute(['before'=>$previousId,'after'=>$newId,'credentials'=>$count,'versions'=>$revisionCount]);
            $this->audit->record($pdo,null,'vault.key.rotated','success','vault_key',null,['count'=>$count]);
            return ['rotated'=>true,'from_key_id'=>$previousId,'to_key_id'=>$newId,
                'credentials'=>$count,'versions'=>$revisionCount,'generation'=>(int)$row['generation']+1];
        });
    }

    private function verifyNewEnvelope(VaultCrypto $crypto,string $cipher,array $item): void
    {
        $plain = $crypto->open($cipher,(string)$item['id'],(string)$item['workspace_id'],(string)$item['type']);
        if ($plain !== '') sodium_memzero($plain);
    }
}
