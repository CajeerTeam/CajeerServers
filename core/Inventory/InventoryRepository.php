<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** PostgreSQL persistence with workspace-scoped reads, bounded search and optimistic versions. */
final class InventoryRepository
{
    private const PROJECTION = <<<'SQL'
a.id::text,a.workspace_id::text,a.owner_user_id::text,a.type,a.source,a.external_id,a.name,a.status,a.os_family,
a.ip_addresses::text,a.tags::text,a.attributes::text,a.version,a.created_at::text,a.updated_at::text,a.deleted_at::text
SQL;

    public function workspaceRole(PDO $pdo,string $workspace,string $actor,bool $write=false):string
    {
        $stmt=$pdo->prepare('SELECT wm.role FROM workspace_members wm JOIN users u ON u.id=wm.user_id AND u.is_active=true WHERE wm.workspace_id=:ws AND wm.user_id=:actor');
        $stmt->execute(['ws'=>$workspace,'actor'=>$actor]);
        $role=$stmt->fetchColumn();
        if (!is_string($role)) throw new HttpException(404,'Workspace not found.','not_found');
        if ($write && !in_array($role,['owner','manager'],true)) throw new HttpException(403,'Workspace role insufficient.','permission_denied');
        return $role;
    }

    public function lockWorkspace(PDO $pdo,string $workspace):void
    {
        $stmt=$pdo->prepare('SELECT id FROM workspaces WHERE id=:id FOR UPDATE');
        $stmt->execute(['id'=>$workspace]);
        if (!$stmt->fetchColumn()) throw new HttpException(404,'Workspace not found.','not_found');
    }

    public function assertOwner(PDO $pdo,string $workspace,?string $owner):void
    {
        if ($owner!==null) $this->workspaceRole($pdo,$workspace,$owner);
    }

    /** @return array<string,mixed>|null */
    public function find(PDO $pdo,string $actor,string $id,bool $includeDeleted=false,bool $lock=false):?array
    {
        $sql='SELECT '.self::PROJECTION.' FROM inventory_assets a JOIN workspace_members wm ON wm.workspace_id=a.workspace_id AND wm.user_id=:actor JOIN users u ON u.id=wm.user_id AND u.is_active=true WHERE a.id=:id';
        if (!$includeDeleted) $sql.=' AND a.deleted_at IS NULL';
        if ($lock) $sql.=' FOR UPDATE OF a';
        $stmt=$pdo->prepare($sql);
        $stmt->execute(['actor'=>$actor,'id'=>$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?self::hydrate($row):null;
    }

    /** @return array{data:list<array<string,mixed>>,total:int} */
    public function page(PDO $pdo,string $actor,string $workspace,int $limit,int $offset,?string $type=null,?string $status=null,?string $search=null,bool $includeDeleted=false):array
    {
        $where=' FROM inventory_assets a JOIN workspace_members wm ON wm.workspace_id=a.workspace_id AND wm.user_id=:actor JOIN users u ON u.id=wm.user_id AND u.is_active=true WHERE a.workspace_id=:workspace';
        $params=['actor'=>$actor,'workspace'=>$workspace];
        if (!$includeDeleted) $where.=' AND a.deleted_at IS NULL';
        if ($type!==null) {$where.=' AND a.type=:type';$params['type']=$type;}
        if ($status!==null) {$where.=' AND a.status=:status';$params['status']=$status;}
        if ($search!==null && $search!=='') {
            $where.=" AND a.name ILIKE :search ESCAPE '!'";
            $params['search']='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
        }
        $count=$pdo->prepare('SELECT count(*)'.$where);
        $count->execute($params);
        $total=(int)$count->fetchColumn();
        $stmt=$pdo->prepare('SELECT '.self::PROJECTION.$where.' ORDER BY a.created_at DESC,a.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $key=>$value) $stmt->bindValue(':'.$key,$value,PDO::PARAM_STR);
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
        $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return ['data'=>array_map([self::class,'hydrate'],$stmt->fetchAll(PDO::FETCH_ASSOC)),'total'=>$total];
    }

    /** @param array<string,mixed> $data */
    public function insert(PDO $pdo,string $workspace,array $data):string
    {
        $stmt=$pdo->prepare(<<<'SQL'
INSERT INTO inventory_assets(workspace_id,owner_user_id,type,source,external_id,name,status,os_family,ip_addresses,tags,attributes)
VALUES (CAST(:workspace AS uuid),CAST(:owner AS uuid),:type,'manual',NULL,:name,:status,:os,CAST(:ips AS jsonb),CAST(:tags AS jsonb),CAST(:attrs AS jsonb)) RETURNING id::text
SQL);
        $stmt->execute(['workspace'=>$workspace,'owner'=>$data['owner_user_id'],'type'=>$data['type'],'name'=>$data['name'],'status'=>$data['status'],
            'os'=>$data['os_family'],'ips'=>json_encode($data['ip_addresses'],JSON_THROW_ON_ERROR),
            'tags'=>json_encode($data['tags'],JSON_THROW_ON_ERROR),'attrs'=>json_encode($data['attributes']===[]?(object)[]:$data['attributes'],JSON_THROW_ON_ERROR)]);
        $id=$stmt->fetchColumn();
        if (!is_string($id)) throw new \RuntimeException('Asset insert returned no ID.');
        return $id;
    }

    /** @param array<string,mixed> $patch */
    public function update(PDO $pdo,string $id,array $patch):void
    {
        $columns=['name'=>'name','status'=>'status','os_family'=>'os_family','owner_user_id'=>'owner_user_id','ip_addresses'=>'ip_addresses','tags'=>'tags','attributes'=>'attributes'];
        $sets=[];$params=['id'=>$id];
        foreach ($patch as $key=>$value) {
            if (!isset($columns[$key])) throw new \LogicException('Invalid column name.');
            $column=$columns[$key];
            if (in_array($key,['ip_addresses','tags','attributes'],true)) {$sets[]=$column.'=CAST(:'.$key.' AS jsonb)';$value=json_encode($key==='attributes' && $value===[]?(object)[]:$value,JSON_THROW_ON_ERROR);}
            elseif ($key==='owner_user_id') $sets[]=$column.'=CAST(:'.$key.' AS uuid)';
            else $sets[]=$column.'=:'.$key;
            $params[$key]=$value;
        }
        if (!$sets) throw new \LogicException('Empty patch.');
        $stmt=$pdo->prepare('UPDATE inventory_assets SET '.implode(',',$sets).' WHERE id=:id AND deleted_at IS NULL');
        $stmt->execute($params);
        if ($stmt->rowCount()!==1) throw new HttpException(404,'Asset not found.','not_found');
    }

    public function setDeleted(PDO $pdo,string $id,bool $deleted):void
    {
        $stmt=$pdo->prepare('UPDATE inventory_assets SET deleted_at='.($deleted?'now()':'NULL').' WHERE id=:id AND deleted_at IS '.($deleted?'NULL':'NOT NULL'));
        $stmt->execute(['id'=>$id]);
        if ($stmt->rowCount()!==1) throw new HttpException(409,'Asset state changed.','conflict');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function hydrate(array $row):array
    {
        foreach (['ip_addresses','tags','attributes'] as $key) $row[$key]=json_decode((string)$row[$key],true,64,JSON_THROW_ON_ERROR);
        $row['version']=(int)$row['version'];
        $row['etag']=InfrastructureAsset::etag((string)$row['id'],$row['version']);
        return $row;
    }
}
