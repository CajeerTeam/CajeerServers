<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Credentials\BindingPolicy;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\InventoryRepository;
use PDO;
use Cajeer\Servers\Integration\ConnectorRegistry;
use PDOException;

/** Persisted, workspace-isolated connector configurations and Vault references.
 * Registered targets are executable via ConnectorRuntimeService; this service never reads a secret.
 */
final class ConnectorBindingService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryRepository $inventory,
        private readonly AuditLogger $audit,
        private readonly ConnectorRegistry $registry,
    ) {}

    private static function uuid(mixed $id):string { return CredentialInput::uuid($id); }

    public static function validateName(mixed $name):string
    {
        if (!is_string($name) || strlen(trim($name))<1 || strlen($name)>120 || !preg_match('//u',$name)
            || preg_match('/[\x00-\x1f\x7f]/',$name))
            throw new HttpException(422,'Invalid connector name.','validation_failed');
        return trim($name);
    }

    public static function validateHost(mixed $host):string
    {
        if (!is_string($host) || strlen($host)<1 || strlen($host)>253 || $host!==trim($host))
            throw new HttpException(422,'Invalid connector host.','validation_failed');
        if (filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_IPV6)) return strtolower($host);
        if (preg_match('/^[0-9.]+$/D',$host) || preg_match('/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*$/D',$host)!==1)
            throw new HttpException(422,'Invalid connector host.','validation_failed');
        return strtolower($host);
    }

    public static function validatePort(mixed $port):int
    {
        if (!is_int($port) || $port<1 || $port>65535)
            throw new HttpException(422,'Invalid connector port.','validation_failed');
        return $port;
    }

    private static function identifier():string
    {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
        $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    /** @return array<string,mixed> */
    private static function present(array $row):array
    {
        foreach (['id','workspace_id','asset_id'] as $key) if (isset($row[$key])) $row[$key]=(string)$row[$key];
        $row['version']=(int)$row['version'];$row['endpoint_port']=(int)$row['endpoint_port'];
        $row['health_duration_ms']=isset($row['health_duration_ms'])?(int)$row['health_duration_ms']:null;
        $row['consecutive_failures']=(int)($row['consecutive_failures']??0);
        $row['etag']=CredentialInput::etag($row['id'],$row['version']);
        return $row;
    }

    private function target(PDO $pdo,string $actor,string $id,bool $lock=false):array
    {
        $query=$pdo->prepare('SELECT t.id::text,t.workspace_id::text,t.provider,t.name,t.endpoint_host,t.endpoint_port,t.asset_id::text,t.state,t.version,t.created_at::text,t.updated_at::text,h.health_status,h.health_checked_at::text,h.health_success_at::text,h.health_duration_ms,h.health_error_code,h.circuit_open_until::text,h.consecutive_failures FROM connector_targets t JOIN connector_health_current h ON h.target_id=t.id JOIN workspace_members wm ON wm.workspace_id=t.workspace_id AND wm.user_id=CAST(:actor AS uuid) JOIN users u ON u.id=wm.user_id AND u.is_active=true WHERE t.id=CAST(:id AS uuid)'.($lock?' FOR UPDATE OF t':''));
        $query->execute(['id'=>$id,'actor'=>$actor]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new HttpException(404,'Connector configuration not found.','not_found');
        return self::present($row);
    }

    /** @param array<string,mixed> $data */
    public function create(string $actor,array $data,?string $requestId=null):array
    {
        if (array_diff(array_keys($data),['workspace_id','provider','name','endpoint_host','endpoint_port','asset_id'])!==[])
            throw new HttpException(422,'Invalid connector fields.','validation_failed');
        $ws=self::uuid($data['workspace_id']??null);
        $provider=BindingPolicy::provider($data['provider']??null);
        $name=self::validateName($data['name']??null);
        $host=self::validateHost($data['endpoint_host']??null);
        $port=self::validatePort($data['endpoint_port']??null);
        $asset=isset($data['asset_id'])?self::uuid($data['asset_id']):null;
        return $this->db->transaction(function(PDO $pdo)use($actor,$ws,$provider,$name,$host,$port,$asset,$requestId):array {
            $this->inventory->lockWorkspace($pdo,$ws);
            $this->inventory->workspaceRole($pdo,$ws,$actor,true);
            if ($asset!==null) {
                $row=$this->inventory->find($pdo,$actor,$asset);
                if (!$row || $row['workspace_id']!==$ws) throw new HttpException(404,'Asset unavailable in workspace.','not_found');
            }
            $id=self::identifier();
            $stmt=$pdo->prepare('INSERT INTO connector_targets(id,workspace_id,provider,name,endpoint_host,endpoint_port,asset_id,created_by) VALUES(CAST(:id AS uuid),CAST(:ws AS uuid),:provider,:name,:host,:port,CAST(:asset AS uuid),CAST(:actor AS uuid))');
            try {$stmt->execute(['id'=>$id,'ws'=>$ws,'provider'=>$provider,'name'=>$name,'host'=>$host,'port'=>$port,'asset'=>$asset,'actor'=>$actor]);}
            catch(PDOException $e) {
                if (($e->errorInfo[0]??$e->getCode())==='23505')
                    throw new HttpException(409,'Connector name already exists in workspace.','conflict');
                throw $e;
            }
            $this->audit->record($pdo,$actor,'connector.target.created','success','connector_target',$id,[], $requestId);
            return $this->target($pdo,$actor,$id);
        });
    }

    public function list(string $actor,string $workspace,int $limit=50,int $offset=0,?string $search=null,?string $provider=null,?string $state=null):array
    {
        $workspace=self::uuid($workspace);
        if ($limit<1||$limit>100||$offset<0||$offset>10000) throw new HttpException(422,'Invalid pagination.','validation_failed');
        $pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        $filters='';$params=[];
        if ($search!==null && $search!=='') { $search=self::validateName($search);$filters.=' AND (strpos(lower(t.name),lower(:search_name))>0 OR strpos(lower(t.endpoint_host),lower(:search_host))>0)';$params[':search_name']=$search;$params[':search_host']=$search; }
        if ($provider!==null && $provider!=='') {if (!in_array($provider,['proxmox','vmmanager','ssh','winrm','snmp','custom'],true)) throw new HttpException(422,'Invalid provider filter.','validation_failed');$filters.=' AND t.provider=:provider';$params[':provider']=$provider;}
        if ($state!==null && $state!=='') {if (!in_array($state,['active','disabled'],true)) throw new HttpException(422,'Invalid state filter.','validation_failed');$filters.=' AND t.state=:state';$params[':state']=$state;}
        $q=$pdo->prepare('SELECT t.id::text,t.workspace_id::text,t.provider,t.name,t.endpoint_host,t.endpoint_port,t.asset_id::text,t.state,t.version,t.created_at::text,t.updated_at::text,h.health_status,h.health_checked_at::text,h.health_success_at::text,h.health_duration_ms,h.health_error_code,h.circuit_open_until::text,h.consecutive_failures FROM connector_targets t JOIN connector_health_current h ON h.target_id=t.id WHERE t.workspace_id=CAST(:ws AS uuid)'.$filters.' ORDER BY t.created_at DESC,t.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $key=>$value) $q->bindValue($key,$value,PDO::PARAM_STR);
        $q->bindValue(':ws',$workspace);$q->bindValue(':limit',$limit,PDO::PARAM_INT);$q->bindValue(':offset',$offset,PDO::PARAM_INT);$q->execute();
        return array_map(self::present(...),$q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function show(string $actor,string $id):array
    {
        return $this->target($this->db->connection(),$actor,self::uuid($id));
    }

    /** @param array<string,mixed> $patch */
    public function update(string $actor,string $id,int $version,array $patch,?string $requestId=null):array
    {
        $id=self::uuid($id);
        if ($patch===[] || array_diff(array_keys($patch),['name','endpoint_host','endpoint_port','state','asset_id'])!==[])
            throw new HttpException(422,'Invalid connector update.','validation_failed');
        $changes=[];
        foreach ($patch as $key=>$value) {
            $changes[$key]=match($key){
                'name'=>self::validateName($value),'endpoint_host'=>self::validateHost($value),
                'endpoint_port'=>self::validatePort($value),
                'state'=>in_array($value,['active','disabled'],true)?$value:throw new HttpException(422,'Invalid connector state.','validation_failed'),
                'asset_id'=>$value===null?null:self::uuid($value),
            };
        }
        return $this->db->transaction(function(PDO $pdo)use($actor,$id,$version,$changes,$requestId):array {
            $old=$this->target($pdo,$actor,$id);
            $ws=$old['workspace_id'];$this->inventory->lockWorkspace($pdo,$ws);
            $this->inventory->workspaceRole($pdo,$ws,$actor,true);
            $current=$this->target($pdo,$actor,$id,true);
            if ($current['version']!==$version) throw new HttpException(412,'Connector was modified.','precondition_failed');
            if (($changes['endpoint_host']??$current['endpoint_host'])!==$current['endpoint_host'] || ($changes['endpoint_port']??$current['endpoint_port'])!==$current['endpoint_port']) {
                $discovered=$pdo->prepare('SELECT 1 FROM connector_discovery_state WHERE target_id=:id'); $discovered->execute(['id'=>$id]);
                if ($discovered->fetchColumn()) throw new HttpException(409,'Create a new target when changing a discovered endpoint.','connector_endpoint_changed');
            }
            if (array_key_exists('asset_id',$changes) && $changes['asset_id']!==null) {
                $asset=$this->inventory->find($pdo,$actor,$changes['asset_id']);
                if (!$asset || $asset['workspace_id']!==$ws) throw new HttpException(404,'Asset unavailable in workspace.','not_found');
            }
            $parts=['version=version+1','updated_at=clock_timestamp()'];$params=['id'=>$id,'version'=>$version];
            foreach ($changes as $key=>$value) {$parts[]=$key.'=:'.$key;$params[$key]=$value;}
            $stmt=$pdo->prepare('UPDATE connector_targets SET '.implode(',',$parts).' WHERE id=CAST(:id AS uuid) AND version=:version');
            try {$stmt->execute($params);}
            catch(PDOException $e) {
                if (($e->errorInfo[0]??$e->getCode())==='23505')
                    throw new HttpException(409,'Connector name already exists in workspace.','conflict');
                throw $e;
            }
            if ($stmt->rowCount()!==1)throw new HttpException(412,'Connector changed.','precondition_failed');
            $this->audit->record($pdo,$actor,'connector.target.updated','success','connector_target',$id,[], $requestId);
            return $this->target($pdo,$actor,$id);
        });
    }

    public function delete(string $actor,string $id,int $version,?string $requestId=null):void
    {
        $id=self::uuid($id);
        $this->db->transaction(function(PDO $pdo)use($actor,$id,$version,$requestId):void{
            $row=$this->target($pdo,$actor,$id);$this->inventory->lockWorkspace($pdo,$row['workspace_id']);
            $this->inventory->workspaceRole($pdo,$row['workspace_id'],$actor,true);
            $row=$this->target($pdo,$actor,$id,true);
            if ($row['version']!==$version)throw new HttpException(412,'Connector was modified.','precondition_failed');
            $stmt=$pdo->prepare('SELECT 1 FROM connector_credential_bindings WHERE connector_id=CAST(:id AS uuid) LIMIT 1');
            $stmt->execute(['id'=>$id]);
            if ($stmt->fetchColumn())throw new HttpException(409,'Detach connector credentials first.','conflict');
            $stmt=$pdo->prepare("SELECT 1 FROM connector_actions WHERE target_id=:id AND status IN ('dispatching','submitted','unknown') LIMIT 1");
            $stmt->execute(['id'=>$id]);
            if ($stmt->fetchColumn())throw new HttpException(409,'Resolve outstanding connector actions before deletion.','action_unresolved');
            $stmt=$pdo->prepare('DELETE FROM connector_targets WHERE id=CAST(:id AS uuid) AND version=:version');
            $stmt->execute(['id'=>$id,'version'=>$version]);
            if ($stmt->rowCount()!==1)throw new HttpException(412,'Connector changed.','precondition_failed');
            $this->audit->record($pdo,$actor,'connector.target.deleted','success','connector_target',$id,[], $requestId);
        });
    }

    public function listBindings(string $actor,string $id):array
    {
        $id=self::uuid($id);$pdo=$this->db->connection();$target=$this->target($pdo,$actor,$id);
        $stmt=$pdo->prepare('SELECT b.id::text,b.connector_id::text,b.credential_id::text,b.purpose,b.created_at::text,c.type,c.state,c.version AS credential_version FROM connector_credential_bindings b JOIN credentials c ON c.id=b.credential_id WHERE b.connector_id=CAST(:id AS uuid) AND b.workspace_id=CAST(:ws AS uuid) ORDER BY b.created_at,b.id LIMIT 100');
        $stmt->execute(['id'=>$id,'ws'=>$target['workspace_id']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function bind(string $actor,string $id,string $credential,string $purpose,int $version,?string $requestId=null,bool $attachToAsset=false):array
    {
        $id=self::uuid($id);$credential=self::uuid($credential);$purpose=BindingPolicy::purpose($purpose);
        return $this->db->transaction(function(PDO $pdo)use($actor,$id,$credential,$purpose,$version,$requestId,$attachToAsset):array{
            $target=$this->target($pdo,$actor,$id);$ws=$target['workspace_id'];
            $this->inventory->lockWorkspace($pdo,$ws);$this->inventory->workspaceRole($pdo,$ws,$actor,true);
            $target=$this->target($pdo,$actor,$id,true);
            if ($target['version']!==$version)throw new HttpException(412,'Connector was modified.','precondition_failed');
            if ($target['state']!=='active')throw new HttpException(409,'Connector is disabled.','conflict');
            $stmt=$pdo->prepare('SELECT c.type FROM inventory_credential_scopes s JOIN credentials c ON c.id=s.credential_id AND c.workspace_id=s.workspace_id WHERE s.credential_id=CAST(:credential AS uuid) AND s.workspace_id=CAST(:ws AS uuid) AND c.state=\'active\' AND c.vault_format=\'cjev1\' AND c.deleted_at IS NULL FOR SHARE OF c');
            $stmt->execute(['credential'=>$credential,'ws'=>$ws]);
            $type=$stmt->fetchColumn();
            if (!is_string($type)||!BindingPolicy::allowsConnector($target['provider'],$type,$purpose))
                throw new HttpException(403,'Credential not authorized for connector purpose.','permission_denied');
            $row=$pdo->prepare('INSERT INTO connector_credential_bindings(workspace_id,connector_id,credential_id,purpose,created_by) VALUES(CAST(:ws AS uuid),CAST(:connector AS uuid),CAST(:credential AS uuid),:purpose,CAST(:actor AS uuid)) ON CONFLICT(connector_id,purpose) DO NOTHING RETURNING id::text');
            $row->execute(['ws'=>$ws,'connector'=>$id,'credential'=>$credential,'purpose'=>$purpose,'actor'=>$actor]);
            $binding=$row->fetchColumn();
            if (!is_string($binding))throw new HttpException(409,'Connector purpose already bound.','conflict');
            if ($attachToAsset) {
                if ($target['asset_id']===null) throw new HttpException(422,'Select an Inventory asset to link credentials.','validation_failed');
                $asset=$this->inventory->find($pdo,$actor,$target['asset_id']);
                if ($asset===null || $asset['workspace_id']!==$ws) throw new HttpException(404,'Target asset unavailable.','not_found');
                $attach=$pdo->prepare('INSERT INTO inventory_asset_credential_bindings(workspace_id,asset_id,credential_id,purpose,created_by) VALUES(CAST(:ws AS uuid),CAST(:asset AS uuid),CAST(:credential AS uuid),:purpose,CAST(:actor AS uuid)) ON CONFLICT(asset_id,credential_id,purpose) DO NOTHING');
                $attach->execute(['ws'=>$ws,'asset'=>$target['asset_id'],'credential'=>$credential,'purpose'=>$purpose,'actor'=>$actor]);
                if ($attach->rowCount()===1) $this->audit->record($pdo,$actor,'inventory.credential.bound','success','inventory_asset',$target['asset_id'],[], $requestId);
            }
            $this->bump($pdo,$id);
            $this->audit->record($pdo,$actor,'connector.credential.bound','success','connector_target',$id,[], $requestId);
            return ['id'=>$binding,'connector_id'=>$id,'credential_id'=>$credential,'purpose'=>$purpose];
        });
    }

    public function unbind(string $actor,string $id,string $bindingId,int $version,?string $requestId=null):void
    {
        $id=self::uuid($id);$bindingId=self::uuid($bindingId);
        $this->db->transaction(function(PDO $pdo)use($actor,$id,$bindingId,$version,$requestId):void{
            $target=$this->target($pdo,$actor,$id);$ws=$target['workspace_id'];
            $this->inventory->lockWorkspace($pdo,$ws);$this->inventory->workspaceRole($pdo,$ws,$actor,true);
            $target=$this->target($pdo,$actor,$id,true);
            if ($target['version']!==$version)throw new HttpException(412,'Connector was modified.','precondition_failed');
            $stmt=$pdo->prepare('DELETE FROM connector_credential_bindings WHERE id=CAST(:binding AS uuid) AND connector_id=CAST(:connector AS uuid) AND workspace_id=CAST(:ws AS uuid)');
            $stmt->execute(['binding'=>$bindingId,'connector'=>$id,'ws'=>$ws]);
            if ($stmt->rowCount()!==1)throw new HttpException(404,'Binding not found.','not_found');
            $this->bump($pdo,$id);
            $this->audit->record($pdo,$actor,'connector.credential.unbound','success','connector_target',$id,[], $requestId);
        });
    }

    /** Trusted metadata lookup only. Never loads encrypted payload or exposes a secret. */
    public function resolveActive(string $actor,string $id,string $purpose):array
    {
        $id=self::uuid($id);$purpose=BindingPolicy::purpose($purpose);
        $pdo=$this->db->connection();$target=$this->target($pdo,$actor,$id);
        if ($target['state']!=='active')throw new HttpException(409,'Connector is disabled.','conflict');
        $stmt=$pdo->prepare('SELECT b.credential_id::text,c.type,c.version FROM connector_credential_bindings b JOIN credentials c ON c.id=b.credential_id AND c.workspace_id=b.workspace_id WHERE b.connector_id=CAST(:id AS uuid) AND b.workspace_id=CAST(:ws AS uuid) AND b.purpose=:purpose AND c.vault_format=\'cjev1\' AND c.state=\'active\' AND c.deleted_at IS NULL');
        $stmt->execute(['id'=>$id,'ws'=>$target['workspace_id'],'purpose'=>$purpose]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)||!BindingPolicy::allowsConnector($target['provider'],(string)$row['type'],$purpose))
            throw new HttpException(404,'Active connector credential not available.','not_found');
        return ['credential_id'=>$row['credential_id'],'type'=>$row['type'],'credential_version'=>(int)$row['version'],'workspace_id'=>$target['workspace_id'],'purpose'=>$purpose];
    }

    /** Authorizes a concrete operation. Only a matching active connector AND Inventory binding can use a Vault grant. */
    public function runtimeContext(string $actor,string $id,int $version,string $operation='inspect'):array
    {
        $id=self::uuid($id);
        $pdo=$this->db->connection();
        $target=$this->target($pdo,$actor,$id);
        $this->inventory->workspaceRole($pdo,$target['workspace_id'],$actor,true);
        if ($version!==$target['version'])throw new HttpException(412,'Connector configuration changed.','precondition_failed');
        if ($target['state']!=='active')throw new HttpException(409,'Connector is disabled.','conflict');
        $manifest=$this->registry->require($target['provider'],$operation)->manifest();
        $purpose=$manifest->purpose;
        if ($target['asset_id']===null)throw new HttpException(409,'Associate an Inventory asset with this connector.','asset_required');
        $asset=$this->inventory->find($pdo,$actor,$target['asset_id']);
        if ($asset===null || $asset['workspace_id']!==$target['workspace_id'])
            throw new HttpException(409,'Connector asset is unavailable.','asset_unavailable');
        $credential=$this->resolveActive($actor,$id,$purpose);
        if (!$manifest->accepts($credential['type'],$purpose)) throw new HttpException(403,'Credential not supported by driver.','permission_denied');
        $check=$pdo->prepare('SELECT 1 FROM inventory_asset_credential_bindings b WHERE b.workspace_id=CAST(:ws AS uuid) AND b.asset_id=CAST(:asset AS uuid) AND b.credential_id=CAST(:credential AS uuid) AND b.purpose=:purpose');
        $check->execute(['ws'=>$target['workspace_id'],'asset'=>$target['asset_id'],'credential'=>$credential['credential_id'],'purpose'=>$purpose]);
        if (!$check->fetchColumn())throw new HttpException(409,'Attach the same credential to the Inventory asset before execution.','inventory_binding_required');
        return [...$target,'credential_id'=>$credential['credential_id'],'credential_version'=>$credential['credential_version'],'credential_type'=>$credential['type'],'purpose'=>$purpose];
    }

    private function bump(PDO $pdo,string $id):void
    {
        $stmt=$pdo->prepare('UPDATE connector_targets SET version=version+1,updated_at=clock_timestamp() WHERE id=CAST(:id AS uuid)');
        $stmt->execute(['id'=>$id]);
    }
}
