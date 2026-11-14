<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Bounded incremental reads; never retain remote task params, output or error messages. */
final class VmmanagerTaskDiscovery
{
    public static function normalize(\stdClass $row):array
    {
        $id=VmmanagerApiClient::id($row->id??null);
        $name=$row->name??null;
        if(!is_string($name)||!preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D',$name)) throw new ConnectorTransportException('connector_protocol_error');
        $status=$row->status??null;
        $state=match($status){'created'=>'queued','deferred'=>'deferred','running'=>'running','complete'=>'succeeded','fail'=>'failed',default=>'unknown'};
        $consul=$row->consul_id??null;
        if(is_int($consul))$consul=(string)$consul;
        if(!is_string($consul)||$consul==='0'||!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$consul))$consul=null;
        $host=$row->host??null;
        if($host instanceof \stdClass)$host=$host->id??null;
        try{$host=VmmanagerApiClient::id($host);}catch(ConnectorTransportException){$host=null;}
        return ['remote_id'=>$id,'consul_id'=>$consul,'type'=>$name,'resource_id'=>$host===null?null:'host:'.$host,'state'=>$state];
    }
    public static function terminal(string $state):bool{return in_array($state,['succeeded','failed'],true);}
    public static function merge(string $old,string $new):string
    {return self::terminal($old)||($new==='unknown'&&$old!=='unknown')?$old:$new;}

    private static function put(array &$tasks,array $task):void
    {
        $id=$task['remote_id'];$old=$tasks[$id]??null;
        if($old!==null){
            if($old['type']!==$task['type']||($old['consul_id']!==null&&$task['consul_id']!==null&&$old['consul_id']!==$task['consul_id']))throw new ConnectorTransportException('connector_protocol_error');
            $task['state']=self::merge($old['state'],$task['state']);
            $task['consul_id']=$task['consul_id']??$old['consul_id'];
            if($old['resource_id']!==null&&$task['resource_id']!==null&&$old['resource_id']!==$task['resource_id'])throw new ConnectorTransportException('connector_protocol_error');
            $task['resource_id']=$task['resource_id']??$old['resource_id'];
        }
        $tasks[$id]=$task;
    }

    /** Each callback is a validated list request (id > after, ascending). An empty page is exhaustion. */
    public static function collect(callable $page,array $previous,array $identity):array
    {
        $cursor=$previous['cursor']??['after'=>0,'sweep_after'=>0,'identity'=>$identity];
        if(($cursor['identity']??null)!=$identity)throw new ConnectorTransportException('connector_auth_failed');
        $after=$cursor['after'];$sweep=$cursor['sweep_after'];$tasks=[];$checked=[];$exhausted=false;
        // Poll old nonterminal tasks first. A missing row does not imply failure or completion.
        foreach(array_slice($previous['pending']??[],0,20) as $id){
            $id=VmmanagerApiClient::id($id);$rows=$page($id-1,1);
            if($rows===[]||VmmanagerApiClient::id($rows[0]->id??null)!==$id){$checked[$id]='not_visible';continue;}
            self::put($tasks,self::normalize($rows[0]));$checked[$id]='available';
        }
        // Bounded new history: cursor advances only over successfully normalized rows.
        for($i=0;$i<5;$i++){
            $rows=$page($after,100);
            if($rows===[]){$exhausted=true;break;}
            foreach($rows as $row){$task=self::normalize($row);$after=$task['remote_id'];self::put($tasks,$task);}
        }
        // One rolling page of old IDs catches late visibility / out-of-order commits without a full reload.
        // Use the previous high-water mark so new pages are not read a second time during initial import.
        $oldHigh=$cursor['after'];
        if($oldHigh>0){
            $rows=$page($sweep,100);$reached=false;
            foreach($rows as $row){$task=self::normalize($row);$id=$task['remote_id'];if($id>$oldHigh){$reached=true;break;}self::put($tasks,$task);$sweep=$id;}
            if($rows===[]||$reached||$sweep>=$oldHigh)$sweep=0;
        }
        foreach($tasks as $id=>$task)if(isset($checked[$id]))$checked[$id]='available';
        // List responses may lag a prior status read. Never overwrite terminal evidence within a batch.
        // The store also enforces this across batches.
        return ['expected_revision'=>$previous['revision']??0,'cursor'=>['after'=>$after,'sweep_after'=>$sweep,'identity'=>$identity],
            'tasks'=>array_values($tasks),'checked'=>$checked,'observed_count'=>count($tasks),'high_water_id'=>$after,'sweep_after'=>$sweep,
            'caught_up'=>$exhausted,'visibility'=>$identity['administrator']&&$identity['owner_id']===$identity['trustee_id']?'administrative':'restricted'];
    }
}
