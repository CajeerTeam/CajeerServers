<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;
use PDOException;

/** Persistent, audited asset relations. Every operation is authorized against its workspace. */
final class RelationshipService
{
    public const KINDS = ['cluster_contains','hosts','depends_on','connected_to'];
    private const PROJECTION = 'id::text,workspace_id::text,source_asset_id::text,target_asset_id::text,kind,version,created_at::text,updated_at::text';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryRepository $inventory,
        private readonly AuditLogger $audit,
    ) {}

    public static function uuid(mixed $value, string $field='id'): string
    {
        if (!is_string($value) || !InfrastructureAsset::isUuid($value)) {
            throw new HttpException(422,'Invalid relationship field: '.$field,'validation_failed');
        }
        return strtolower($value);
    }
    public static function kind(mixed $kind): string
    {
        if (!is_string($kind) || !in_array($kind,self::KINDS,true)) {
            throw new HttpException(422,'Invalid relation kind.','validation_failed');
        }
        return $kind;
    }
    private static function canonical(string $source, string $target, string $kind): array
    {
        if ($source === $target) throw new HttpException(422,'A resource cannot relate to itself.','validation_failed');
        return $kind==='connected_to' && strcmp($source,$target)>0 ? [$target,$source] : [$source,$target];
    }
    private static function row(array $row): array
    {
        $row['version']=(int)$row['version'];
        $row['etag']=InfrastructureAsset::etag((string)$row['id'],$row['version']);
        return $row;
    }
    private static function conflict(PDOException $e): never
    {
        $state=(string)($e->errorInfo[0]??$e->getCode());
        if (in_array($state,['23505','23503','23514','23P01'],true)) {
            throw new HttpException(409,'Relationship conflicts with topology, asset state or workspace.','conflict');
        }
        throw $e;
    }
    private static function load(PDO $pdo,string $ws,string $id,bool $lock=false): ?array
    {
        $stmt=$pdo->prepare('SELECT '.self::PROJECTION.' FROM inventory_relationships WHERE workspace_id=:ws AND id=:id'.($lock?' FOR UPDATE':''));
        $stmt->execute(['ws'=>$ws,'id'=>$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?self::row($row):null;
    }
    public function read(string $actor,string $workspace,string $id): array
    {
        $workspace=self::uuid($workspace,'workspace_id');$id=self::uuid($id);
        $pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        return self::load($pdo,$workspace,$id) ?? throw new HttpException(404,'Relationship not found.','not_found');
    }
    public function list(string $actor,string $workspace,int $limit=50,int $offset=0,?string $kind=null,?string $assetId=null): array
    {
        $workspace=self::uuid($workspace,'workspace_id');
        if ($limit<1 || $limit>100 || $offset<0 || $offset>10000) throw new HttpException(422,'Invalid pagination.','validation_failed');
        if ($kind!==null) self::kind($kind);
        if ($assetId!==null) $assetId=self::uuid($assetId,'asset_id');
        $pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        $where=' FROM inventory_relationships WHERE workspace_id=:ws';$params=['ws'=>$workspace];
        if ($kind!==null) {$where.=' AND kind=:kind';$params['kind']=$kind;}
        if ($assetId!==null) {$where.=' AND (source_asset_id=:source OR target_asset_id=:target)';$params['source']=$assetId;$params['target']=$assetId;}
        $count=$pdo->prepare('SELECT count(*)'.$where);$count->execute($params);
        $stmt=$pdo->prepare('SELECT '.self::PROJECTION.$where.' ORDER BY created_at DESC,id DESC LIMIT :limit OFFSET :offset');
        foreach($params as $key=>$value)$stmt->bindValue(':'.$key,$value,PDO::PARAM_STR);
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->bindValue(':offset',$offset,PDO::PARAM_INT);$stmt->execute();
        return ['data'=>array_map(self::row(...),$stmt->fetchAll(PDO::FETCH_ASSOC)),
            'pagination'=>['limit'=>$limit,'offset'=>$offset,'total'=>(int)$count->fetchColumn()]];
    }
    /** @param array<string,mixed> $input */
    public function create(string $actor,array $input,?string $requestId=null): array
    {
        if (array_diff(array_keys($input),['workspace_id','source_asset_id','target_asset_id','kind']) || count($input)!==4) {
            throw new HttpException(422,'Invalid relationship fields.','validation_failed');
        }
        $ws=self::uuid($input['workspace_id']??null,'workspace_id');
        $source=self::uuid($input['source_asset_id']??null,'source_asset_id');
        $target=self::uuid($input['target_asset_id']??null,'target_asset_id');
        $kind=self::kind($input['kind']??null);
        [$source,$target]=self::canonical($source,$target,$kind);
        return $this->db->transaction(function(PDO $pdo)use($actor,$ws,$source,$target,$kind,$requestId):array {
            $this->inventory->lockWorkspace($pdo,$ws);
            $this->inventory->workspaceRole($pdo,$ws,$actor,true);
            try {
                $stmt=$pdo->prepare('INSERT INTO inventory_relationships(workspace_id,source_asset_id,target_asset_id,kind) VALUES (:ws,:source,:target,:kind) RETURNING id::text');
                $stmt->execute(['ws'=>$ws,'source'=>$source,'target'=>$target,'kind'=>$kind]);
                $id=(string)$stmt->fetchColumn();
            } catch(PDOException $e) {self::conflict($e);}
            $row=self::load($pdo,$ws,$id)??throw new \RuntimeException('Created relationship missing.');
            $this->audit->record($pdo,$actor,'inventory.relationship.created','success','inventory_relationship',$id,
                ['kind'=>$kind,'workspace_id'=>$ws,'source_asset_id'=>$source,'target_asset_id'=>$target],$requestId);
            return $row;
        },3);
    }
    public function updateKind(string $actor,string $ws,string $id,int $version,string $kind,?string $requestId=null): array
    {
        return $this->mutate($actor,$ws,$id,$version,self::kind($kind),$requestId);
    }
    public function delete(string $actor,string $ws,string $id,int $version,?string $requestId=null): void
    {
        $this->mutate($actor,$ws,$id,$version,null,$requestId);
    }
    private function mutate(string $actor,string $ws,string $id,int $version,?string $kind,?string $requestId): ?array
    {
        $ws=self::uuid($ws,'workspace_id');$id=self::uuid($id);
        if ($version<1) throw new HttpException(400,'Invalid expected version.','invalid_precondition');
        return $this->db->transaction(function(PDO $pdo)use($actor,$ws,$id,$version,$kind,$requestId):?array {
            $this->inventory->lockWorkspace($pdo,$ws);
            $this->inventory->workspaceRole($pdo,$ws,$actor,true);
            $before=self::load($pdo,$ws,$id,true);
            if ($before===null) throw new HttpException(404,'Relationship not found.','not_found');
            if ($before['version']!==$version) throw new HttpException(412,'Relationship has changed.','precondition_failed');
            if ($kind!==null && $kind===$before['kind']) throw new HttpException(409,'Relation kind unchanged.','conflict');
            try {
                if ($kind===null) {
                    $stmt=$pdo->prepare('DELETE FROM inventory_relationships WHERE id=:id AND workspace_id=:ws');
                    $stmt->execute(['id'=>$id,'ws'=>$ws]);
                } else {
                    // connected_to is undirected and has a canonical endpoint order.
                    [$source,$target]=self::canonical($before['source_asset_id'],$before['target_asset_id'],$kind);
                    $stmt=$pdo->prepare('UPDATE inventory_relationships SET kind=:kind,source_asset_id=:source,target_asset_id=:target WHERE id=:id AND workspace_id=:ws');
                    $stmt->execute(['kind'=>$kind,'source'=>$source,'target'=>$target,'id'=>$id,'ws'=>$ws]);
                }
            } catch(PDOException $e) {self::conflict($e);}
            if ($stmt->rowCount()!==1) throw new HttpException(409,'Relationship changed.','conflict');
            $after=$kind===null?null:self::load($pdo,$ws,$id);
            $this->audit->record($pdo,$actor,$kind===null?'inventory.relationship.deleted':'inventory.relationship.updated',
                'success','inventory_relationship',$id,['workspace_id'=>$ws,'old_kind'=>$before['kind'],'new_kind'=>$kind],$requestId);
            return $after;
        },3);
    }
}
