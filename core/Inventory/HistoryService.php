<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** Workspace-scoped history, import observations, field overrides, safe selective revision restoration. */
final class HistoryService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly InventoryRepository $repo,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ){}

    public static function context(PDO $pdo,string $actor,string $origin,?string $requestId):void
    {
        if(!InfrastructureAsset::isUuid($actor)||!in_array($origin,['manual','observation'],true))throw new \InvalidArgumentException('Invalid history context.');
        $stmt=$pdo->prepare("SELECT set_config('cajeer.actor_id',:actor,true),set_config('cajeer.change_origin',:origin,true),set_config('cajeer.request_id',:rid,true)");
        $stmt->execute(['actor'=>$actor,'origin'=>$origin,'rid'=>$requestId??'']);
    }

    /** @return array<string,mixed> */
    private function visible(PDO $pdo,string $actor,string $assetId,bool $write=false):array
    {
        if(!InfrastructureAsset::isUuid($assetId))throw new HttpException(404,'Asset not found.','not_found');
        $row=$this->repo->find($pdo,$actor,strtolower($assetId),true);
        if($row===null)throw new HttpException(404,'Asset not found.','not_found');
        // Deleted assets are not visible to workspace viewers (same policy as InventoryService::read).
        $this->repo->workspaceRole($pdo,(string)$row['workspace_id'],$actor,$write || $row['deleted_at']!==null);
        return $row;
    }

    /** @return array<string,mixed> */
    public function revisions(string $actor,string $id,int $limit=30,int $offset=0):array
    {
        if($limit<1||$limit>100||$offset<0||$offset>10000)throw new HttpException(422,'Invalid pagination.','validation_failed');
        $pdo=$this->db->connection();$asset=$this->visible($pdo,$actor,$id);
        $sql='SELECT asset_version,operation,origin,actor_id::text,request_id,before_state::text,after_state::text,changes::text,created_at::text FROM inventory_asset_revisions WHERE asset_id=:asset AND workspace_id=:ws ORDER BY asset_version DESC LIMIT :limit OFFSET :offset';
        $stmt=$pdo->prepare($sql);
        $stmt->bindValue(':asset',$asset['id']);$stmt->bindValue(':ws',$asset['workspace_id']);
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->bindValue(':offset',$offset,PDO::PARAM_INT);$stmt->execute();
        $data=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            foreach(['before_state','after_state','changes'] as $field)$row[$field]=json_decode($row[$field],true,32,JSON_THROW_ON_ERROR);
            $row['asset_version']=(int)$row['asset_version'];$data[]=$row;
        }
        $count=$pdo->prepare('SELECT count(*) FROM inventory_asset_revisions WHERE asset_id=:asset AND workspace_id=:ws');
        $count->execute(['asset'=>$asset['id'],'ws'=>$asset['workspace_id']]);
        return ['data'=>$data,'pagination'=>['total'=>(int)$count->fetchColumn(),'limit'=>$limit,'offset'=>$offset]];
    }

    /** @return array<string,mixed> */
    public function provenance(string $actor,string $id):array
    {
        $pdo=$this->db->connection();$asset=$this->visible($pdo,$actor,$id);
        $p=$pdo->prepare('SELECT field,mode,source,actor_id::text,observed_at::text,updated_at::text FROM inventory_field_provenance WHERE asset_id=:asset ORDER BY field');
        $p->execute(['asset'=>$asset['id']]);
        $s=$pdo->prepare('SELECT source,last_observed_at::text,last_received_at::text FROM inventory_observation_state WHERE asset_id=:asset ORDER BY source');
        $s->execute(['asset'=>$asset['id']]);
        return ['asset_id'=>$asset['id'],'source'=>$asset['source'],'fields'=>$p->fetchAll(PDO::FETCH_ASSOC),'observations'=>$s->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** @return array<string,mixed> */
    public function conflicts(string $actor,string $id,bool $includeResolved=false):array
    {
        $pdo=$this->db->connection();$asset=$this->visible($pdo,$actor,$id);
        $sql='SELECT id::text,field,source,observed_value::text,observed_at::text,last_seen_at::text,seen_count,resolved_at::text FROM inventory_reconciliation_conflicts WHERE asset_id=:id AND workspace_id=:ws';
        if(!$includeResolved)$sql.=' AND resolved_at IS NULL';
        $stmt=$pdo->prepare($sql.' ORDER BY last_seen_at DESC LIMIT 100');
        $stmt->execute(['id'=>$asset['id'],'ws'=>$asset['workspace_id']]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){$row['observed_value']=json_decode($row['observed_value'],true,32,JSON_THROW_ON_ERROR);$row['seen_count']=(int)$row['seen_count'];}unset($row);
        return ['data'=>$rows];
    }

    /** @return array<string,mixed> */
    public function observations(string $actor,string $id,int $limit=30):array
    {
        if($limit<1||$limit>100)throw new HttpException(422,'Invalid limit.','validation_failed');
        $pdo=$this->db->connection();$asset=$this->visible($pdo,$actor,$id);
        $stmt=$pdo->prepare('SELECT id::text,source,external_id,event_key,observed_at::text,received_at::text,outcome,applied_fields::text,conflicted_fields::text FROM inventory_observations WHERE asset_id=:id AND workspace_id=:ws ORDER BY received_at DESC,id DESC LIMIT :lim');
        $stmt->bindValue(':id',$asset['id']);$stmt->bindValue(':ws',$asset['workspace_id']);$stmt->bindValue(':lim',$limit,PDO::PARAM_INT);$stmt->execute();
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){foreach(['applied_fields','conflicted_fields'] as $f)$row[$f]=json_decode($row[$f],true,16,JSON_THROW_ON_ERROR);}unset($row);
        return ['data'=>$rows];
    }

    /** Atomically reconcile a bounded discovery batch using the same validation and provenance engine. */
    public function observeMany(string $actor,array $events,?string $requestId=null,bool $nested=false,bool $connector=false):array
    {
        if (count($events)<1 || count($events)>1800)throw new HttpException(422,'Invalid discovery batch size.','validation_failed');
        foreach ($events as $event) ObservationInput::normalize($event,$connector);
        return $this->db->transaction(function(PDO $pdo)use($actor,$events,$requestId,$connector):array {
            $result=[];
            foreach ($events as $event) $result[]=$this->observe($actor,$event,$requestId,true,$connector);
            return $result;
        },attempts:$nested?1:2);
    }

    /** Ingest one idempotent observation from an explicitly untrusted 'import' source. @return array<string,mixed> */
    public function observe(string $actor,array $input,?string $requestId=null,bool $nested=false,bool $connector=false,?string $linkedAsset=null):array
    {
        $event=ObservationInput::normalize($input,$connector);
        if (!$connector && (ObservationInput::reserved($event['external_id']) || str_starts_with($event['event_key'],'connector.')))
            throw new HttpException(422,'Connector identity is reserved.','validation_failed');
        if ($linkedAsset!==null && !$connector) throw new \LogicException('Internal connector link required.');
        return $this->db->transaction(function(PDO $pdo)use($actor,$event,$requestId,$linkedAsset):array{
            $ws=$event['workspace_id'];
            $this->repo->lockWorkspace($pdo,$ws);
            $this->repo->workspaceRole($pdo,$ws,$actor,true);
            $existing=$pdo->prepare('SELECT id::text,asset_id::text,payload_hash,outcome,applied_fields::text,conflicted_fields::text FROM inventory_observations WHERE workspace_id=:ws AND source=:src AND event_key=:key');
            $existing->execute(['ws'=>$ws,'src'=>'import','key'=>$event['event_key']]);
            $prior=$existing->fetch(PDO::FETCH_ASSOC);
            if(is_array($prior)){
                if(!hash_equals($prior['payload_hash'],$event['payload_hash']))throw new HttpException(409,'Observation key was reused for different data.','conflict');
                return ['id'=>$prior['id'],'asset_id'=>$prior['asset_id'],'outcome'=>$prior['outcome'],'applied_fields'=>json_decode($prior['applied_fields'],true,16,JSON_THROW_ON_ERROR),
                    'conflicted_fields'=>json_decode($prior['conflicted_fields'],true,16,JSON_THROW_ON_ERROR),'duplicate'=>true];
            }
            $lookup=$pdo->prepare('SELECT id::text FROM inventory_assets WHERE workspace_id=:ws AND source=:src AND external_id=:external FOR UPDATE');
            $lookup->execute(['ws'=>$ws,'src'=>'import','external'=>$event['external_id']]);
            $assetId=$lookup->fetchColumn();
            if ($linkedAsset!==null) {
                if ($assetId!==false && $assetId!==$linkedAsset) throw new HttpException(409,'Conflicting connector identity.','conflict');
                $assetId=$linkedAsset;
            }
            $created=false;$applied=[];$conflicted=[];$outcome='unchanged';
            self::context($pdo,$actor,'observation',$requestId);
            if($assetId===false){
                if(!isset($event['fields']['name']))throw new HttpException(422,'New asset requires name.','validation_failed');
                $new=InfrastructureAsset::changes($event['fields'],true);
                $insert=$pdo->prepare(<<<'SQL'
INSERT INTO inventory_assets(workspace_id,type,source,external_id,name,status,os_family,ip_addresses,tags,attributes,environment)
VALUES (CAST(:ws AS uuid),:type,'import',:external,:name,:status,:os,CAST(:ips AS jsonb),CAST(:tags AS jsonb),CAST(:attrs AS jsonb),:env) RETURNING id::text
SQL);
                $insert->execute(['ws'=>$ws,'type'=>$event['type'],'external'=>$event['external_id'],'name'=>$new['name'],'status'=>$new['status'],
                    'os'=>$new['os_family'],'ips'=>json_encode($new['ip_addresses'],JSON_THROW_ON_ERROR),
                    'tags'=>json_encode($new['tags'],JSON_THROW_ON_ERROR),'attrs'=>json_encode($new['attributes']===[]?(object)[]:$new['attributes'],JSON_THROW_ON_ERROR),'env'=>$new['environment']]);
                $assetId=$insert->fetchColumn();if(!is_string($assetId))throw new \RuntimeException('Observation insert failed.');
                $created=true;$outcome='created';$applied=array_keys($event['fields']);
                foreach(array_unique([...ObservationInput::FIELDS,...array_keys($event['fields'])]) as $field)$this->setProvenance($pdo,$assetId,$field,'observed','import',$actor,$event['observed_at']);
            }else{
                $asset=$this->repo->find($pdo,$actor,$assetId,true,true);
                if($asset===null || $asset['workspace_id']!==$ws || ($linkedAsset===null && ($asset['source']!=='import' || $asset['external_id']!==$event['external_id'])) || ($linkedAsset!==null && $asset['source']!=='manual' && ($asset['source']!=='import' || $asset['external_id']!==$event['external_id'])))throw new HttpException(409,'Asset identity changed.','conflict');
                if($asset['type']!==$event['type'])throw new HttpException(409,'Cannot change observed asset type.','conflict');
                if($asset['deleted_at']!==null)throw new HttpException(409,'Cannot reconcile a deleted asset.','conflict');
                $last=$pdo->prepare('SELECT last_observed_at::text FROM inventory_observation_state WHERE asset_id=:id AND source=:src FOR UPDATE');
                $last->execute(['id'=>$assetId,'src'=>'import']);$lastWhen=$last->fetchColumn();
                if(is_string($lastWhen) && new \DateTimeImmutable($lastWhen)>=new \DateTimeImmutable($event['observed_at'])){
                    $outcome='stale';
                }else{
                    $patch=[];
                    $modeStmt=$pdo->prepare('SELECT mode FROM inventory_field_provenance WHERE asset_id=:id AND field=:field');
                    foreach($event['fields'] as $field=>$value){
                        $modeStmt->execute(['id'=>$assetId,'field'=>$field]);$mode=$modeStmt->fetchColumn();
                        if($mode==='manual'){
                            if(($field==='attributes'?($asset[$field][array_key_first($value)]??null)!=reset($value):$asset[$field]!==$value)){
                                $conflicted[]=$field;
                                $this->recordConflict($pdo,$assetId,$ws,$field,$value,$event['observed_at']);
                            }else{$this->resolveConflict($pdo,$assetId,$field);}
                        }else{
                            if(($field==='attributes'?($asset[$field][array_key_first($value)]??null)!=reset($value):$asset[$field]!==$value)){$patch[$field]=$field==='attributes'?InfrastructureAsset::changes(['attributes'=>array_replace($asset[$field],$value)])['attributes']:$value;$applied[]=$field;}
                            $this->setProvenance($pdo,$assetId,$field,'observed','import',$actor,$event['observed_at']);
                            $this->resolveConflict($pdo,$assetId,$field);
                        }
                    }
                    if($patch)$this->repo->update($pdo,$assetId,$patch);
                    $outcome=$conflicted?'conflicted':($patch?'applied':'unchanged');
                }
            }
            if($outcome!=='stale'){
                $state=$pdo->prepare(<<<'SQL'
INSERT INTO inventory_observation_state(asset_id,source,last_observed_at) VALUES (CAST(:id AS uuid),'import',CAST(:observed AS timestamptz))
ON CONFLICT(asset_id,source) DO UPDATE SET last_observed_at=GREATEST(inventory_observation_state.last_observed_at,EXCLUDED.last_observed_at),last_received_at=clock_timestamp()
SQL);
                $state->execute(['id'=>$assetId,'observed'=>$event['observed_at']]);
            }
            $stmt=$pdo->prepare(<<<'SQL'
INSERT INTO inventory_observations(workspace_id,asset_id,actor_id,source,external_id,event_key,payload_hash,observed_at,outcome,applied_fields,conflicted_fields)
VALUES(CAST(:ws AS uuid),CAST(:asset AS uuid),CAST(:actor AS uuid),'import',:external,:key,:digest,CAST(:when AS timestamptz),:outcome,CAST(:applied AS jsonb),CAST(:conflicts AS jsonb)) RETURNING id::text
SQL);
            $stmt->execute(['ws'=>$ws,'asset'=>$assetId,'actor'=>$actor,'external'=>$event['external_id'],'key'=>$event['event_key'],
                'digest'=>$event['payload_hash'],'when'=>$event['observed_at'],'outcome'=>$outcome,
                'applied'=>json_encode($applied,JSON_THROW_ON_ERROR),'conflicts'=>json_encode($conflicted,JSON_THROW_ON_ERROR)]);
            $id=$stmt->fetchColumn();if(!is_string($id))throw new \RuntimeException('Observation event insert failed.');
            $this->audit->record($pdo,$actor,'inventory.observation.received','success','inventory_asset',$assetId,
                ['source'=>'import','outcome'=>$outcome,'applied_fields'=>$applied,'conflicts'=>$conflicted],$requestId);
            return ['id'=>$id,'asset_id'=>$assetId,'outcome'=>$outcome,'applied_fields'=>$applied,'conflicted_fields'=>$conflicted,'duplicate'=>false];
        },attempts:$nested?1:2);
    }

    private function setProvenance(PDO $pdo,string $asset,string $field,string $mode,string $source,string $actor,?string $when):void
    {
        $stmt=$pdo->prepare(<<<'SQL'
INSERT INTO inventory_field_provenance(asset_id,field,mode,source,actor_id,observed_at)
VALUES(CAST(:id AS uuid),:field,:mode,:src,CAST(:actor AS uuid),CAST(:seen AS timestamptz))
ON CONFLICT(asset_id,field) DO UPDATE SET mode=EXCLUDED.mode,source=EXCLUDED.source,
actor_id=EXCLUDED.actor_id,observed_at=EXCLUDED.observed_at,updated_at=clock_timestamp()
SQL);
        $stmt->execute(['id'=>$asset,'field'=>$field,'mode'=>$mode,'src'=>$source,'actor'=>$actor,'seen'=>$when]);
    }

    private function recordConflict(PDO $pdo,string $asset,string $ws,string $field,mixed $value,string $when):void
    {
        $stmt=$pdo->prepare(<<<'SQL'
INSERT INTO inventory_reconciliation_conflicts(asset_id,workspace_id,source,field,observed_value,observed_at)
VALUES(CAST(:id AS uuid),CAST(:ws AS uuid),'import',:field,CAST(:value AS jsonb),CAST(:seen AS timestamptz))
ON CONFLICT(asset_id,source,field) WHERE resolved_at IS NULL DO UPDATE
SET observed_value=EXCLUDED.observed_value,observed_at=EXCLUDED.observed_at,last_seen_at=clock_timestamp(),seen_count=inventory_reconciliation_conflicts.seen_count+1
SQL);
        $stmt->execute(['id'=>$asset,'ws'=>$ws,'field'=>$field,'value'=>json_encode($value,JSON_THROW_ON_ERROR),'seen'=>$when]);
    }

    private function resolveConflict(PDO $pdo,string $asset,string $field):void
    {
        $pdo->prepare("UPDATE inventory_reconciliation_conflicts SET resolved_at=clock_timestamp() WHERE asset_id=:id AND field=:field AND source='import' AND resolved_at IS NULL")
            ->execute(['id'=>$asset,'field'=>$field]);
    }

    /** @return array<string,mixed> */
    public function override(string $actor,string $assetId,int $expectedVersion,array $input,?string $requestId=null):array
    {
        $fields=ObservationInput::overrideFields($input);
        return $this->db->transaction(function(PDO $pdo)use($actor,$assetId,$expectedVersion,$fields,$requestId):array{
            $current=$this->visible($pdo,$actor,$assetId,true);$ws=$current['workspace_id'];
            $this->repo->lockWorkspace($pdo,$ws);
            $row=$this->repo->find($pdo,$actor,$assetId,true,true);
            if($row===null||$row['workspace_id']!==$ws)throw new HttpException(409,'Asset moved.','conflict');
            if($row['source']!=='import'||$row['deleted_at']!==null)throw new HttpException(409,'Only active imported assets accept overrides.','conflict');
            if($row['version']!==$expectedVersion)throw new HttpException(412,'Asset version changed.','precondition_failed');
            self::context($pdo,$actor,'manual',$requestId);
            $patch=[];foreach($fields as $f=>$value)if($row[$f]!==$value)$patch[$f]=$value;
            if($patch)$this->repo->update($pdo,$assetId,$patch);
            else $pdo->prepare('UPDATE inventory_assets SET updated_at=clock_timestamp() WHERE id=:id')->execute(['id'=>$assetId]);
            foreach($fields as $field=>$value){$this->setProvenance($pdo,$assetId,$field,'manual','manual',$actor,null);$this->resolveConflict($pdo,$assetId,$field);}
            $this->audit->record($pdo,$actor,'inventory.override.changed','success','inventory_asset',$assetId,['fields'=>array_keys($fields)],$requestId);
            return $this->repo->find($pdo,$actor,$assetId);
        });
    }

    /** @return array<string,mixed> */
    public function clearOverride(string $actor,string $assetId,int $version,string $field,?string $requestId=null):array
    {
        if(!in_array($field,ObservationInput::FIELDS,true))throw new HttpException(404,'Field not found.','not_found');
        return $this->db->transaction(function(PDO $pdo)use($actor,$assetId,$version,$field,$requestId):array{
            $current=$this->visible($pdo,$actor,$assetId,true);$ws=$current['workspace_id'];
            $this->repo->lockWorkspace($pdo,$ws);
            $row=$this->repo->find($pdo,$actor,$assetId,true,true);
            if($row===null||$row['workspace_id']!==$ws||$row['source']!=='import'||$row['deleted_at']!==null)throw new HttpException(409,'Invalid imported asset.','conflict');
            if($row['version']!==$version)throw new HttpException(412,'Asset version changed.','precondition_failed');
            $stmt=$pdo->prepare("DELETE FROM inventory_field_provenance WHERE asset_id=:id AND field=:field AND mode='manual'");
            $stmt->execute(['id'=>$assetId,'field'=>$field]);
            if($stmt->rowCount()!==1)throw new HttpException(409,'No override exists.','conflict');
            self::context($pdo,$actor,'manual',$requestId);
            $pdo->prepare('UPDATE inventory_assets SET updated_at=clock_timestamp() WHERE id=:id')->execute(['id'=>$assetId]);
            $this->resolveConflict($pdo,$assetId,$field);
            $this->audit->record($pdo,$actor,'inventory.override.cleared','success','inventory_asset',$assetId,['field'=>$field],$requestId);
            return $this->repo->find($pdo,$actor,$assetId);
        });
    }

    /** Restore safe, validated manual asset fields; arbitrary attributes are intentionally excluded. @return array<string,mixed> */
    public function restoreRevision(string $actor,string $id,int $expectedVersion,int $historyVersion,?string $requestId=null):array
    {
        if($historyVersion<1)throw new HttpException(422,'Invalid revision.','validation_failed');
        $pdo=$this->db->connection();$row=$this->visible($pdo,$actor,$id,true);
        if($row['source']!=='manual'||$row['deleted_at']!==null)throw new HttpException(409,'Only active manual assets can restore fields.','conflict');
        $stmt=$pdo->prepare('SELECT after_state::text FROM inventory_asset_revisions WHERE asset_id=:id AND workspace_id=:ws AND asset_version=:version');
        $stmt->execute(['id'=>$row['id'],'ws'=>$row['workspace_id'],'version'=>$historyVersion]);
        $raw=$stmt->fetchColumn();if(!is_string($raw))throw new HttpException(404,'Revision not found.','not_found');
        $snapshot=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        $patch=[];foreach(['name','status','os_family','ip_addresses','tags','environment','owner_user_id'] as $f){if(array_key_exists($f,$snapshot))$patch[$f]=$snapshot[$f];}
        // Update performs workspace+owner authorization, ETag comparison and its own audit.
        return $this->db->transaction(function(PDO $connection)use($actor,$id,$expectedVersion,$patch,$requestId,$historyVersion):array{
            $updated=$this->inventory->update($actor,$id,$expectedVersion,$patch,$requestId);
            $this->audit->record($connection,$actor,'inventory.revision.restored','success','inventory_asset',$id,['reason'=>'historical_restore'], $requestId);
            return $updated;
        });
    }
}
