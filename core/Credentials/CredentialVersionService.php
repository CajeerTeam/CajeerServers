<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\InventoryRepository;
use PDO;

/** Explicit, auditable staged secret rotation. No plaintext is returned to clients. */
final class CredentialVersionService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryRepository $inventory,
        private readonly CredentialsVault $vault,
        private readonly VaultCrypto $crypto,
        private readonly AuditLogger $audit,
    ) {}

    private function row(PDO $pdo,string $actor,string $credential,bool $lock=false):array
    {
        $sql="SELECT c.id::text,c.workspace_id::text,c.type,c.state,c.version,c.secret_version
            FROM credentials c JOIN workspace_members m ON m.workspace_id=c.workspace_id AND m.user_id=CAST(:actor AS uuid)
            JOIN users u ON u.id=m.user_id AND u.is_active=true
            WHERE c.id=CAST(:id AS uuid) AND c.vault_format='cjev1' AND c.deleted_at IS NULL";
        if ($lock) $sql.=' FOR UPDATE OF c';
        $q=$pdo->prepare($sql);$q->execute(['id'=>$credential,'actor'=>$actor]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new HttpException(404,'Credential not found.','not_found');
        return $row;
    }

    private function guarded(string $actor,string $credential,int $expected,callable $callback):array
    {
        $credential=CredentialInput::uuid($credential);
        if ($expected<1) throw new HttpException(400,'Invalid If-Match.','invalid_precondition');
        $this->vault->check();
        return $this->db->transaction(function(PDO $pdo) use($actor,$credential,$expected,$callback):array {
            $initial=$this->row($pdo,$actor,$credential);
            $this->inventory->lockWorkspace($pdo,(string)$initial['workspace_id']);
            $this->inventory->workspaceRole($pdo,(string)$initial['workspace_id'],$actor,true);
            $row=$this->row($pdo,$actor,$credential,true);
            if ((int)$row['version']!==$expected)
                throw new HttpException(412,'Credential changed. Reload before retrying.','precondition_failed');
            if ($row['state']!=='active')
                throw new HttpException(409,'Credential must be active to rotate.','conflict');
            return $callback($pdo,$row);
        });
    }

    public function history(string $actor,string $credential):array
    {
        $credential=CredentialInput::uuid($credential);
        $pdo=$this->db->connection();
        $row=$this->row($pdo,$actor,$credential);
        $this->inventory->workspaceRole($pdo,(string)$row['workspace_id'],$actor);
        $stmt=$pdo->prepare("SELECT secret_version,state,created_at::text,activated_at::text,retired_at::text
            FROM vault_credential_versions WHERE credential_id=CAST(:id AS uuid)
            ORDER BY secret_version DESC LIMIT 100");
        $stmt->execute(['id'=>$credential]);
        $items=array_map(static function(array $item):array {
            $item['secret_version']=(int)$item['secret_version'];
            return $item;
        },$stmt->fetchAll(PDO::FETCH_ASSOC));
        foreach ($items as &$item) {
            if ($item['state']!=='pending') continue;
            $status=$this->validationStatus($pdo,$credential,(int)$item['secret_version'],(int)$row['version']);
            $item['required_targets']=$status['required'];
            $item['validated_targets']=$status['validated'];
            $item['activation_ready']=$status['required']===$status['validated'];
        }
        unset($item);
        return ['data'=>$items];
    }

    public function stage(string $actor,string $credential,int $expected,mixed $secret,?string $requestId=null):array
    {
        return $this->guarded($actor,$credential,$expected,function(PDO $pdo,array $row) use($actor,$credential,$secret,$requestId):array {
            $serialized=CredentialProfile::encode((string)$row['type'],$secret);
            $pending=$pdo->prepare("SELECT 1 FROM vault_credential_versions WHERE credential_id=CAST(:id AS uuid) AND state='pending'");
            $pending->execute(['id'=>$credential]);
            if ($pending->fetchColumn()) throw new HttpException(409,'Pending credential rotation already exists.','conflict');
            $next=$pdo->prepare('SELECT coalesce(max(secret_version),0)+1 FROM vault_credential_versions WHERE credential_id=CAST(:id AS uuid)');
            $next->execute(['id'=>$credential]);
            $secretVersion=(int)$next->fetchColumn();
            $cipher=$this->crypto->seal($serialized,$credential,(string)$row['workspace_id'],(string)$row['type']);
            $insert=$pdo->prepare("INSERT INTO vault_credential_versions(credential_id,secret_version,state,encrypted_payload,created_by)
                VALUES(CAST(:id AS uuid),:revision,'pending',decode(:cipher,'hex'),CAST(:actor AS uuid))");
            $insert->execute(['id'=>$credential,'revision'=>$secretVersion,'cipher'=>bin2hex($cipher),'actor'=>$actor]);
            $this->bump($pdo,$actor,$credential,(int)$row['version']);
            $this->audit->record($pdo,$actor,'vault.credential.rotation_staged','success','credential',$credential,[], $requestId);
            return $this->result($credential,(int)$row['version']+1,$secretVersion,'pending');
        });
    }

    public function activate(string $actor,string $credential,int $expected,int $secretVersion,bool $confirmed,
                             ?string $requestId=null):array
    {
        if (!$confirmed) throw new HttpException(422,'Explicit external validation confirmation required.','validation_failed');
        if ($secretVersion<1) throw new HttpException(422,'Invalid secret version.','validation_failed');
        return $this->guarded($actor,$credential,$expected,function(PDO $pdo,array $row) use($actor,$credential,$secretVersion,$requestId):array {
            $q=$pdo->prepare("SELECT encode(encrypted_payload,'hex') FROM vault_credential_versions
                WHERE credential_id=CAST(:id AS uuid) AND secret_version=:revision AND state='pending' FOR UPDATE");
            $q->execute(['id'=>$credential,'revision'=>$secretVersion]);
            $hex=$q->fetchColumn();
            if (!is_string($hex)) throw new HttpException(409,'Pending secret version not found.','conflict');
            $status=$this->validationStatus($pdo,$credential,$secretVersion,(int)$row['version']);
            if ($status['required'] > $status['validated']) {
                throw new HttpException(409,
                    'Validate the pending credential against every active Proxmox connector before activation.',
                    'connector_validation_required');
            }
            $cipher=hex2bin($hex);
            if ($cipher===false) throw new HttpException(409,'Invalid encrypted version.','conflict');
            // Verify encrypted material with current Vault key before activation.
            $plain=$this->crypto->open($cipher,$credential,(string)$row['workspace_id'],(string)$row['type']);
            if ($plain!=='') sodium_memzero($plain);
            $end=$pdo->prepare("UPDATE vault_credential_versions SET state='retired',retired_at=clock_timestamp()
                WHERE credential_id=CAST(:id AS uuid) AND state='active'");
            $end->execute(['id'=>$credential]);
            $begin=$pdo->prepare("UPDATE vault_credential_versions SET state='active',activated_at=clock_timestamp()
                WHERE credential_id=CAST(:id AS uuid) AND secret_version=:revision AND state='pending'");
            $begin->execute(['id'=>$credential,'revision'=>$secretVersion]);
            if ($begin->rowCount()!==1) throw new HttpException(409,'Secret version changed.','conflict');
            $update=$pdo->prepare("UPDATE credentials SET encrypted_payload=decode(:cipher,'hex'),secret_version=:secret_version,
                version=version+1,updated_by=CAST(:actor AS uuid),updated_at=clock_timestamp()
                WHERE id=CAST(:id AS uuid) AND version=:version AND state='active'");
            $update->execute(['cipher'=>$hex,'secret_version'=>$secretVersion,'actor'=>$actor,'id'=>$credential,'version'=>(int)$row['version']]);
            if ($update->rowCount()!==1) throw new HttpException(412,'Credential changed.','precondition_failed');
            // Existing step-up grants reference the row revision and become unusable.
            $this->audit->record($pdo,$actor,'vault.credential.rotation_activated','success','credential',$credential,[], $requestId);
            $pdo->prepare('DELETE FROM vault_rotation_validations WHERE credential_id=CAST(:id AS uuid) AND secret_version=:revision')
                ->execute(['id'=>$credential,'revision'=>$secretVersion]);
            sodium_memzero($cipher);
            return $this->result($credential,(int)$row['version']+1,$secretVersion,'active');
        });
    }

    public function cancel(string $actor,string $credential,int $expected,int $secretVersion,?string $requestId=null):array
    {
        if ($secretVersion<1) throw new HttpException(422,'Invalid secret version.','validation_failed');
        return $this->guarded($actor,$credential,$expected,function(PDO $pdo,array $row) use($actor,$credential,$secretVersion,$requestId):array {
            $stmt=$pdo->prepare("UPDATE vault_credential_versions SET state='discarded',retired_at=clock_timestamp()
                WHERE credential_id=CAST(:id AS uuid) AND secret_version=:revision AND state='pending'");
            $stmt->execute(['id'=>$credential,'revision'=>$secretVersion]);
            if ($stmt->rowCount()!==1) throw new HttpException(409,'Pending version not found.','conflict');
            $this->bump($pdo,$actor,$credential,(int)$row['version']);
            $this->audit->record($pdo,$actor,'vault.credential.rotation_cancelled','success','credential',$credential,[], $requestId);
            $pdo->prepare('DELETE FROM vault_rotation_validations WHERE credential_id=CAST(:id AS uuid) AND secret_version=:revision')
                ->execute(['id'=>$credential,'revision'=>$secretVersion]);
            return $this->result($credential,(int)$row['version']+1,$secretVersion,'discarded');
        });
    }

    /**
     * A server-owned proof is valid for ten minutes and for exactly the revisions
     * checked remotely. Revoking a binding, editing the connector or updating the
     * credential automatically makes the old proof unusable.
     *
     * @return array{required:int,validated:int}
     */
    private function validationStatus(PDO $pdo,string $credential,int $revision,int $credentialVersion):array
    {
        $query=$pdo->prepare(<<<'SQL'
SELECT count(*) AS required, count(v.connector_id) AS validated
FROM connector_credential_bindings b
JOIN connector_targets t ON t.id=b.connector_id AND t.workspace_id=b.workspace_id
LEFT JOIN vault_rotation_validations v
  ON v.credential_id=b.credential_id AND v.secret_version=:revision
 AND v.connector_id=t.id AND v.connector_version=t.version
 AND v.credential_version=:version
 AND v.validated_at>clock_timestamp()-interval '10 minutes'
WHERE b.credential_id=CAST(:credential AS uuid) AND b.purpose='api'
  AND t.provider='proxmox' AND t.state='active'
SQL);
        $query->execute(['credential'=>$credential,'revision'=>$revision,'version'=>$credentialVersion]);
        $result=$query->fetch(PDO::FETCH_ASSOC);
        return ['required'=>(int)$result['required'],'validated'=>(int)$result['validated']];
    }

    /**
     * Persist real, successful read-only connector validation. Never trust an
     * HTTP supplied success flag or connector/credential revision without checking
     * the database again after remote I/O.
     */
    public function markValidated(string $actor,string $credential,int $revision,int $credentialVersion,
                                  string $connector,int $connectorVersion,?string $requestId=null):array
    {
        $credential=CredentialInput::uuid($credential);
        $connector=CredentialInput::uuid($connector);
        if ($revision<1 || $credentialVersion<1 || $connectorVersion<1)
            throw new HttpException(422,'Invalid validation revision.','validation_failed');
        return $this->db->transaction(function(PDO $pdo) use($actor,$credential,$revision,$credentialVersion,$connector,$connectorVersion,$requestId):array {
            $initial=$this->row($pdo,$actor,$credential);
            $workspace=(string)$initial['workspace_id'];
            $this->inventory->lockWorkspace($pdo,$workspace);
            $this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $row=$this->row($pdo,$actor,$credential,true);
            if ((int)$row['version']!==$credentialVersion || $row['state']!=='active')
                throw new HttpException(409,'Credential changed during validation.','conflict');
            $pending=$pdo->prepare("SELECT 1 FROM vault_credential_versions WHERE credential_id=CAST(:credential AS uuid)
                AND secret_version=:revision AND state='pending' FOR SHARE");
            $pending->execute(['credential'=>$credential,'revision'=>$revision]);
            if (!$pending->fetchColumn()) throw new HttpException(409,'Pending version changed.','conflict');
            $target=$pdo->prepare(<<<'SQL'
SELECT t.version FROM connector_targets t
JOIN connector_credential_bindings b ON b.connector_id=t.id AND b.workspace_id=t.workspace_id
JOIN inventory_assets a ON a.id=t.asset_id AND a.workspace_id=t.workspace_id AND a.deleted_at IS NULL
JOIN inventory_asset_credential_bindings ab ON ab.asset_id=a.id AND ab.workspace_id=t.workspace_id
  AND ab.credential_id=b.credential_id AND ab.purpose='api'
WHERE t.id=CAST(:connector AS uuid) AND t.workspace_id=CAST(:ws AS uuid)
 AND t.provider='proxmox' AND t.state='active' AND t.version=:version
 AND b.credential_id=CAST(:credential AS uuid) AND b.purpose='api'
FOR SHARE OF t,b,ab
SQL);
            $target->execute(['connector'=>$connector,'ws'=>$workspace,'version'=>$connectorVersion,'credential'=>$credential]);
            if (!$target->fetchColumn()) throw new HttpException(409,'Connector binding changed during validation.','conflict');
            $save=$pdo->prepare(<<<'SQL'
INSERT INTO vault_rotation_validations
(credential_id,secret_version,connector_id,credential_version,connector_version,validated_by)
VALUES(CAST(:credential AS uuid),:revision,CAST(:connector AS uuid),:credential_version,:connector_version,CAST(:actor AS uuid))
ON CONFLICT(credential_id,secret_version,connector_id) DO UPDATE SET
credential_version=EXCLUDED.credential_version,
connector_version=EXCLUDED.connector_version,
validated_by=EXCLUDED.validated_by,validated_at=clock_timestamp()
SQL);
            $save->execute(['credential'=>$credential,'revision'=>$revision,'connector'=>$connector,
                'credential_version'=>$credentialVersion,'connector_version'=>$connectorVersion,'actor'=>$actor]);
            $this->audit->record($pdo,$actor,'vault.credential.rotation_validated','success','credential',$credential,
                ['connector_id'=>$connector,'revision'=>$revision],$requestId);
            return $this->validationStatus($pdo,$credential,$revision,$credentialVersion);
        });
    }

    private function bump(PDO $pdo,string $actor,string $credential,int $version):void
    {
        $stmt=$pdo->prepare("UPDATE credentials SET version=version+1,updated_at=clock_timestamp(),updated_by=CAST(:actor AS uuid)
            WHERE id=CAST(:id AS uuid) AND version=:version");
        $stmt->execute(['id'=>$credential,'version'=>$version,'actor'=>$actor]);
        if ($stmt->rowCount()!==1) throw new HttpException(412,'Credential changed.','precondition_failed');
    }

    private function result(string $credential,int $version,int $secretVersion,string $state):array
    {
        return ['credential_id'=>$credential,'version'=>$version,'secret_version'=>$secretVersion,'state'=>$state,
            'etag'=>CredentialInput::etag($credential,$version)];
    }
}
