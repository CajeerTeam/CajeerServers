<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\InventoryRepository;
use PDO;
use RuntimeException;
use Throwable;

/** PostgreSQL-backed encrypted credential lifecycle. NEVER serves decrypted bytes to REST. */
final class CredentialStore
{
    private const PUBLIC_COLUMNS = <<<'SQL'
c.id::text,c.workspace_id::text,c.name,c.description,c.type,c.state,c.version,c.secret_version,
c.created_at::text,c.updated_at::text,c.deleted_at::text
SQL;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryRepository $inventory,
        private readonly CredentialsVault $vault,
        private readonly VaultCrypto $crypto,
        private readonly AuditLogger $audit,
    ) {}

    private function ensureReady(): void
    {
        try { $this->vault->check(); }
        catch (Throwable) { throw new HttpException(503, 'Vault is not ready.', 'vault_unavailable'); }
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    /** Return only allowlisted, non-secret fields; no ciphertext, raw metadata or key identifiers. */
    private static function public(array $row): array
    {
        $result = [];
        foreach (['id','workspace_id','name','description','type','state','created_at','updated_at','deleted_at'] as $key) {
            $result[$key] = $row[$key] ?? null;
        }
        $result['version'] = (int) $row['version'];
        $result['secret_version'] = (int) $row['secret_version'];
        $result['etag'] = CredentialInput::etag((string) $row['id'], (int) $row['version']);
        return $result;
    }

    private function visible(PDO $pdo, string $actor, string $id, bool $lock = false): array
    {
        $sql = 'SELECT '.self::PUBLIC_COLUMNS.' FROM credentials c '
            .'JOIN workspace_members wm ON wm.workspace_id=c.workspace_id AND wm.user_id=:actor '
            .'JOIN users u ON u.id=wm.user_id AND u.is_active=true '
            .'WHERE c.id=CAST(:id AS uuid) AND c.vault_format=\'cjev1\' AND c.deleted_at IS NULL';
        if ($lock) $sql .= ' FOR UPDATE OF c';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['actor'=>$actor,'id'=>$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($result)) throw new HttpException(404, 'Credential not found.', 'not_found');
        return $result;
    }

    /** @return array{data:list<array<string,mixed>>,pagination:array<string,int>} */
    public function list(string $actor, string $workspace, int $limit = 50, int $offset = 0): array
    {
        $workspace = CredentialInput::uuid($workspace);
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 10000)
            throw new HttpException(422, 'Invalid pagination.', 'validation_failed');
        $pdo = $this->db->connection();
        $this->inventory->workspaceRole($pdo, $workspace, $actor);
        $count = $pdo->prepare('SELECT count(*) FROM credentials c WHERE c.workspace_id=CAST(:ws AS uuid) AND c.vault_format=\'cjev1\' AND c.deleted_at IS NULL '
            .'AND EXISTS (SELECT 1 FROM workspace_members wm JOIN users u ON u.id=wm.user_id AND u.is_active=true '
            .'WHERE wm.workspace_id=c.workspace_id AND wm.user_id=CAST(:actor AS uuid))');
        $count->execute(['ws'=>$workspace,'actor'=>$actor]);
        $total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare('SELECT '.self::PUBLIC_COLUMNS.' FROM credentials c WHERE c.workspace_id=CAST(:ws AS uuid) '
            .'AND c.vault_format=\'cjev1\' AND c.deleted_at IS NULL '
            .'AND EXISTS (SELECT 1 FROM workspace_members wm JOIN users u ON u.id=wm.user_id AND u.is_active=true '
            .'WHERE wm.workspace_id=c.workspace_id AND wm.user_id=CAST(:actor AS uuid)) '
            .'ORDER BY c.created_at DESC,c.id DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':ws',$workspace);
        $stmt->bindValue(':actor',$actor);
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
        $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return ['data'=>array_map(self::public(...),$stmt->fetchAll(PDO::FETCH_ASSOC)),
            'pagination'=>['total'=>$total,'limit'=>$limit,'offset'=>$offset]];
    }

    public function show(string $actor, string $id): array
    {
        $pdo = $this->db->connection();
        return self::public($this->visible($pdo,$actor,CredentialInput::uuid($id)));
    }

    /** @param array<string,mixed> $input */
    public function create(string $actor, array $input, ?string $requestId = null): array
    {
        if (array_diff(array_keys($input), ['workspace_id','name','description','type','secret']) || !isset($input['secret']))
            throw new HttpException(422,'Invalid credential fields.','validation_failed');
        $workspace = CredentialInput::uuid($input['workspace_id']??null);
        $name = CredentialInput::name($input['name']??null);
        $description = CredentialInput::description($input['description']??'');
        $type = CredentialInput::type($input['type']??null);
        $secret = CredentialProfile::encode($type, $input['secret']);
        $this->ensureReady(); // Wrong/uninitialized master key blocks all new writes.
        return $this->db->transaction(function(PDO $pdo) use ($actor,$workspace,$name,$description,$type,$secret,$requestId):array {
            $this->inventory->lockWorkspace($pdo,$workspace);
            $this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $id = self::uuid();
            $envelope = $this->crypto->seal($secret,$id,$workspace,$type);
            $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO credentials(id,workspace_id,name,description,type,encrypted_payload,metadata,vault_format,state,version,created_by,updated_by)
VALUES(CAST(:id AS uuid),CAST(:ws AS uuid),:name,:description,:type,decode(:cipher,'hex'),'{}'::jsonb,'cjev1','active',1,
       CAST(:actor AS uuid),CAST(:actor AS uuid))
SQL);
            $stmt->execute(['id'=>$id,'ws'=>$workspace,'name'=>$name,'description'=>$description,'type'=>$type,
                'cipher'=>bin2hex($envelope),'actor'=>$actor]);
            // Fresh credentials are explicitly scoped to their creator's authorized workspace.
            $scope = $pdo->prepare(<<<'SQL'
INSERT INTO inventory_credential_scopes(credential_id,workspace_id,scoped_by)
VALUES(CAST(:id AS uuid),CAST(:ws AS uuid),CAST(:actor AS uuid))
SQL);
            $scope->execute(['id'=>$id,'ws'=>$workspace,'actor'=>$actor]);
            $history=$pdo->prepare("INSERT INTO vault_credential_versions(credential_id,secret_version,state,encrypted_payload,activated_at,created_by)
                VALUES(CAST(:id AS uuid),1,'active',decode(:cipher,'hex'),clock_timestamp(),CAST(:actor AS uuid))");
            $history->execute(['id'=>$id,'cipher'=>bin2hex($envelope),'actor'=>$actor]);
            $this->audit->record($pdo,$actor,'vault.credential.created','success','credential',$id,[], $requestId);
            return self::public($this->visible($pdo,$actor,$id));
        });
    }

    /** @param array<string,mixed> $changes */
    public function update(string $actor,string $id,int $version,array $changes,?string $requestId=null):array
    {
        $patch = CredentialInput::patch($changes);
        return $this->mutate($actor,$id,$version,'metadata',$patch,null,$requestId);
    }

    public function replaceSecret(string $actor,string $id,int $version,mixed $secret,?string $requestId=null):array
    {
        $this->ensureReady();
        return $this->mutate($actor,$id,$version,'secret',[],$secret,$requestId);
    }

    public function archive(string $actor,string $id,int $version,?string $requestId=null):array
    {
        return $this->mutate($actor,$id,$version,'archive',[],null,$requestId);
    }

    /** @param array<string,mixed> $patch */
    private function mutate(string $actor,string $id,int $version,string $action,array $patch,mixed $secret,?string $requestId):array
    {
        $id = CredentialInput::uuid($id);
        if ($version < 1) throw new HttpException(400,'Invalid credential version.','invalid_precondition');
        return $this->db->transaction(function(PDO $pdo) use ($actor,$id,$version,$action,$patch,$secret,$requestId):array {
            $visible = $this->visible($pdo,$actor,$id);
            $workspace = (string)$visible['workspace_id'];
            $this->inventory->lockWorkspace($pdo,$workspace);
            $this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $row = $this->visible($pdo,$actor,$id,true);
            if ((int)$row['version'] !== $version) throw new HttpException(412,'Credential changed. Reload before retrying.','precondition_failed');
            if ($action === 'archive') {
                $bindings = $pdo->prepare('SELECT count(*) FROM inventory_asset_credential_bindings WHERE credential_id=CAST(:id AS uuid)');
                $bindings->execute(['id'=>$id]);
                if ((int)$bindings->fetchColumn() > 0)
                    throw new HttpException(409,'Detach credential from inventory assets before archiving.','conflict');
                $connectors=$pdo->prepare('SELECT 1 FROM connector_credential_bindings WHERE credential_id=CAST(:id AS uuid) LIMIT 1');
                $connectors->execute(['id'=>$id]);
                if ($connectors->fetchColumn()) throw new HttpException(409,'Detach credential from connector targets before archiving.','conflict');
            }
            $fields=['version=version+1','updated_at=clock_timestamp()','updated_by=CAST(:actor AS uuid)'];
            $params=['actor'=>$actor,'id'=>$id,'version'=>$version];
            if ($action==='metadata') {
                foreach ($patch as $key=>$value) {
                    $column = $key==='status'?'state':$key;
                    $fields[]=$column.'=:'.$key;
                    $params[$key]=$value;
                }
            } elseif ($action==='secret') {
                if ($secret===null) throw new RuntimeException('Missing authorized replacement.');
                if ($row['state']!=='active') throw new HttpException(409,'Credential must be active.','conflict');
                $pending=$pdo->prepare("SELECT 1 FROM vault_credential_versions WHERE credential_id=CAST(:id AS uuid) AND state='pending'");
                $pending->execute(['id'=>$id]);
                if ($pending->fetchColumn()) throw new HttpException(409,'Cancel or activate pending rotation first.','conflict');
                $serialized = CredentialProfile::encode((string)$row['type'],$secret);
                $cipher = $this->crypto->seal($serialized,$id,$workspace,(string)$row['type']);
                $fields[]="encrypted_payload=decode(:cipher,'hex')";
                $next=$pdo->prepare('SELECT coalesce(max(secret_version),0)+1 FROM vault_credential_versions WHERE credential_id=CAST(:id AS uuid)');
                $next->execute(['id'=>$id]);
                $newSecretVersion=(int)$next->fetchColumn();
                $fields[]="secret_version=:secret_version";
                $params['secret_version']=$newSecretVersion;
                $params['cipher']=bin2hex($cipher);
            } else {
                $fields[]="state='archived'";
                $fields[]='deleted_at=clock_timestamp()';
            }
            $stmt=$pdo->prepare('UPDATE credentials SET '.implode(',',$fields).' WHERE id=CAST(:id AS uuid) AND version=:version AND deleted_at IS NULL');
            $stmt->execute($params);
            if ($stmt->rowCount()!==1) throw new HttpException(412,'Credential changed during update.','precondition_failed');
            if ($action==='secret') {
                $end=$pdo->prepare("UPDATE vault_credential_versions SET state='retired',retired_at=clock_timestamp()
                    WHERE credential_id=CAST(:id AS uuid) AND state='active'");
                $end->execute(['id'=>$id]);
                $next=$pdo->prepare('SELECT secret_version FROM credentials WHERE id=CAST(:id AS uuid)');
                $next->execute(['id'=>$id]);
                $secretVersion=(int)$next->fetchColumn();
                $history=$pdo->prepare("INSERT INTO vault_credential_versions(credential_id,secret_version,state,encrypted_payload,activated_at,created_by)
                    VALUES(CAST(:id AS uuid),:revision,'active',decode(:cipher,'hex'),clock_timestamp(),CAST(:actor AS uuid))");
                $history->execute(['id'=>$id,'revision'=>$secretVersion,'cipher'=>$params['cipher'],'actor'=>$actor]);
            } elseif ($action==='archive') {
                $history=$pdo->prepare("UPDATE vault_credential_versions SET state='retired',retired_at=clock_timestamp()
                    WHERE credential_id=CAST(:id AS uuid) AND state='active'");
                $history->execute(['id'=>$id]);
                $pending=$pdo->prepare("UPDATE vault_credential_versions SET state='discarded',retired_at=clock_timestamp()
                    WHERE credential_id=CAST(:id AS uuid) AND state='pending'");
                $pending->execute(['id'=>$id]);
            }
            $this->audit->record($pdo,$actor,'vault.credential.'.($action==='metadata'?'updated':($action==='secret'?'secret_replaced':'archived')),
                'success','credential',$id,[], $requestId);
            if ($action==='archive') {
                $stmt=$pdo->prepare('SELECT '.self::PUBLIC_COLUMNS.' FROM credentials c WHERE c.id=CAST(:id AS uuid)');
                $stmt->execute(['id'=>$id]);
                $archived=$stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($archived)) throw new RuntimeException('Archived credential cannot be retrieved.');
                return self::public($archived);
            }
            return self::public($this->visible($pdo,$actor,$id));
        });
    }
}
