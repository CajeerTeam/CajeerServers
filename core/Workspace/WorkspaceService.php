<?php
declare(strict_types=1);

namespace Cajeer\Servers\Workspace;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** PostgreSQL-backed organization/workspace ownership and resource isolation. */
final class WorkspaceService
{
    public function __construct(private readonly DatabaseManager $db, private readonly AuditLogger $audit) {}

    public static function uuid(string $value): string
    {
        if (preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $value) !== 1) {
            throw new HttpException(404, 'Resource not found.', 'not_found');
        }
        return $value;
    }

    public static function details(string $slug, string $name): array
    {
        $name = trim($name);
        if (preg_match('/^[a-z][a-z0-9-]{2,63}$/D', $slug) !== 1 || $name === '' || strlen($name) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new HttpException(422, 'Invalid slug or name.', 'validation_failed');
        }
        return [$slug, $name];
    }

    private function readMembership(PDO $pdo, string $workspaceId, string $userId, bool $lock = false): string
    {
        $sql = <<<'SQL'
SELECT wm.role FROM workspace_members wm JOIN users u ON u.id=wm.user_id
WHERE wm.workspace_id=:workspace AND wm.user_id=:user AND u.is_active=true
SQL;
        $stmt = $pdo->prepare($sql . ($lock ? ' FOR UPDATE OF wm' : ''));
        $stmt->execute(['workspace'=>$workspaceId, 'user'=>$userId]);
        $role = $stmt->fetchColumn();
        if (!is_string($role)) throw new HttpException(404, 'Workspace not found.', 'not_found');
        return $role;
    }

    private function requireRole(PDO $pdo, string $workspaceId, string $userId, array $roles, bool $lock = false): string
    {
        $role = $this->readMembership($pdo, $workspaceId, $userId, $lock);
        if (!in_array($role, $roles, true)) throw new HttpException(403, 'Workspace role insufficient.', 'permission_denied');
        return $role;
    }

    private function workspaceLock(PDO $pdo, string $workspaceId): array
    {
        $stmt = $pdo->prepare('SELECT id::text,organization_id::text,slug,name FROM workspaces WHERE id=:id FOR UPDATE');
        $stmt->execute(['id'=>$workspaceId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new HttpException(404, 'Workspace not found.', 'not_found');
        return $row;
    }

    /** Only organizations which contain a workspace the active user belongs to are visible. */
    public function organizations(string $userId): array
    {
        $stmt=$this->db->connection()->prepare(<<<'SQL'
SELECT DISTINCT o.id::text,o.slug,o.name,o.created_at::text
FROM organizations o JOIN workspaces w ON w.organization_id=o.id
JOIN workspace_members wm ON wm.workspace_id=w.id
JOIN users u ON u.id=wm.user_id
WHERE wm.user_id=:user AND u.is_active=true ORDER BY o.slug LIMIT 200
SQL);
        $stmt->execute(['user'=>$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createOrganization(string $userId, string $slug, string $name, ?string $requestId = null): array
    {
        [$slug,$name]=self::details($slug,$name);
        return $this->db->transaction(function(PDO $pdo) use($userId,$slug,$name,$requestId):array {
            $stmt=$pdo->prepare('INSERT INTO organizations(slug,name,created_by) VALUES (:slug,:name,:creator) RETURNING id::text,slug,name');
            try { $stmt->execute(['slug'=>$slug,'name'=>$name,'creator'=>$userId]); }
            catch (\PDOException $e) { if ($e->getCode()==='23505') throw new HttpException(409,'Organization slug already exists.','conflict'); throw $e; }
            $org=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($org)) throw new \RuntimeException('Could not create organization.');
            $ws=$pdo->prepare("INSERT INTO workspaces(organization_id,slug,name) VALUES (:org,'default','Default workspace') RETURNING id::text");
            $ws->execute(['org'=>$org['id']]);
            $workspace=(string)$ws->fetchColumn();
            $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES (:workspace,:user,'owner')")
                ->execute(['workspace'=>$workspace,'user'=>$userId]);
            $this->audit->record($pdo,$userId,'organization.created','success','organization',$org['id'],[], $requestId);
            $this->audit->record($pdo,$userId,'workspace.created','success','workspace',$workspace,[], $requestId);
            return [...$org,'default_workspace_id'=>$workspace];
        });
    }

    public function renameOrganization(string $userId, string $orgId, string $name, ?string $requestId = null): array
    {
        self::uuid($orgId); [, $name]=self::details('placeholder',$name);
        return $this->db->transaction(function(PDO $pdo) use($userId,$orgId,$name,$requestId):array {
            $org=$pdo->prepare('SELECT id::text FROM organizations WHERE id=:id FOR UPDATE');$org->execute(['id'=>$orgId]);
            if (!$org->fetchColumn()) throw new HttpException(404,'Organization not found.','not_found');
            $stmt=$pdo->prepare(<<<'SQL'
SELECT 1 FROM workspaces w JOIN workspace_members wm ON wm.workspace_id=w.id
JOIN users u ON u.id=wm.user_id
WHERE w.organization_id=:org AND wm.user_id=:user AND wm.role='owner' AND u.is_active=true LIMIT 1
SQL);
            $stmt->execute(['org'=>$orgId,'user'=>$userId]);
            if (!$stmt->fetchColumn()) throw new HttpException(404,'Organization not found.','not_found');
            $up=$pdo->prepare('UPDATE organizations SET name=:name,updated_at=now() WHERE id=:id RETURNING id::text,slug,name');
            $up->execute(['name'=>$name,'id'=>$orgId]);
            $this->audit->record($pdo,$userId,'organization.renamed','success','organization',$orgId,[], $requestId);
            return $up->fetch(PDO::FETCH_ASSOC);
        });
    }

    public function workspaces(string $userId, ?string $organizationId = null): array
    {
        if ($organizationId !== null) self::uuid($organizationId);
        $stmt=$this->db->connection()->prepare(<<<'SQL'
SELECT w.id::text,w.organization_id::text,w.slug,w.name,wm.role,w.created_at::text
FROM workspaces w JOIN workspace_members wm ON wm.workspace_id=w.id
JOIN users u ON u.id=wm.user_id
WHERE wm.user_id=:user AND u.is_active=true AND (CAST(:org_filter AS uuid) IS NULL OR w.organization_id=CAST(:org_id AS uuid))
ORDER BY w.created_at,w.id LIMIT 250
SQL);
        $stmt->execute(['user'=>$userId,'org_filter'=>$organizationId,'org_id'=>$organizationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createWorkspace(string $userId, string $orgId, string $slug, string $name, ?string $requestId=null): array
    {
        self::uuid($orgId); [$slug,$name]=self::details($slug,$name);
        return $this->db->transaction(function(PDO $pdo) use($userId,$orgId,$slug,$name,$requestId):array {
            $lock=$pdo->prepare('SELECT id FROM organizations WHERE id=:id FOR UPDATE');$lock->execute(['id'=>$orgId]);
            if (!$lock->fetchColumn()) throw new HttpException(404,'Organization not found.','not_found');
            $member=$pdo->prepare(<<<'SQL'
SELECT 1 FROM workspace_members wm JOIN workspaces w ON w.id=wm.workspace_id
JOIN users u ON u.id=wm.user_id
WHERE w.organization_id=:org AND wm.user_id=:user AND wm.role='owner' AND u.is_active=true LIMIT 1
SQL);
            $member->execute(['org'=>$orgId,'user'=>$userId]);
            if (!$member->fetchColumn()) throw new HttpException(404,'Organization not found.','not_found');
            $stmt=$pdo->prepare('INSERT INTO workspaces(organization_id,slug,name) VALUES (:org,:slug,:name) RETURNING id::text,organization_id::text,slug,name');
            try {$stmt->execute(['org'=>$orgId,'slug'=>$slug,'name'=>$name]);}
            catch (\PDOException $e) {if ($e->getCode()==='23505') throw new HttpException(409,'Workspace slug already exists.','conflict');throw $e;}
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES (:workspace,:user,'owner')")
                ->execute(['workspace'=>$row['id'],'user'=>$userId]);
            $this->audit->record($pdo,$userId,'workspace.created','success','workspace',$row['id'],[], $requestId);
            return [...$row,'role'=>'owner'];
        });
    }

    public function renameWorkspace(string $userId,string $workspaceId,string $name,?string $requestId=null):array
    {
        self::uuid($workspaceId); [, $name]=self::details('placeholder',$name);
        return $this->db->transaction(function(PDO $pdo) use($userId,$workspaceId,$name,$requestId):array {
            $this->workspaceLock($pdo,$workspaceId);
            $this->requireRole($pdo,$workspaceId,$userId,['owner','manager']);
            $stmt=$pdo->prepare('UPDATE workspaces SET name=:name,updated_at=now() WHERE id=:id RETURNING id::text,organization_id::text,slug,name');
            $stmt->execute(['name'=>$name,'id'=>$workspaceId]);
            $this->audit->record($pdo,$userId,'workspace.renamed','success','workspace',$workspaceId,[], $requestId);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        });
    }

    public function members(string $userId,string $workspaceId):array
    {
        self::uuid($workspaceId);$pdo=$this->db->connection();
        $this->readMembership($pdo,$workspaceId,$userId);
        $stmt=$pdo->prepare(<<<'SQL'
SELECT u.id::text AS user_id,u.name,u.email,u.is_active,wm.role,wm.added_at::text
FROM workspace_members wm JOIN users u ON u.id=wm.user_id
WHERE wm.workspace_id=:id ORDER BY CASE wm.role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END,u.name LIMIT 250
SQL);
        $stmt->execute(['id'=>$workspaceId]);
        return array_map(static function(array $row):array {
            $row['is_active'] = in_array($row['is_active'],[true,'t','1',1],true);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Workspace row lock serializes concurrent owner removals, guaranteeing at least one active owner. */
    public function setMember(string $actor,string $workspaceId,string $userId,?string $role,?string $requestId=null):void
    {
        self::uuid($workspaceId); self::uuid($userId);
        if ($role !== null && !in_array($role,['owner','manager','viewer'],true)) throw new HttpException(422,'Invalid workspace role.','validation_failed');
        $this->db->transaction(function(PDO $pdo) use($actor,$workspaceId,$userId,$role,$requestId):void {
            $this->workspaceLock($pdo,$workspaceId);
            $this->requireRole($pdo,$workspaceId,$actor,['owner']);
            $prev=$pdo->prepare('SELECT role FROM workspace_members WHERE workspace_id=:ws AND user_id=:user FOR UPDATE');
            $prev->execute(['ws'=>$workspaceId,'user'=>$userId]);$old=$prev->fetchColumn();
            if ($role !== null) {
                $check=$pdo->prepare('SELECT 1 FROM users WHERE id=:user AND is_active=true FOR SHARE');$check->execute(['user'=>$userId]);
                if (!$check->fetchColumn()) throw new HttpException(404,'User not found or inactive.','not_found');
            }
            if ($old === 'owner' && $role !== 'owner') {
                $count=$pdo->prepare(<<<'SQL'
SELECT count(*) FROM workspace_members wm JOIN users u ON u.id=wm.user_id
WHERE wm.workspace_id=:ws AND wm.role='owner' AND u.is_active=true
SQL);
                $count->execute(['ws'=>$workspaceId]);
                if ((int)$count->fetchColumn()<=1) throw new HttpException(409,'Cannot remove the last active workspace owner.','conflict');
            }
            if ($role===null) {
                $owned=$pdo->prepare('SELECT 1 FROM inventory_assets WHERE workspace_id=:ws AND owner_user_id=:user LIMIT 1');
                $owned->execute(['ws'=>$workspaceId,'user'=>$userId]);
                if ($owned->fetchColumn()) throw new HttpException(409,'Reassign owned assets before removing membership.','conflict');
                if ($old === false) throw new HttpException(404,'Workspace member not found.','not_found');
                $pdo->prepare('DELETE FROM workspace_members WHERE workspace_id=:ws AND user_id=:user')->execute(['ws'=>$workspaceId,'user'=>$userId]);
            } else {
                $pdo->prepare(<<<'SQL'
INSERT INTO workspace_members(workspace_id,user_id,role) VALUES (:ws,:user,:role)
ON CONFLICT (workspace_id,user_id) DO UPDATE SET role=EXCLUDED.role
SQL)->execute(['ws'=>$workspaceId,'user'=>$userId,'role'=>$role]);
            }
            $this->audit->record($pdo,$actor,$role===null?'workspace.member.removed':'workspace.member.assigned','success','workspace',$workspaceId,['role'=>$role??'removed'], $requestId);
        });
    }

    /** Scoped, read-only listing until Inventory CRUD arrives in 0.2.3. */
    public function assets(string $actor,string $workspaceId,int $limit=50,int $offset=0):array
    {
        self::uuid($workspaceId);
        if ($limit<1 || $limit>100 || $offset<0 || $offset>10000) throw new HttpException(422,'Invalid pagination.','validation_failed');
        $pdo=$this->db->connection();$this->readMembership($pdo,$workspaceId,$actor);
        $stmt=$pdo->prepare(<<<'SQL'
SELECT id::text,type,source,name,status,owner_user_id::text,created_at::text
FROM inventory_assets WHERE workspace_id=:ws AND deleted_at IS NULL ORDER BY created_at DESC,id DESC LIMIT :limit OFFSET :offset
SQL);
        $stmt->bindValue(':ws',$workspaceId);$stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->bindValue(':offset',$offset,PDO::PARAM_INT);$stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function assignOwner(string $actor,string $workspaceId,string $assetId,?string $owner,?string $requestId=null):array
    {
        self::uuid($workspaceId);self::uuid($assetId);if($owner!==null)self::uuid($owner);
        return $this->db->transaction(function(PDO $pdo) use($actor,$workspaceId,$assetId,$owner,$requestId):array {
            $this->workspaceLock($pdo,$workspaceId);
            $this->requireRole($pdo,$workspaceId,$actor,['owner','manager']);
            if($owner!==null) $this->readMembership($pdo,$workspaceId,$owner);
            $stmt=$pdo->prepare('UPDATE inventory_assets SET owner_user_id=CAST(:owner AS uuid),updated_at=now() WHERE id=:asset AND workspace_id=:ws AND deleted_at IS NULL RETURNING id::text,workspace_id::text,owner_user_id::text');
            $stmt->execute(['owner'=>$owner,'asset'=>$assetId,'ws'=>$workspaceId]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new HttpException(404,'Asset not found.','not_found');
            $this->audit->record($pdo,$actor,'inventory.asset.owner_assigned','success','inventory_asset',$assetId,['reason'=>'workspace_ownership'], $requestId);
            return $row;
        });
    }

    /** Source/destination must be in one organization. Both authorizations are checked under locks. */
    public function transferAsset(string $actor,string $sourceId,string $assetId,string $destinationId,?string $requestId=null):array
    {
        self::uuid($sourceId);self::uuid($destinationId);self::uuid($assetId);
        if ($sourceId===$destinationId) throw new HttpException(422,'Source and destination match.','validation_failed');
        return $this->db->transaction(function(PDO $pdo) use($actor,$sourceId,$assetId,$destinationId,$requestId):array {
            $locks=[];$ids=[$sourceId,$destinationId];sort($ids,SORT_STRING);
            foreach($ids as $id) $locks[$id]=$this->workspaceLock($pdo,$id);
            if ($locks[$sourceId]['organization_id']!==$locks[$destinationId]['organization_id']) throw new HttpException(403,'Cross-organization transfer prohibited.','permission_denied');
            $this->requireRole($pdo,$sourceId,$actor,['owner']);
            $this->requireRole($pdo,$destinationId,$actor,['owner','manager']);
            $stmt=$pdo->prepare('UPDATE inventory_assets SET workspace_id=:dest,owner_user_id=NULL,updated_at=now() WHERE workspace_id=:src AND id=:asset AND deleted_at IS NULL RETURNING id::text,workspace_id::text,owner_user_id::text');
            try {$stmt->execute(['dest'=>$destinationId,'src'=>$sourceId,'asset'=>$assetId]);}
            catch(\PDOException $e) {if($e->getCode()==='23505')throw new HttpException(409,'Source identity conflicts in destination.','conflict');throw $e;}
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new HttpException(404,'Asset not found.','not_found');
            $this->audit->record($pdo,$actor,'inventory.asset.transferred','success','inventory_asset',$assetId,['reason'=>'workspace_transfer'], $requestId);
            return $row;
        });
    }
}
