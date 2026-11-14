<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use PDO;
use Cajeer\Servers\Http\Exception\HttpException;

/** Cursor, task updates and events are committed with the authorized worker result. */
final class VmmanagerTaskStore
{
    private static function args(array $t):array
    {return ['id'=>$t['id'],'scope'=>hash('sha256',json_encode([(int)$t['version'],$t['credential_id'],(int)$t['credential_version']],JSON_THROW_ON_ERROR))];}
    private static function q(PDO $p,string $sql,array $a):\PDOStatement
    {$q=$p->prepare($sql);$q->execute($a);return $q;}
    public static function previous(PDO $p,array $t):array
    {
        $a=self::args($t);$r=self::q($p,'SELECT revision,cursor FROM vmmanager_task_cursor WHERE target_id=:id AND scope_hash=:scope',$a)->fetch(PDO::FETCH_ASSOC);
        $actions=self::q($p,"SELECT id::text,node,remote_task FROM connector_actions WHERE target_id=:target AND workspace_id=:workspace AND target_version=:tv AND credential_id=:credential AND credential_version=:cv AND status='submitted' AND action IN ('vmmanager.host.start','vmmanager.host.stop','vmmanager.host.reboot') AND remote_task IS NOT NULL AND node IS NOT NULL ORDER BY task_checked_at ASC NULLS FIRST,created_at,id LIMIT 4",['target'=>$t['id'],'workspace'=>$t['workspace_id'],'tv'=>$t['version'],'credential'=>$t['credential_id'],'cv'=>$t['credential_version']])->fetchAll(PDO::FETCH_ASSOC);
        if(!$r)return ['revision'=>0,'actions'=>$actions];
        $pending=self::q($p,"SELECT remote_id FROM vmmanager_tasks WHERE target_id=:id AND scope_hash=:scope AND state IN ('queued','deferred','running','unknown') ORDER BY checked_at ASC NULLS FIRST,id LIMIT 20",$a)->fetchAll(PDO::FETCH_COLUMN);
        return ['revision'=>(int)$r['revision'],'cursor'=>json_decode($r['cursor'],true,32,JSON_THROW_ON_ERROR),'pending'=>array_map('intval',$pending),'actions'=>$actions];
    }
    private static function event(PDO $p,array $a,string $id,string $kind,string $state,string $at,int $seq=0):void
    {self::q($p,'INSERT INTO vmmanager_task_events(task_id,target_id,scope_hash,kind,state,observed_at,transition_seq) VALUES(:task,:id,:scope,:kind,:state,:at,:seq) ON CONFLICT(task_id,transition_seq,kind) DO NOTHING',$a+['task'=>$id,'kind'=>$kind,'state'=>$state,'at'=>$at,'seq'=>$seq]);}
    public static function save(PDO $p,array $t,array $batch,string $at):array
    {
        $a=self::args($t);$old=self::q($p,'SELECT revision FROM vmmanager_task_cursor WHERE target_id=:id AND scope_hash=:scope FOR UPDATE',$a)->fetchColumn();
        if((int)$old!==$batch['expected_revision'])throw new HttpException(409,'VMmanager task cursor superseded.','snapshot_stale');
        $summary=array_diff_key($batch,array_flip(['tasks','checked','cursor','expected_revision','action_checks']));$summary['new_tasks']=0;$summary['completed_tasks']=0;
        self::q($p,<<<'SQL'
INSERT INTO vmmanager_task_cursor(target_id,workspace_id,scope_hash,revision,observed_at,cursor,summary)
VALUES(:id,:ws,:scope,:revision,:at,CAST(:cursor AS jsonb),CAST(:summary AS jsonb))
ON CONFLICT(target_id,scope_hash) DO UPDATE SET revision=EXCLUDED.revision,observed_at=EXCLUDED.observed_at,cursor=EXCLUDED.cursor,summary=EXCLUDED.summary
SQL,$a+['ws'=>$t['workspace_id'],'revision'=>$batch['expected_revision']+1,'at'=>$at,'cursor'=>json_encode($batch['cursor'],JSON_THROW_ON_ERROR),'summary'=>json_encode($summary,JSON_THROW_ON_ERROR)]);
        foreach($batch['tasks'] as $task){
            $key=$a+['remote'=>$task['remote_id']];$old=self::q($p,'SELECT id::text,state,task_type,consul_id,resource_id,transition_seq FROM vmmanager_tasks WHERE target_id=:id AND scope_hash=:scope AND remote_id=:remote FOR UPDATE',$key)->fetch(PDO::FETCH_ASSOC);
            $state=$task['state'];
            if(!$old){
                $id=(string)self::q($p,<<<'SQL'
INSERT INTO vmmanager_tasks(target_id,scope_hash,remote_id,consul_id,task_type,resource_id,state,first_seen_at,last_seen_at,checked_at,check_state)
VALUES(:id,:scope,:remote,:consul,:type,:resource,:state,:first,:last,:checked,'available') RETURNING id::text
SQL,$key+['consul'=>$task['consul_id'],'type'=>$task['type'],'resource'=>$task['resource_id'],'state'=>$state,'first'=>$at,'last'=>$at,'checked'=>$at])->fetchColumn();
                self::event($p,$a,$id,'task_discovered',$state,$at);$summary['new_tasks']++;
                if(VmmanagerTaskDiscovery::terminal($state)){self::event($p,$a,$id,'task_completed',$state,$at);$summary['completed_tasks']++;}
            }else{
                $id=$old['id'];$before=$old['state'];$seq=(int)$old['transition_seq'];
                if($old['task_type']!==$task['type']||($old['consul_id']!==null&&$task['consul_id']!==null&&$old['consul_id']!==$task['consul_id']))throw new ConnectorTransportException('connector_protocol_error');
                if($old['resource_id']!==null&&$task['resource_id']!==null&&$old['resource_id']!==$task['resource_id'])throw new ConnectorTransportException('connector_protocol_error');
                $state=VmmanagerTaskDiscovery::merge($before,$state);
                if($before!==$state){
                    $seq++;
                    if(VmmanagerTaskDiscovery::terminal($state)){self::event($p,$a,$id,'task_completed',$state,$at,$seq);$summary['completed_tasks']++;}
                    elseif($state==='running')self::event($p,$a,$id,'task_started',$state,$at,$seq);
                    elseif($state==='deferred')self::event($p,$a,$id,'task_deferred',$state,$at,$seq);
                    elseif($state==='queued')self::event($p,$a,$id,'task_queued',$state,$at,$seq);
                    if($before==='unknown')self::event($p,$a,$id,'task_outcome_resolved',$state,$at,$seq);
                }
                self::q($p,'UPDATE vmmanager_tasks SET state=:state,transition_seq=:seq,consul_id=COALESCE(consul_id,:consul),resource_id=COALESCE(resource_id,:resource),last_seen_at=:at,checked_at=:checked,check_state=\'available\' WHERE id=:task',['state'=>$state,'seq'=>$seq,'consul'=>$task['consul_id'],'resource'=>$task['resource_id'],'at'=>$at,'checked'=>$at,'task'=>$id]);
            }
        }
        foreach($batch['checked'] as $id=>$status)self::q($p,'UPDATE vmmanager_tasks SET checked_at=:at,check_state=:status WHERE target_id=:id AND scope_hash=:scope AND remote_id=:remote',$a+['at'=>$at,'status'=>$status,'remote'=>$id]);
        self::q($p,'UPDATE vmmanager_task_cursor SET summary=CAST(:summary AS jsonb) WHERE target_id=:id AND scope_hash=:scope',$a+['summary'=>json_encode($summary,JSON_THROW_ON_ERROR)]);
        return $summary;
    }
    private static function utc(string $s):string
    {return (new \DateTimeImmutable($s))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');}
    public static function read(PDO $p,array $t,string $view,int $limit,?string $before,?string $state):array
    {
        $empty=['state'=>'not_collected','observed_at'=>null,'summary'=>null,'items'=>[],'next_before'=>null];
        $r=self::q($p,<<<'SQL'
SELECT t.version,c.id::text AS credential_id,c.version AS credential_version
FROM connector_targets t JOIN connector_credential_bindings b ON b.connector_id=t.id AND b.workspace_id=t.workspace_id AND b.purpose='api'
JOIN credentials c ON c.id=b.credential_id AND c.workspace_id=b.workspace_id
JOIN inventory_asset_credential_bindings a ON a.workspace_id=t.workspace_id AND a.asset_id=t.asset_id AND a.credential_id=c.id AND a.purpose='api'
JOIN inventory_assets asset ON asset.id=t.asset_id AND asset.workspace_id=t.workspace_id AND asset.deleted_at IS NULL
WHERE t.id=:id AND t.workspace_id=:ws AND t.provider='vmmanager' AND t.state='active' AND t.vmmanager_profile IS NOT NULL AND c.state='active' AND c.deleted_at IS NULL AND c.vault_format='cjev1'
SQL,['id'=>$t['id'],'ws'=>$t['workspace_id']])->fetch(PDO::FETCH_ASSOC);
        if(!$r)return ['state'=>'stale']+$empty;
        $a=self::args(array_merge($t,$r));
        $cursor=self::q($p,'SELECT observed_at,summary FROM vmmanager_task_cursor WHERE target_id=:id AND scope_hash=:scope',$a)->fetch(PDO::FETCH_ASSOC);
        if(!$cursor)return self::q($p,'SELECT 1 FROM vmmanager_task_cursor WHERE target_id=:id LIMIT 1',['id'=>$t['id']])->fetchColumn()?['state'=>'stale']+$empty:$empty;
        if($view==='events'){$sql='SELECT e.id::text,e.kind,e.state,e.observed_at,t.remote_id::text,t.consul_id,t.task_type AS type,t.resource_id FROM vmmanager_task_events e JOIN vmmanager_tasks t ON t.id=e.task_id WHERE e.target_id=:id AND e.scope_hash=:scope';$alias='e';}
        else {$sql='SELECT t.id::text,t.remote_id::text,t.consul_id,t.task_type AS type,t.resource_id,t.state,t.first_seen_at,t.last_seen_at,t.checked_at,t.check_state FROM vmmanager_tasks t WHERE t.target_id=:id AND t.scope_hash=:scope';$alias='t';}
        if($before!==null){$sql.=" AND $alias.id<CAST(:before AS bigint)";$a['before']=$before;}
        if($state!==null){$sql.=" AND $alias.state=:state";$a['state']=$state;}
        $items=self::q($p,$sql." ORDER BY $alias.id DESC LIMIT ".($limit+1),$a)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($items)>$limit;if($more)array_pop($items);
        foreach($items as &$item)foreach(['first_seen_at','last_seen_at','checked_at','observed_at'] as $key)if(isset($item[$key]))$item[$key]=self::utc($item[$key]);
        unset($item);
        return ['state'=>'available','observed_at'=>self::utc($cursor['observed_at']),'summary'=>json_decode($cursor['summary'],true,32,JSON_THROW_ON_ERROR),'items'=>$items,'next_before'=>$more?$items[array_key_last($items)]['id']:null];
    }
}
