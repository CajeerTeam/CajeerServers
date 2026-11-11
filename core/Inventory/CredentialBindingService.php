<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** Workspace-scoped credential references. Never reads or returns encrypted_payload. */
final class CredentialBindingService
{
    public function __construct(private readonly DatabaseManager $db,private readonly InventoryRepository $repo,private readonly AuditLogger $audit){}

    private static function uuid(mixed $value):string
    {
        if(!is_string($value)||!InfrastructureAsset::isUuid($value))throw new HttpException(422,'Invalid identifier.','validation_failed');
        return strtolower($value);
    }

    private static function purpose(mixed $purpose):string
    {
        if(!is_string($purpose)||preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$purpose)!==1)
            throw new HttpException(422,'Invalid binding purpose.','validation_failed');
        return $purpose;
    }

    /** Privileged opt-in enrollment of a *legacy* credential into one workspace; no secret access. */
    public function scope(string $actor,string $workspace,string $credential,?string $requestId=null):array
    {
        $workspace=self::uuid($workspace);$credential=self::uuid($credential);
        return $this->db->transaction(function(PDO $pdo)use($actor,$workspace,$credential,$requestId):array{
            $this->repo->lockWorkspace($pdo,$workspace);
            if($this->repo->workspaceRole($pdo,$workspace,$actor,true)!=='owner')throw new HttpException(403,'Workspace ownership required.','permission_denied');
            $cred=$pdo->prepare('SELECT workspace_id::text,vault_format,state,deleted_at FROM credentials WHERE id=:id FOR SHARE');
            $cred->execute(['id'=>$credential]);
            $item=$cred->fetch(PDO::FETCH_ASSOC);
            if(!is_array($item) || $item['vault_format']!=='cjev1' || $item['workspace_id']!==$workspace || $item['deleted_at']!==null)
                throw new HttpException(404,'Credential not found in this workspace.','not_found');
            if($item['state']!=='active') throw new HttpException(409,'Credential is not active.','conflict');
            $stmt=$pdo->prepare('INSERT INTO inventory_credential_scopes(credential_id,workspace_id,scoped_by) VALUES(CAST(:cred AS uuid),CAST(:ws AS uuid),CAST(:actor AS uuid)) ON CONFLICT(credential_id) DO NOTHING');
            $stmt->execute(['cred'=>$credential,'ws'=>$workspace,'actor'=>$actor]);
            $row=$pdo->prepare('SELECT workspace_id::text FROM inventory_credential_scopes WHERE credential_id=:id');
            $row->execute(['id'=>$credential]);
            if($row->fetchColumn()!==$workspace)throw new HttpException(409,'Credential is scoped to another workspace.','conflict');
            if($stmt->rowCount()===1)$this->audit->record($pdo,$actor,'inventory.credential.scoped','success','credential',$credential,[], $requestId);
            return ['credential_id'=>$credential,'workspace_id'=>$workspace];
        });
    }

    /** @return array<string,mixed> */
    public function attach(string $actor,string $assetId,string $credential,string $purpose,?string $requestId=null):array
    {
        $assetId=self::uuid($assetId);$credential=self::uuid($credential);$purpose=self::purpose($purpose);
        return $this->db->transaction(function(PDO $pdo)use($actor,$assetId,$credential,$purpose,$requestId):array{
            $row=$this->repo->find($pdo,$actor,$assetId);
            if($row===null)throw new HttpException(404,'Asset not found.','not_found');
            $workspace=$row['workspace_id'];$this->repo->lockWorkspace($pdo,$workspace);
            $this->repo->workspaceRole($pdo,$workspace,$actor,true);
            $check=$pdo->prepare('SELECT 1 FROM inventory_credential_scopes s JOIN credentials c ON c.id=s.credential_id
                WHERE s.workspace_id=:ws AND s.credential_id=:credential AND c.workspace_id=s.workspace_id
                  AND c.vault_format=\'cjev1\' AND c.state=\'active\' AND c.deleted_at IS NULL');
            $check->execute(['ws'=>$workspace,'credential'=>$credential]);
            if(!$check->fetchColumn())throw new HttpException(403,'Credential is not scoped to this workspace.','permission_denied');
            $statement=$pdo->prepare(<<<'SQL'
INSERT INTO inventory_asset_credential_bindings(workspace_id,asset_id,credential_id,purpose,created_by)
VALUES(CAST(:ws AS uuid),CAST(:asset AS uuid),CAST(:cred AS uuid),:purpose,CAST(:actor AS uuid))
ON CONFLICT(asset_id,credential_id,purpose) DO NOTHING
RETURNING id::text
SQL);
            $statement->execute(['ws'=>$workspace,'asset'=>$assetId,'cred'=>$credential,'purpose'=>$purpose,'actor'=>$actor]);
            $id=$statement->fetchColumn();
            if(!is_string($id))throw new HttpException(409,'Credential binding already exists.','conflict');
            $this->audit->record($pdo,$actor,'inventory.credential.bound','success','inventory_asset',$assetId,[], $requestId);
            return ['id'=>$id,'asset_id'=>$assetId,'credential_id'=>$credential,'purpose'=>$purpose];
        });
    }

    /** @return list<array<string,mixed>> */
    public function list(string $actor,string $assetId):array
    {
        $assetId=self::uuid($assetId);$pdo=$this->db->connection();
        $row=$this->repo->find($pdo,$actor,$assetId);
        if($row===null)throw new HttpException(404,'Asset not found.','not_found');
        $query=$pdo->prepare('SELECT id::text,asset_id::text,credential_id::text,purpose,created_at::text FROM inventory_asset_credential_bindings WHERE asset_id=:asset AND workspace_id=:ws ORDER BY created_at,id LIMIT 100');
        $query->execute(['asset'=>$assetId,'ws'=>$row['workspace_id']]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function detach(string $actor,string $assetId,string $bindingId,?string $requestId=null):void
    {
        $assetId=self::uuid($assetId);$bindingId=self::uuid($bindingId);
        $this->db->transaction(function(PDO $pdo)use($actor,$assetId,$bindingId,$requestId):void{
            $row=$this->repo->find($pdo,$actor,$assetId);
            if($row===null)throw new HttpException(404,'Asset not found.','not_found');
            $workspace=$row['workspace_id'];$this->repo->lockWorkspace($pdo,$workspace);
            $this->repo->workspaceRole($pdo,$workspace,$actor,true);
            $q=$pdo->prepare('DELETE FROM inventory_asset_credential_bindings WHERE id=:id AND asset_id=:asset AND workspace_id=:ws');
            $q->execute(['id'=>$bindingId,'asset'=>$assetId,'ws'=>$workspace]);
            if($q->rowCount()!==1)throw new HttpException(404,'Binding not found.','not_found');
            $this->audit->record($pdo,$actor,'inventory.credential.unbound','success','inventory_asset',$assetId,[], $requestId);
        });
    }
}
