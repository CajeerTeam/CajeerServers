<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** Transactional, workspace-authorized manual CMDB operations. */
final class InventoryService
{
    public function __construct(private readonly DatabaseManager $db, private readonly InventoryRepository $repo, private readonly AuditLogger $audit) {}

    private static function uuid(string $id):string
    {
        if (!InfrastructureAsset::isUuid($id)) throw new HttpException(404,'Asset not found.','not_found');
        return strtolower($id);
    }

    /** @return array<string,mixed> */
    public function read(string $actor,string $id,bool $includeDeleted=false):array
    {
        $row=$this->repo->find($this->db->connection(),$actor,self::uuid($id),$includeDeleted);
        if ($row===null) throw new HttpException(404,'Asset not found.','not_found');
        if ($includeDeleted) $this->repo->workspaceRole($this->db->connection(),(string)$row['workspace_id'],$actor,true);
        return $row;
    }

    /** @return array<string,mixed> */
    public function list(string $actor,string $workspace,int $limit,int $offset,?string $type,?string $status,?string $search,bool $includeDeleted,array $filters=[]):array
    {
        self::uuid($workspace);
        if ($limit<1 || $limit>100 || $offset<0 || $offset>10000 || ($type!==null && !in_array($type,InfrastructureAsset::TYPES,true))
            || ($status!==null && !in_array($status,InfrastructureAsset::STATUSES,true)) || ($search!==null && (strlen($search)>100 || preg_match('/[\x00-\x1f\x7f]/',$search)))) {
            throw new HttpException(422,'Invalid inventory filters.','validation_failed');
        }
        foreach ($filters as $field=>$value) {
            $valid=match($field){
                'tag'=>is_string($value)&&preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$value)===1,
                'group_id','owner_user_id'=>is_string($value)&&InfrastructureAsset::isUuid($value),
                'environment'=>is_string($value)&&preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$value)===1,
                'source'=>is_string($value)&&preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D',$value)===1,
                'os_family'=>is_string($value)&&in_array($value,InfrastructureAsset::OS_FAMILIES,true),
                'sort'=>is_string($value)&&in_array($value,['created_desc','name_asc','updated_desc'],true),
                'cursor'=>is_string($value)&&strlen($value)<=200,
                default=>false,
            };
            if(!$valid)throw new HttpException(422,'Invalid filter: '.$field,'validation_failed');
        }
        $pdo=$this->db->connection();
        $role=$this->repo->workspaceRole($pdo,$workspace,$actor);
        if ($includeDeleted && !in_array($role,['owner','manager'],true)) throw new HttpException(403,'Workspace role insufficient.','permission_denied');
        $page=$this->repo->page($pdo,$actor,$workspace,$limit,$offset,$type,$status,$search,$includeDeleted,$filters);
        return [...$page,'pagination'=>['limit'=>$limit,'offset'=>$offset,'total'=>$page['total'],'next_cursor'=>$page['next_cursor']??null]];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function create(string $actor,array $data,?string $requestId=null):array
    {
        $workspace=self::uuid((string)($data['workspace_id']??''));
        $normalized=InfrastructureAsset::create($data);
        return $this->db->transaction(function(PDO $pdo)use($actor,$workspace,$normalized,$requestId):array {
            $this->repo->lockWorkspace($pdo,$workspace);
            $this->repo->workspaceRole($pdo,$workspace,$actor,true);
            $this->repo->assertOwner($pdo,$workspace,$normalized['owner_user_id']);
            HistoryService::context($pdo,$actor,'manual',$requestId);
            try {$id=$this->repo->insert($pdo,$workspace,$normalized);}
            catch (\PDOException $e) { if ($e->getCode()==='23505') throw new HttpException(409,'Asset identity conflict.','conflict'); throw $e; }
            $row=$this->repo->find($pdo,$actor,$id);
            if ($row===null) throw new \RuntimeException('Created asset could not be retrieved.');
            $this->audit->record($pdo,$actor,'inventory.asset.created','success','inventory_asset',$id,['source'=>'manual'],$requestId);
            return $row;
        });
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    public function update(string $actor,string $id,int $version,array $changes,?string $requestId=null):array
    {
        $patch=InfrastructureAsset::changes($changes);
        return $this->mutate($actor,$id,$version,'update',$patch,$requestId);
    }

    /** @return array<string,mixed> */
    public function delete(string $actor,string $id,int $version,?string $requestId=null):array
    {
        return $this->mutate($actor,$id,$version,'delete',[],$requestId);
    }

    /** @return array<string,mixed> */
    public function restore(string $actor,string $id,int $version,?string $requestId=null):array
    {
        return $this->mutate($actor,$id,$version,'restore',[],$requestId);
    }

    /** @param array<string,mixed> $patch @return array<string,mixed> */
    private function mutate(string $actor,string $id,int $version,string $operation,array $patch,?string $requestId):array
    {
        $id=self::uuid($id);
        if ($version<1) throw new HttpException(400,'Invalid asset version.','invalid_precondition');
        return $this->db->transaction(function(PDO $pdo)use($actor,$id,$version,$operation,$patch,$requestId):array {
            // Read the authorized workspace ID first, then lock the workspace to
            // serialize changes with membership updates and cross-workspace transfers.
            $visible=$this->repo->find($pdo,$actor,$id,true);
            if ($visible===null) throw new HttpException(404,'Asset not found.','not_found');
            $workspace=(string)$visible['workspace_id'];
            $this->repo->lockWorkspace($pdo,$workspace);
            $this->repo->workspaceRole($pdo,$workspace,$actor,true);
            $row=$this->repo->find($pdo,$actor,$id,true,true);
            if ($row===null || $row['workspace_id']!==$workspace) throw new HttpException(409,'Asset moved during update.','conflict');
            if ($row['source']!=='manual') throw new HttpException(403,'Externally managed assets cannot be edited manually.','permission_denied');
            if ($version!==$row['version']) throw new HttpException(412,'Asset has changed. Reload before retrying.','precondition_failed');
            if ($operation==='restore' && $row['deleted_at']===null) throw new HttpException(409,'Asset is not deleted.','conflict');
            if ($operation!=='restore' && $row['deleted_at']!==null) throw new HttpException(404,'Asset not found.','not_found');
            HistoryService::context($pdo,$actor,'manual',$requestId);
            if ($operation==='update') {
                if (array_key_exists('owner_user_id',$patch)) $this->repo->assertOwner($pdo,$workspace,$patch['owner_user_id']);
                $this->repo->update($pdo,$id,$patch);
            } else {
                try {$this->repo->setDeleted($pdo,$id,$operation==='delete');}
                catch (\PDOException $e) {
                    if (in_array((string)($e->errorInfo[0]??$e->getCode()),['23503','23514'],true))
                        throw new HttpException(409,'Detach inventory relationships and IPAM references before deleting this asset.','conflict');
                    throw $e;
                }
            }
            $updated=$this->repo->find($pdo,$actor,$id,true);
            if ($updated===null) throw new \RuntimeException('Updated asset is missing.');
            $this->audit->record($pdo,$actor,'inventory.asset.'.$operation.'d','success','inventory_asset',$id,['source'=>'manual'],$requestId);
            return $updated;
        });
    }
}
