<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\HistoryService;
use Cajeer\Servers\Inventory\InventoryRepository;
use PDO;

/** Connector-owned identities, read-only plans and atomic, non-destructive snapshot application. */
final class ReconciliationService
{
    public function __construct(private readonly InventoryRepository $inventory,private readonly HistoryService $history,private readonly AuditLogger $audit) {}

    private function query(PDO $pdo,string $sql,array $params=[]): \PDOStatement
    {
        $q=$pdo->prepare($sql); $q->execute($params); return $q;
    }
    public function generation(PDO $pdo):int
    {
        return (int)$pdo->query("SELECT nextval('connector_snapshot_generation')")->fetchColumn();
    }
    private static function key(array $target,string $external):string
    {
        return $target['provider'].':'.$target['id'].':'.$external;
    }
    private function state(PDO $pdo,array $target):?array
    {
        $r=$this->query($pdo,'SELECT * FROM connector_discovery_state WHERE target_id=:id AND workspace_id=:ws', ['id'=>$target['id'],'ws'=>$target['workspace_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if ($r['endpoint_host']!==$target['endpoint_host'] || (int)$r['endpoint_port']!==$target['endpoint_port'])
            throw new HttpException(409,'Connector endpoint changed; create a new target for a different infrastructure identity.','connector_endpoint_changed');
        return $r;
    }
    private function mapping(PDO $pdo,array $target,string $external):?array
    {
        return $this->query($pdo,'SELECT * FROM connector_discovered_resources WHERE target_id=:id AND workspace_id=:ws AND external_id=:ext',
            ['id'=>$target['id'],'ws'=>$target['workspace_id'],'ext'=>$external])->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private function decision(PDO $pdo,string $actor,array $target,array $item):array
    {
        $external=$item['external_id']; $mapping=$this->mapping($pdo,$target,$external);
        $assetId=$mapping['asset_id']??null;
        if ($assetId===null) {
            // Exact legacy identity is authoritative, never fuzzy-match by mutable name or IP.
            $assetId=$this->query($pdo,"SELECT id::text FROM inventory_assets WHERE workspace_id=:ws AND source='import' AND external_id=:ext",
                ['ws'=>$target['workspace_id'],'ext'=>self::key($target,$external)])->fetchColumn()?:null;
        }
        $base=['external_id'=>$external,'type'=>$item['type'],'name'=>$item['fields']['name']??null,'asset_id'=>$assetId,'action'=>'create','conflict'=>null,'candidate_ids'=>[],'parent_external_id'=>$item['parent_external_id']??null];
        if ($mapping!==null && $mapping['resource_type']!==$item['type']) return array_replace($base,['action'=>'conflict','conflict'=>'identity_unavailable']);
        if ($assetId!==null) {
            $asset=$this->inventory->find($pdo,$actor,$assetId,true);
            if (!$asset || $asset['workspace_id']!==$target['workspace_id'] || $asset['deleted_at']!==null || $asset['type']!==$item['type'])
                return array_replace($base,['action'=>'conflict','conflict'=>'identity_unavailable']);
            $exact=$this->query($pdo,"SELECT id::text FROM inventory_assets WHERE workspace_id=:ws AND source='import' AND external_id=:ext",['ws'=>$target['workspace_id'],'ext'=>self::key($target,$external)])->fetchColumn();
            if ($exact && $exact!==$assetId) return array_replace($base,['action'=>'conflict','conflict'=>'identity_collision']);
            $owner=$this->query($pdo,'SELECT target_id::text,external_id FROM connector_discovered_resources WHERE asset_id=:asset',['asset'=>$assetId])->fetch(PDO::FETCH_ASSOC);
            if ($owner && ($owner['target_id']!==$target['id'] || $owner['external_id']!==$external))
                return array_replace($base,['action'=>'conflict','conflict'=>'identity_collision']);
            $manual=$this->query($pdo,"SELECT field FROM inventory_field_provenance WHERE asset_id=:id AND mode='manual'",['id'=>$assetId])->fetchAll(PDO::FETCH_COLUMN);
            $changed=false; $conflicts=[];
            foreach ($item['fields'] as $field=>$value) if (($field==='attributes'?($asset[$field][array_key_first($value)]??null)!=reset($value):$asset[$field]!==$value)) {
                $changed=true; if (in_array($field,$manual,true)) $conflicts[]=$field;
            }
            return array_replace($base,['action'=>$conflicts?'conflict':($changed?'update':'unchanged'),'conflict'=>$conflicts?'manual_fields':null,'conflicted_fields'=>$conflicts]);
        }
        if (!isset($item['fields']['name'])) return array_replace($base,['action'=>'conflict','conflict'=>'name_required']);
        if (!in_array($mapping['allow_create']??false,[true,'t','1',1],true)) {
            $candidates=$this->query($pdo,'SELECT id::text FROM inventory_assets WHERE workspace_id=:ws AND type=:type AND lower(name)=lower(:name) AND deleted_at IS NULL ORDER BY id LIMIT 5',
                ['ws'=>$target['workspace_id'],'type'=>$item['type'],'name'=>$item['fields']['name']])->fetchAll(PDO::FETCH_COLUMN);
            if ($candidates) return array_replace($base,['action'=>'conflict','conflict'=>'ambiguous_name','candidate_ids'=>$candidates]);
        }
        return $base;
    }
    public function preview(PDO $pdo,string $actor,array $target,ConnectorResult $snapshot):array
    {
        $this->state($pdo,$target);
        $counts=['create'=>0,'update'=>0,'unchanged'=>0,'conflict'=>0]; $items=[];
        foreach ($snapshot->items as $item) {
            $d=$this->decision($pdo,$actor,$target,$item); $counts[$d['action']]++;
            if (count($items)<100) $items[]=$d;
        }
        return ['complete'=>$snapshot->complete,'summary'=>$counts,'items'=>$items,'truncated'=>count($snapshot->items)>100];
    }
    /** Caller owns workspace lock, target authorization and queue fence. No independent transaction. */
    public function apply(PDO $pdo,string $actor,array $target,ConnectorResult $snapshot,int $generation,string $observedAt,?string $requestId):array
    {
        if ($snapshot->items===[]) throw new HttpException(409,'Empty snapshot is never applied.','empty_inventory');
        $state=$this->state($pdo,$target);
        if ($state && (int)$state['generation'] >= $generation) throw new HttpException(409,'Snapshot superseded.','snapshot_stale');
        $scope=hash('sha256',json_encode([$target['version'],$target['credential_id'],$target['credential_version'],$target['purpose']],JSON_THROW_ON_ERROR));
        // Changed permissions/binding invalidate accumulated evidence of absence.
        if (!$state || $state['scope_hash']!==$scope) $this->query($pdo,"UPDATE connector_discovered_resources SET missed_complete=0,absent_since=NULL,presence=CASE WHEN presence IN ('stale','missing') THEN 'unknown' ELSE presence END WHERE target_id=:id",['id'=>$target['id']]);
        $this->query($pdo,<<<'SQL'
INSERT INTO connector_discovery_state(target_id,workspace_id,endpoint_host,endpoint_port,generation,scope_hash,last_snapshot_at,last_complete_at)
VALUES(:id,:ws,:host,:port,:gen,:scope,CAST(:seen AS timestamptz),CASE WHEN CAST(:full AS boolean) THEN CAST(:complete_at AS timestamptz) END)
ON CONFLICT(target_id) DO UPDATE SET generation=EXCLUDED.generation,scope_hash=EXCLUDED.scope_hash,last_snapshot_at=EXCLUDED.last_snapshot_at,
 last_complete_at=COALESCE(EXCLUDED.last_complete_at,CASE WHEN connector_discovery_state.scope_hash=EXCLUDED.scope_hash THEN connector_discovery_state.last_complete_at END)
SQL,['id'=>$target['id'],'ws'=>$target['workspace_id'],'host'=>$target['endpoint_host'],'port'=>$target['endpoint_port'],'gen'=>$generation,'scope'=>$scope,'seen'=>$observedAt,'full'=>$snapshot->complete?'true':'false','complete_at'=>$observedAt]);
        $outcomes=['created'=>0,'applied'=>0,'unchanged'=>0,'conflicted'=>0,'stale'=>0];
        $blocked=0;
        // Process bounded chunks while keeping one outer transaction (no partial commit).
        foreach (array_chunk($snapshot->items,250) as $chunk) foreach ($chunk as $item) {
            $d=$this->decision($pdo,$actor,$target,$item);
            $assetId=$d['asset_id']; $conflict=$d['conflict'];
            if ($conflict!==null && $conflict!=='manual_fields') {
                $blocked++; $outcomes['conflicted']++;
                // Never attach an asset already owned by another resource.
                if (in_array($conflict,['identity_collision','identity_unavailable'],true)) $assetId=$this->mapping($pdo,$target,$item['external_id'])['asset_id']??null;
            } else {
                $record=$this->history->observe($actor,[
                    'workspace_id'=>$target['workspace_id'],'external_id'=>self::key($target,$item['external_id']),
                    'event_key'=>'connector.'.hash('sha256',$target['id'].':'.$generation.':'.$item['external_id']),
                    'type'=>$item['type'],'fields'=>$item['fields'],'observed_at'=>$observedAt,
                ],$requestId,true,true,$assetId);
                $assetId=$record['asset_id']; $outcomes[$record['outcome']]++;
                $conflict=$record['outcome']==='conflicted'?'manual_fields':null;
            }
            $this->query($pdo,<<<'SQL'
INSERT INTO connector_discovered_resources(target_id,workspace_id,external_id,resource_type,asset_id,presence,first_seen_at,last_seen_at,last_generation,conflict_code,observed_name,parent_external_id)
VALUES(:id,:ws,:ext,:type,:asset,'seen',CAST(:first AS timestamptz),CAST(:last AS timestamptz),:gen,:conflict,:name,:parent)
ON CONFLICT(target_id,external_id) DO UPDATE SET asset_id=COALESCE(connector_discovered_resources.asset_id,EXCLUDED.asset_id),presence='seen',
 first_seen_at=COALESCE(connector_discovered_resources.first_seen_at,EXCLUDED.first_seen_at),last_seen_at=EXCLUDED.last_seen_at,
 last_generation=EXCLUDED.last_generation,missed_complete=0,absent_since=NULL,conflict_code=EXCLUDED.conflict_code,observed_name=EXCLUDED.observed_name,
 parent_external_id=COALESCE(EXCLUDED.parent_external_id,connector_discovered_resources.parent_external_id)
SQL,['id'=>$target['id'],'ws'=>$target['workspace_id'],'ext'=>$item['external_id'],'type'=>$item['type'],'asset'=>$assetId,'first'=>$observedAt,'last'=>$observedAt,'gen'=>$generation,'conflict'=>$conflict,'name'=>$item['fields']['name']??null,'parent'=>$item['parent_external_id']??null]);
        }
        if ($snapshot->complete) {
            $this->query($pdo,<<<'SQL'
UPDATE connector_discovered_resources SET missed_complete=missed_complete+1, absent_since=COALESCE(absent_since,CAST(:since AS timestamptz)),
 presence=CASE WHEN missed_complete>=2 AND absent_since<=CAST(:missing_at AS timestamptz)-interval '72 hours' THEN 'missing'
 WHEN missed_complete>=1 AND absent_since<=CAST(:stale_at AS timestamptz)-interval '24 hours' THEN 'stale' ELSE presence END
WHERE target_id=:id AND last_generation<:gen AND last_seen_at IS NOT NULL
SQL,['id'=>$target['id'],'gen'=>$generation,'since'=>$observedAt,'missing_at'=>$observedAt,'stale_at'=>$observedAt]);
        }
        $presence=['unknown'=>0,'seen'=>0,'stale'=>0,'missing'=>0];
        foreach ($this->query($pdo,'SELECT presence,count(*) AS n FROM connector_discovered_resources WHERE target_id=:id GROUP BY presence',['id'=>$target['id']])->fetchAll(PDO::FETCH_ASSOC) as $row) $presence[$row['presence']]=(int)$row['n'];
        return ['outcomes'=>$outcomes,'snapshot'=>['generation'=>$generation,'complete'=>$snapshot->complete,'blocked'=>$blocked,'presence'=>$presence]];
    }
    public function resources(PDO $pdo,string $actor,array $target,int $limit,int $offset):array
    {
        $this->inventory->workspaceRole($pdo,$target['workspace_id'],$actor);
        $q=$pdo->prepare('SELECT external_id,resource_type,asset_id::text,presence,first_seen_at::text,last_seen_at::text,absent_since::text,missed_complete,conflict_code,observed_name,parent_external_id,allow_create,last_generation FROM connector_discovered_resources WHERE target_id=:id AND workspace_id=:ws ORDER BY external_id LIMIT :lim OFFSET :off');
        $q->bindValue('id',$target['id']); $q->bindValue('ws',$target['workspace_id']); $q->bindValue('lim',$limit,PDO::PARAM_INT); $q->bindValue('off',$offset,PDO::PARAM_INT); $q->execute();
        $data=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($data as &$r) { $r['missed_complete']=(int)$r['missed_complete']; $r['last_generation']=(int)$r['last_generation']; $r['allow_create']=in_array($r['allow_create'],[true,'t','1',1],true); } unset($r);
        return $data;
    }
    /** Explicit conflict resolution: bind an existing manual asset, or approve distinct creation. */
    public function resolve(PDO $pdo,string $actor,array $target,string $external,int $generation,?string $assetId,?string $requestId):array
    {
        $this->state($pdo,$target);
        $row=$this->mapping($pdo,$target,$external);
        if (!$row || $row['asset_id']!==null || $row['conflict_code']!=='ambiguous_name' || (int)$row['last_generation']!==$generation) throw new HttpException(409,'No unresolved name conflict.','conflict');
        if ($assetId!==null) {
            $asset=$this->inventory->find($pdo,$actor,$assetId,false,true);
            if (!$asset || $asset['workspace_id']!==$target['workspace_id'] || $asset['type']!==$row['resource_type'] || $asset['source']!=='manual')
                throw new HttpException(422,'Select an active manual asset of the same type in this workspace.','validation_failed');
            if ($this->query($pdo,'SELECT 1 FROM connector_discovered_resources WHERE asset_id=:id',['id'=>$assetId])->fetchColumn())
                throw new HttpException(409,'Asset already mapped.','conflict');
        }
        $this->query($pdo,'UPDATE connector_discovered_resources SET asset_id=:asset,allow_create=CAST(:allow AS boolean),conflict_code=NULL WHERE target_id=:id AND external_id=:ext',
            ['asset'=>$assetId,'allow'=>$assetId===null?'true':'false','id'=>$target['id'],'ext'=>$external]);
        $this->audit->record($pdo,$actor,'connector.resource.resolved','success','connector_target',$target['id'],['reason'=>$assetId===null?'create_distinct':'link_manual'],$requestId);
        return ['external_id'=>$external,'asset_id'=>$assetId,'allow_create'=>$assetId===null];
    }
}
