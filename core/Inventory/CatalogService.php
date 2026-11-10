<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;
use PDOException;

/** Actual workspace-scoped tag, group and group-membership operations. */
final class CatalogService
{
    public function __construct(private readonly DatabaseManager $db, private readonly InventoryRepository $inventory, private readonly AuditLogger $audit) {}

    public static function uuid(mixed $value,string $field='id'):string
    {
        if(!is_string($value)||!InfrastructureAsset::isUuid($value))throw new HttpException(422,"Invalid $field.",'validation_failed');
        return strtolower($value);
    }
    public static function slug(mixed $value):string
    {
        if(!is_string($value)||preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$value)!==1)
            throw new HttpException(422,'Invalid slug: lowercase letters, digits, dots, underscores and hyphens only.','validation_failed');
        return $value;
    }
    private static function validUnicodeLength(string $value,int $max):bool
    {
        if(strlen($value)>$max*4)return false;
        $length=preg_match_all('/./us',$value);
        return $length!==false && $length<=$max;
    }
    private static function name(mixed $value):string
    {
        if(!is_string($value)||($value=trim($value))===''||!self::validUnicodeLength($value,128)
            ||preg_match('/[\x00-\x1f\x7f]/',$value))
            throw new HttpException(422,'Invalid catalog display name.','validation_failed');
        return $value;
    }
    private static function description(mixed $value):string
    {
        if(!is_string($value)||!self::validUnicodeLength($value,1024)||str_contains($value,"\0"))
            throw new HttpException(422,'Invalid catalog description.','validation_failed');
        return $value;
    }
    public static function fields(array $data,bool $create):array
    {
        $allowed=$create?['workspace_id','slug','name','description']:['name','description'];
        if(array_diff(array_keys($data),$allowed)||!$data)
            throw new HttpException(422,'Invalid catalog fields.','validation_failed');
        if($create&&(!isset($data['workspace_id'],$data['slug'],$data['name'])))
            throw new HttpException(422,'Missing catalog fields.','validation_failed');
        $out=[];
        if(isset($data['workspace_id']))$out['workspace_id']=self::uuid($data['workspace_id'],'workspace_id');
        if(isset($data['slug']))$out['slug']=self::slug($data['slug']);
        if(array_key_exists('name',$data))$out['name']=self::name($data['name']);
        if(array_key_exists('description',$data))$out['description']=self::description($data['description']);
        if($create)$out['description']??='';
        return $out;
    }
    private static function table(string $kind):string
    {
        return match($kind){'tags'=>'inventory_tags','groups'=>'inventory_groups',default=>throw new \LogicException('Invalid catalog kind')};
    }
    private static function projection(string $kind):string
    {
        return 'id::text,workspace_id::text,slug,name,description,version,created_at::text,updated_at::text';
    }
    private static function etag(array $row):array
    {
        if(isset($row['version'])){
            $row['version']=(int)$row['version'];
            $row['etag']=InfrastructureAsset::etag((string)$row['id'],$row['version']);
        }
        if(isset($row['asset_count']))$row['asset_count']=(int)$row['asset_count'];
        return $row;
    }
    public function list(string $actor,string $workspace,string $kind,int $limit=100,int $offset=0):array
    {
        $workspace=self::uuid($workspace,'workspace_id');
        if($limit<1||$limit>100||$offset<0||$offset>10000)throw new HttpException(422,'Invalid pagination.','validation_failed');
        $pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        $table=self::table($kind);
        $link=$kind==='groups'?'inventory_group_assets':'inventory_asset_tags';
        $fk=$kind==='groups'?'group_id':'tag_id';
        $query="SELECT ".self::projection($kind).", (SELECT count(*) FROM $link l WHERE l.$fk=$table.id) AS asset_count FROM $table WHERE workspace_id=:ws ORDER BY slug,id LIMIT :limit OFFSET :offset";
        $stmt=$pdo->prepare($query);$stmt->bindValue(':ws',$workspace);
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
        $stmt->execute();
        $count=$pdo->prepare("SELECT count(*) FROM $table WHERE workspace_id=:ws");$count->execute(['ws'=>$workspace]);
        return ['data'=>array_map([self::class,'etag'],$stmt->fetchAll(PDO::FETCH_ASSOC)),
            'pagination'=>['limit'=>$limit,'offset'=>$offset,'total'=>(int)$count->fetchColumn()]];
    }
    private static function load(PDO $pdo,string $kind,string $workspace,string $id,bool $lock=false):?array
    {
        $table=self::table($kind);
        $stmt=$pdo->prepare('SELECT '.self::projection($kind)." FROM $table WHERE id=:id AND workspace_id=:ws".($lock?' FOR UPDATE':''));
        $stmt->execute(['id'=>$id,'ws'=>$workspace]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?self::etag($row):null;
    }
    public function read(string $actor,string $workspace,string $kind,string $id):array
    {
        $workspace=self::uuid($workspace,'workspace_id');$id=self::uuid($id);
        $pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        return self::load($pdo,$kind,$workspace,$id)??throw new HttpException(404,'Catalog entry not found.','not_found');
    }
    private static function conflict(PDOException $e):never
    {
        if(in_array((string)($e->errorInfo[0]??$e->getCode()),['23505','23503','23514'],true))
            throw new HttpException(409,'Catalog constraint conflict.','conflict');
        throw $e;
    }
    public function create(string $actor,string $kind,array $input,?string $requestId=null):array
    {
        $data=self::fields($input,true);$workspace=$data['workspace_id'];
        return $this->db->transaction(function(PDO $pdo)use($actor,$kind,$data,$workspace,$requestId):array{
            $this->inventory->lockWorkspace($pdo,$workspace);$this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $table=self::table($kind);
            try {
                $stmt=$pdo->prepare("INSERT INTO $table(workspace_id,slug,name,description) VALUES (:ws,:slug,:name,:description) RETURNING id::text");
                $stmt->execute(['ws'=>$workspace,'slug'=>$data['slug'],'name'=>$data['name'],'description'=>$data['description']]);
            }catch(PDOException $e){self::conflict($e);}
            $id=(string)$stmt->fetchColumn();$entry=self::load($pdo,$kind,$workspace,$id);
            if($entry===null)throw new \RuntimeException('Catalog entry not persisted.');
            $this->audit->record($pdo,$actor,"inventory.$kind.created",'success',"inventory_$kind",$id,['workspace_id'=>$workspace],$requestId);
            return $entry;
        },3);
    }
    public function update(string $actor,string $kind,string $workspace,string $id,int $version,array $input,?string $requestId=null):array
    {
        $data=self::fields($input,false);$workspace=self::uuid($workspace,'workspace_id');$id=self::uuid($id);
        return $this->db->transaction(function(PDO $pdo)use($actor,$kind,$workspace,$id,$version,$data,$requestId):array{
            $this->inventory->lockWorkspace($pdo,$workspace);$this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $before=self::load($pdo,$kind,$workspace,$id,true);
            if($before===null)throw new HttpException(404,'Group not found.','not_found');
            if($before['version']!==$version)throw new HttpException(412,'Group changed.','precondition_failed');
            $sets=[];$params=['ws'=>$workspace,'id'=>$id];
            foreach($data as $key=>$value){$sets[]="$key=:$key";$params[$key]=$value;}
            $table=self::table($kind);
            $stmt=$pdo->prepare('UPDATE '.$table.' SET '.implode(',',$sets).' WHERE workspace_id=:ws AND id=:id');
            $stmt->execute($params);
            $entry=self::load($pdo,$kind,$workspace,$id);
            $this->audit->record($pdo,$actor,'inventory.'.$kind.'.updated','success','inventory_'.$kind,$id,['workspace_id'=>$workspace],$requestId);
            return $entry;
        },3);
    }
    public function delete(string $actor,string $kind,string $workspace,string $id,?int $version=null,?string $requestId=null):void
    {
        $workspace=self::uuid($workspace,'workspace_id');$id=self::uuid($id);
        $this->db->transaction(function(PDO $pdo)use($actor,$kind,$workspace,$id,$version,$requestId):void{
            $this->inventory->lockWorkspace($pdo,$workspace);$this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $entry=self::load($pdo,$kind,$workspace,$id,true);
            if($entry===null)throw new HttpException(404,'Catalog entry not found.','not_found');
            if($version!==$entry['version'])throw new HttpException(412,'Group changed.','precondition_failed');
            $table=self::table($kind);$links=$kind==='groups'?'inventory_group_assets':'inventory_asset_tags';$fk=$kind==='groups'?'group_id':'tag_id';
            $count=$pdo->prepare("SELECT 1 FROM $links WHERE $fk=:id LIMIT 1");$count->execute(['id'=>$id]);
            if($count->fetchColumn())throw new HttpException(409,'Detach assigned assets before deleting catalog entry.','conflict');
            try {$stmt=$pdo->prepare("DELETE FROM $table WHERE id=:id AND workspace_id=:ws");$stmt->execute(['id'=>$id,'ws'=>$workspace]);}
            catch(PDOException $e){self::conflict($e);}
            $this->audit->record($pdo,$actor,"inventory.$kind.deleted",'success',"inventory_$kind",$id,['workspace_id'=>$workspace],$requestId);
        },3);
    }
    public function groupAssets(string $actor,string $workspace,string $group,int $limit=100,int $offset=0):array
    {
        $workspace=self::uuid($workspace,'workspace_id');$group=self::uuid($group,'group_id');
        if($limit<1||$limit>100||$offset<0||$offset>10000)throw new HttpException(422,'Invalid pagination.','validation_failed');
        $pdo=$this->db->connection();$this->inventory->workspaceRole($pdo,$workspace,$actor);
        if(self::load($pdo,'groups',$workspace,$group)===null)throw new HttpException(404,'Group not found.','not_found');
        $stmt=$pdo->prepare('SELECT a.id::text,a.name,a.type,a.status,a.deleted_at::text FROM inventory_group_assets x JOIN inventory_assets a ON a.id=x.asset_id AND a.workspace_id=x.workspace_id WHERE x.workspace_id=:ws AND x.group_id=:gid ORDER BY a.name,a.id LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':ws',$workspace);$stmt->bindValue(':gid',$group);
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->bindValue(':offset',$offset,PDO::PARAM_INT);$stmt->execute();
        return ['data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function groupMembership(string $actor,string $workspace,string $group,string $asset,int $version,bool $attach,?string $requestId=null):array
    {
        $workspace=self::uuid($workspace,'workspace_id');$group=self::uuid($group,'group_id');$asset=self::uuid($asset,'asset_id');
        return $this->db->transaction(function(PDO $pdo)use($actor,$workspace,$group,$asset,$version,$attach,$requestId):array{
            $this->inventory->lockWorkspace($pdo,$workspace);$this->inventory->workspaceRole($pdo,$workspace,$actor,true);
            $entry=self::load($pdo,'groups',$workspace,$group,true);
            if($entry===null)throw new HttpException(404,'Group not found.','not_found');
            if($entry['version']!==$version)throw new HttpException(412,'Group membership has changed.','precondition_failed');
            $stmt=$pdo->prepare('SELECT deleted_at FROM inventory_assets WHERE id=:asset AND workspace_id=:ws FOR UPDATE');
            $stmt->execute(['asset'=>$asset,'ws'=>$workspace]);$assetRow=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($assetRow)||($attach&&$assetRow['deleted_at']!==null))throw new HttpException(404,'Asset not found or deleted.','not_found');
            if($attach){
                $stmt=$pdo->prepare('INSERT INTO inventory_group_assets(workspace_id,group_id,asset_id) VALUES(:ws,:gid,:asset) ON CONFLICT DO NOTHING');
            }else{
                $stmt=$pdo->prepare('DELETE FROM inventory_group_assets WHERE workspace_id=:ws AND group_id=:gid AND asset_id=:asset');
            }
            $stmt->execute(['ws'=>$workspace,'gid'=>$group,'asset'=>$asset]);
            if($stmt->rowCount()!==1)throw new HttpException(409,'Membership already in requested state.','conflict');
            $after=self::load($pdo,'groups',$workspace,$group);
            $this->audit->record($pdo,$actor,$attach?'inventory.group.asset.attached':'inventory.group.asset.detached','success','inventory_groups',$group,
                ['workspace_id'=>$workspace,'asset_id'=>$asset],$requestId);
            return $after;
        },3);
    }
}
