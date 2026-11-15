<?php
declare(strict_types=1);
namespace Cajeer\Servers\Events;
use PDO;

final class EventStore
{
    public static function previous(PDO $pdo,array $target):array
    {
        $q=$pdo->prepare('SELECT cursor FROM system_event_cursors WHERE target_id=:id AND workspace_id=:ws AND target_version=:v AND credential_id=:c AND credential_version=:cv');
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id'],'v'=>$target['version'],'c'=>$target['credential_id'],'cv'=>$target['credential_version']]);$value=$q->fetchColumn();return $value===false?[]:EventDiscovery::cursor($target['provider'],json_decode($value,true,16,JSON_THROW_ON_ERROR));
    }
    /** Caller holds a transaction. Lock target serializes quotas and source writes. */
    public static function append(PDO $pdo,array $target,string $source,array $rows):int
    {
        if(!$pdo->inTransaction())throw new \LogicException('Event append requires transaction.');
        if(!in_array($source,['ssh','winrm','syslog'],true)||($source!=='syslog'&&($target['provider']??null)!==$source)||count($rows)>200)throw new \InvalidArgumentException('Invalid event source or batch.');
        $q=$pdo->prepare("SELECT id FROM connector_targets WHERE id=:id AND workspace_id=:ws AND state='active' FOR UPDATE");$q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id']]);if(!$q->fetchColumn())throw new EventRejected('Event target unavailable.');
        $q=$pdo->prepare('SELECT count(*) FROM system_events WHERE target_id=:id');$q->execute(['id'=>$target['id']]);$count=(int)$q->fetchColumn();
        if($count>100000)throw new EventRejected('Event target quota reached.');
        $batch=[];
        foreach($rows as $row){
            $row=SystemEvent::normalize($row);
            if($row['key']==='')throw new \InvalidArgumentException('Missing event identity.');
            $row['key']=hash('sha256',$row['key']);$batch[]=$row;
        }
        if($batch===[])return 0;
        // Keep input order (IDs are the pagination cursor) and first occurrence on conflict.
        $q=$pdo->prepare(<<<'SQL'
INSERT INTO system_events(target_id,workspace_id,source,event_key,channel,event_at,severity,facility,hostname,application,message)
SELECT CAST(:id AS uuid),CAST(:ws AS uuid),:source,r.key,r.channel,r.event_at,r.severity,r.facility,r.hostname,r.application,r.message
FROM jsonb_array_elements(CAST(:batch AS jsonb)) WITH ORDINALITY AS b(item,ord)
CROSS JOIN LATERAL jsonb_to_record(b.item) AS r(key text,channel text,event_at timestamptz,severity smallint,facility smallint,hostname text,application text,message text)
ORDER BY b.ord
ON CONFLICT(target_id,source,event_key) DO NOTHING
SQL);
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id'],'source'=>$source,'batch'=>json_encode($batch,JSON_THROW_ON_ERROR)]);
        $added=$q->rowCount();
        if($count+$added>100000)throw new EventRejected('Event target quota reached.');
        return $added;
    }
    /** Resolve a locally authorized source at ingestion time, not just at socket acceptance. */
    public static function receive(PDO $pdo,array $source,array $events):int
    {
        if(!$pdo->inTransaction())throw new \LogicException('Syslog receive requires transaction.');
        $q=$pdo->prepare("SELECT id::text,workspace_id::text,provider FROM connector_targets WHERE id=:id AND version=:version AND state='active' AND provider IN ('ssh','winrm','snmp','routeros') FOR UPDATE");
        $q->execute(['id'=>$source['target_id'],'version'=>$source['target_version']]);$target=$q->fetch(PDO::FETCH_ASSOC);
        if(!$target)throw new EventRejected('Syslog target unavailable.');
        return self::append($pdo,$target,'syslog',$events);
    }
    public static function save(PDO $pdo,array $target,array $batch):array
    {
        if(!$pdo->inTransaction())throw new \LogicException('Event save requires transaction.');
        $q=$pdo->prepare("SELECT id FROM connector_targets WHERE id=:id AND workspace_id=:ws AND version=:v AND state='active' FOR UPDATE");
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id'],'v'=>$target['version']]);if(!$q->fetchColumn())throw new EventRejected('Event target revision changed.');
        if(self::previous($pdo,$target)!==$batch['previous'])throw new \Cajeer\Servers\Http\Exception\HttpException(409,'Event cursor superseded.','conflict');
        $added=self::append($pdo,$target,$target['provider'],$batch['events']);
        $q=$pdo->prepare('INSERT INTO system_event_cursors(target_id,workspace_id,target_version,credential_id,credential_version,cursor) VALUES(:id,:ws,:v,:c,:cv,CAST(:cursor AS jsonb)) ON CONFLICT(target_id) DO UPDATE SET target_version=EXCLUDED.target_version,credential_id=EXCLUDED.credential_id,credential_version=EXCLUDED.credential_version,cursor=EXCLUDED.cursor,updated_at=clock_timestamp()');
        $q->execute(['id'=>$target['id'],'ws'=>$target['workspace_id'],'v'=>$target['version'],'c'=>$target['credential_id'],'cv'=>$target['credential_version'],'cursor'=>json_encode($batch['cursor'],JSON_THROW_ON_ERROR)]);
        return ['observed_count'=>count($batch['events']),'inserted_count'=>$added,'more'=>$batch['more']];
    }
    public static function read(PDO $pdo,array $target,int $limit,?string $before,?int $severity,string $search,?string $source=null,?string $from=null,?string $to=null):array
    {
        if($limit<1||$limit>100)throw new \InvalidArgumentException('Invalid event page.');
        $sql="SELECT id::text,source,channel,to_char(event_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS event_at,to_char(received_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS received_at,severity,facility,hostname,application,message FROM system_events WHERE target_id=:id AND workspace_id=:ws";$p=['id'=>$target['id'],'ws'=>$target['workspace_id']];
        if($before!==null){$sql.=' AND id<CAST(:before AS bigint)';$p['before']=$before;}if($severity!==null){$sql.=' AND severity=:severity';$p['severity']=$severity;}if($search!==''){$sql.=" AND strpos(lower(message),lower(:search))>0";$p['search']=$search;}
        if($source!==null){$sql.=' AND source=:source';$p['source']=$source;}if($from!==null){$sql.=' AND received_at>=CAST(:from AS timestamptz)';$p['from']=$from;}if($to!==null){$sql.=' AND received_at<CAST(:to AS timestamptz)';$p['to']=$to;}
        $q=$pdo->prepare($sql.' ORDER BY id DESC LIMIT '.($limit+1));$q->execute($p);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);foreach($rows as &$row){$row['severity']=(int)$row['severity'];$row['facility']=$row['facility']===null?null:(int)$row['facility'];}unset($row);return ['events'=>$rows,'next_before'=>$more?$rows[count($rows)-1]['id']:null];
    }
    public static function retain(PDO $pdo,int $days=30,int $limit=10000):int
    {
        if($days<1||$days>365||$limit<1||$limit>10000)throw new \InvalidArgumentException('Invalid retention.');
        $q=$pdo->prepare('DELETE FROM system_events WHERE id IN (SELECT id FROM system_events WHERE received_at<statement_timestamp()-make_interval(days=>CAST(:days AS integer)) ORDER BY received_at,id LIMIT '.$limit.' FOR UPDATE SKIP LOCKED)');$q->execute(['days'=>$days]);return $q->rowCount();
    }
}
