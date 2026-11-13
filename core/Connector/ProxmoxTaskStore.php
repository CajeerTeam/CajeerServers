<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use PDO;
use Cajeer\Servers\Http\Exception\HttpException;

/** Incremental cursor and UPID transitions commit with the authorized collection run. */
final class ProxmoxTaskStore
{
    private static function scope(array $t):string
    {return hash('sha256',json_encode([(int)$t['version'],$t['credential_id'],(int)$t['credential_version']],JSON_THROW_ON_ERROR));}
    private static function query(PDO $p,string $sql,array $args):\PDOStatement
    {$q=$p->prepare($sql);$q->execute($args);return $q;}
    private static function args(array $t):array{return ['id'=>$t['id'],'scope'=>self::scope($t)];}
    public static function previous(PDO $p,array $t):array
    {
        $a=self::args($t);$r=self::query($p,'SELECT revision,cursor FROM proxmox_task_cursor WHERE target_id=:id AND scope_hash=:scope',$a)->fetch(PDO::FETCH_ASSOC);
        // Include accepted actions before their UPID appears in the archive. Separate quotas
        // prevent a backlog of actions from starving ordinary history polling.
        $pending=self::query($p,"SELECT upid FROM proxmox_tasks WHERE target_id=:id AND scope_hash=:scope AND state='running' ORDER BY checked_at ASC NULLS FIRST,id LIMIT 20",$a)->fetchAll(PDO::FETCH_ASSOC);
        $actions=self::query($p,<<<'SQL'
SELECT a.remote_task AS upid FROM connector_actions a
LEFT JOIN proxmox_tasks p ON p.target_id=a.target_id AND p.scope_hash=:scope AND p.upid=a.remote_task
WHERE a.target_id=:id AND a.workspace_id=:ws AND a.target_version=:tv AND a.credential_id=:credential AND a.credential_version=:cv
 AND a.status='submitted' AND a.remote_task IS NOT NULL AND (p.state IS NULL OR p.state IN ('running','unknown'))
ORDER BY a.task_checked_at ASC NULLS FIRST,a.created_at,a.id LIMIT 10
SQL,$a+['ws'=>$t['workspace_id'],'tv'=>$t['version'],'credential'=>$t['credential_id'],'cv'=>$t['credential_version']])->fetchAll(PDO::FETCH_ASSOC);
        $pending=array_values(array_column([...$actions,...$pending],null,'upid'));
        return ['revision'=>$r?(int)$r['revision']:0,'cursor'=>$r?json_decode($r['cursor'],true,32,JSON_THROW_ON_ERROR):['nodes'=>[],'next_node'=>null],'pending'=>$pending];
    }
    public static function summary(array $batch):array
    {return array_diff_key($batch,array_flip(['tasks','cursor','checked','expected_revision']));}
    private static function event(PDO $p,array $a,string $taskId,string $kind,string $state,?int $occurred,string $observed):void
    {
        self::query($p,'INSERT INTO proxmox_task_events(task_id,target_id,scope_hash,kind,state,occurred_at,observed_at) VALUES(:task,:id,:scope,:kind,:state,:occurred,:observed) ON CONFLICT(task_id,kind) DO NOTHING',$a+['task'=>$taskId,'kind'=>$kind,'state'=>$state,'occurred'=>$occurred,'observed'=>$observed]);
    }
    public static function save(PDO $p,array $t,array $batch,string $observed):array
    {
        $a=self::args($t);$r=self::query($p,'SELECT revision FROM proxmox_task_cursor WHERE target_id=:id AND scope_hash=:scope FOR UPDATE',$a)->fetchColumn();
        if((int)$r!==$batch['expected_revision'])throw new HttpException(409,'Task cursor superseded.','snapshot_stale');
        $summary=self::summary($batch);$summary['new_tasks']=0;$summary['completed_tasks']=0;
        // Insert cursor first for FK; the entire transaction is rolled back if any task fails.
        $args=$a+['ws'=>$t['workspace_id'],'revision'=>$batch['expected_revision']+1,'observed'=>$observed,'cursor'=>json_encode($batch['cursor'],JSON_THROW_ON_ERROR),'summary'=>json_encode($summary,JSON_THROW_ON_ERROR)];
        self::query($p,<<<'SQL'
INSERT INTO proxmox_task_cursor(target_id,workspace_id,scope_hash,revision,observed_at,cursor,summary)
VALUES(:id,:ws,:scope,:revision,:observed,CAST(:cursor AS jsonb),CAST(:summary AS jsonb))
ON CONFLICT(target_id,scope_hash) DO UPDATE SET revision=EXCLUDED.revision,observed_at=EXCLUDED.observed_at,cursor=EXCLUDED.cursor,summary=EXCLUDED.summary
SQL,$args);
        foreach($batch['tasks'] as $task){
            $key=$a+['upid'=>$task['upid']];$old=self::query($p,'SELECT id::text,state,ended_at FROM proxmox_tasks WHERE target_id=:id AND scope_hash=:scope AND upid=:upid FOR UPDATE',$key)->fetch(PDO::FETCH_ASSOC);
            $state=$task['state'];$end=$task['ended_at'];
            if(!$old){
                $id=(string)self::query($p,<<<'SQL'
INSERT INTO proxmox_tasks(target_id,workspace_id,scope_hash,upid,node,task_type,resource_id,task_user,started_at,ended_at,state,first_seen_at,last_seen_at)
VALUES(:id,:ws,:scope,:upid,:node,:type,:resource,:user,:start,:end,:state,:first,:last) RETURNING id::text
SQL,$key+['ws'=>$t['workspace_id'],'node'=>$task['node'],'type'=>$task['type'],'resource'=>$task['resource_id'],'user'=>$task['user'],'start'=>$task['started_at'],'end'=>$end,'state'=>$state,'first'=>$observed,'last'=>$observed])->fetchColumn();
                self::event($p,$a,$id,'task_discovered',$state,$task['started_at'],$observed);$summary['new_tasks']++;
                if($state!=='running'){self::event($p,$a,$id,'task_completed',$state,$end,$observed);$summary['completed_tasks']++;}
            }else{
                $id=$old['id'];$before=$old['state'];
                // Terminal evidence is never replaced by stale active responses. Unknown can resolve later.
                if($before!=='running'&&($before!=='unknown'||in_array($state,['running','unknown'],true)))$state=$before;
                if($old['ended_at']!==null)$end=(int)$old['ended_at'];
                if($before==='running'&&$state!=='running'){self::event($p,$a,$id,'task_completed',$state,$end,$observed);$summary['completed_tasks']++;}
                elseif($before==='unknown'&&!in_array($state,['unknown','running'],true))self::event($p,$a,$id,'task_outcome_resolved',$state,$end,$observed);
                self::query($p,'UPDATE proxmox_tasks SET state=:state,ended_at=:end,last_seen_at=:observed WHERE id=:task',['state'=>$state,'end'=>$end,'observed'=>$observed,'task'=>$id]);
            }
        }
        foreach($batch['checked'] as $upid=>$status){
            self::query($p,'UPDATE proxmox_tasks SET checked_at=:observed,check_state=:status WHERE target_id=:id AND scope_hash=:scope AND upid=:upid',$a+['observed'=>$observed,'status'=>$status,'upid'=>$upid]);
            self::query($p,"UPDATE connector_actions SET task_checked_at=:observed WHERE target_id=:id AND workspace_id=:ws AND target_version=:tv AND credential_id=:credential AND credential_version=:cv AND remote_task=:upid AND status='submitted'",['observed'=>$observed,'id'=>$t['id'],'ws'=>$t['workspace_id'],'tv'=>$t['version'],'credential'=>$t['credential_id'],'cv'=>$t['credential_version'],'upid'=>$upid]);
        }
        self::query($p,'UPDATE proxmox_task_cursor SET summary=CAST(:summary AS jsonb) WHERE target_id=:id AND scope_hash=:scope',$a+['summary'=>json_encode($summary,JSON_THROW_ON_ERROR)]);
        return $summary;
    }
    private static function utc(string $s):string
    {return (new \DateTimeImmutable($s))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');}
    /** Resolve the current credential scope without granting write privileges to readers. */
    public static function read(PDO $p,array $t,string $view,int $limit,?string $before,?string $state):array
    {
        $r=self::query($p,<<<'SQL'
SELECT t.version,c.id::text AS credential_id,c.version AS credential_version
FROM connector_targets t JOIN connector_credential_bindings b ON b.connector_id=t.id AND b.workspace_id=t.workspace_id AND b.purpose='api'
JOIN credentials c ON c.id=b.credential_id AND c.workspace_id=b.workspace_id
JOIN inventory_asset_credential_bindings a ON a.workspace_id=t.workspace_id AND a.asset_id=t.asset_id AND a.credential_id=c.id AND a.purpose='api'
JOIN inventory_assets asset ON asset.id=t.asset_id AND asset.workspace_id=t.workspace_id AND asset.deleted_at IS NULL
WHERE t.id=:id AND t.workspace_id=:ws AND t.state='active' AND t.proxmox_profile IS NOT NULL AND c.state='active' AND c.deleted_at IS NULL AND c.vault_format='cjev1'
SQL,['id'=>$t['id'],'ws'=>$t['workspace_id']])->fetch(PDO::FETCH_ASSOC);
        $empty=['state'=>'not_collected','observed_at'=>null,'summary'=>null,'items'=>[],'next_before'=>null];
        if(!$r)return ['state'=>'stale']+$empty;
        $a=self::args(array_merge($t,['version'=>(int)$r['version'],'credential_id'=>$r['credential_id'],'credential_version'=>(int)$r['credential_version']]));
        $cursor=self::query($p,'SELECT observed_at,summary FROM proxmox_task_cursor WHERE target_id=:id AND scope_hash=:scope',$a)->fetch(PDO::FETCH_ASSOC);
        if(!$cursor){$old=self::query($p,'SELECT 1 FROM proxmox_task_cursor WHERE target_id=:id LIMIT 1',['id'=>$t['id']])->fetchColumn();return $old?['state'=>'stale']+$empty:$empty;}
        if($view==='events'){$sql='SELECT e.id::text,e.kind,e.state,e.occurred_at,e.observed_at,t.upid,t.node,t.task_type AS type,t.resource_id,t.task_user AS user FROM proxmox_task_events e JOIN proxmox_tasks t ON t.id=e.task_id WHERE e.target_id=:id AND e.scope_hash=:scope';$alias='e';}
        else {$sql='SELECT t.id::text,t.upid,t.node,t.task_type AS type,t.resource_id,t.task_user AS user,t.started_at,t.ended_at,t.state,t.first_seen_at,t.last_seen_at,t.checked_at,t.check_state FROM proxmox_tasks t WHERE t.target_id=:id AND t.scope_hash=:scope';$alias='t';}
        if($before!==null){$sql.=" AND $alias.id<CAST(:before AS bigint)";$a['before']=$before;}
        if($state!==null){$sql.=" AND $alias.state=:state";$a['state']=$state;}
        $items=self::query($p,$sql." ORDER BY $alias.id DESC LIMIT ".($limit+1),$a)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($items)>$limit;if($more)array_pop($items);
        foreach($items as &$item){
            foreach(['started_at','ended_at','occurred_at'] as $k)if(isset($item[$k]))$item[$k]=(int)$item[$k];
            foreach(['first_seen_at','last_seen_at','checked_at','observed_at'] as $k)if(isset($item[$k]))$item[$k]=self::utc($item[$k]);
        }unset($item);
        return ['state'=>'available','observed_at'=>self::utc($cursor['observed_at']),'summary'=>json_decode($cursor['summary'],false,32,JSON_THROW_ON_ERROR),'items'=>$items,'next_before'=>$more?$items[array_key_last($items)]['id']:null];
    }
}
