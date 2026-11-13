<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Bounded, resumable PVE task history. No task log or arbitrary exit message is retained. */
final class ProxmoxTaskDiscovery
{
    public const PAGE=100;
    public const OVERLAP=600;
    private const BACKFILL=2592000;
    private static function invalid():never {throw new ConnectorTransportException('connector_protocol_error');}
    private static function integer(mixed $v):int
    {if(is_string($v)&&preg_match('/^[0-9]{1,12}$/D',$v))$v=(int)$v;if(!is_int($v)||$v<0||$v>253402300799)self::invalid();return $v;}
    public static function identity(mixed $upid):array
    {
        // PVE escapes unsafe worker IDs with literal backslash-xHH; the path is URL encoded.
        if(!is_string($upid)||strlen($upid)>512||!preg_match('~^UPID:([A-Za-z0-9][A-Za-z0-9._-]{0,119}):([0-9A-Fa-f]{1,16}):([0-9A-Fa-f]{1,16}):([0-9A-Fa-f]{8}):([A-Za-z0-9_.-]{1,64}):([^:\\x00-\\x20\\x7f]{0,180}):([^:\\x00-\\x20\\x7f]{1,180}):$~D',$upid,$m))self::invalid();
        // Printable ASCII only, including escaped IDs; never decode into a path.
        if(!preg_match('/^[\x21-\x7e]+$/D',$upid))self::invalid();
        return ['upid'=>$upid,'node'=>$m[1],'started_at'=>(int)hexdec($m[4]),'type'=>$m[5],'resource_id'=>$m[6],'user'=>$m[7]];
    }
    private static function outcome(mixed $status):string
    {
        if(!is_string($status)||$status===''||strlen($status)>8192)self::invalid();
        return match(true){$status==='OK'=>'succeeded',str_starts_with($status,'WARNINGS:')=>'warning',strtolower($status)==='unknown'=>'unknown',default=>'failed'};
    }
    public static function row(array $r,string $node,bool $active):array
    {
        $out=self::identity($r['upid']??null);
        if($out['node']!==$node||($r['node']??$node)!==$node||($r['type']??$out['type'])!==$out['type']||self::integer($r['starttime']??$out['started_at'])!==$out['started_at'])self::invalid();
        $end=isset($r['endtime'])?self::integer($r['endtime']):null;
        if($end!==null&&$end<$out['started_at'])self::invalid();
        $status=$r['status']??null;
        if($active&&$end===null&&($status===null||$status==='RUNNING'))$state='running';
        else $state=$status===null?'unknown':self::outcome($status);
        if(!$active&&$status==='RUNNING')self::invalid();
        return $out+['state'=>$state,'ended_at'=>$end];
    }
    private static function merge(array &$rows,array $task):void
    {
        $old=$rows[$task['upid']]??null;
        if($old!==null){
            if($old['state']!=='running'&&($task['state']==='running'||($old['state']!=='unknown'&&$task['state']==='unknown')))$task['state']=$old['state'];
            if($task['ended_at']===null)$task['ended_at']=$old['ended_at'];
        }
        $rows[$task['upid']]=$task;
    }
    private static function fetch(callable $get,string $path,callable $project):array
    {
        try{return ['state'=>'available','data'=>$project($get($path))];}
        catch(ConnectorTransportException $e){
            if($e->httpStatus===403)return ['state'=>'permission_denied','data'=>null];
            if($e->httpStatus===404||($e->httpStatus===400&&str_ends_with($path,'/status')))return ['state'=>'unavailable','data'=>null];
            if($e->category==='connector_rate_limited')return ['state'=>'rate_limited','data'=>null];
            throw $e;
        }
    }
    private static function page(callable $get,string $node,string $source,int $offset,?int $since=null,?int $until=null):array
    {
        $path='/api2/json/nodes/'.$node.'/tasks?source='.$source.'&start='.$offset.'&limit='.self::PAGE;
        if($since!==null)$path.='&since='.$since.'&until='.$until;
        return self::fetch($get,$path,static function($raw)use($node,$source,$since,$until):array{
            $rows=ProxmoxApiClient::rows($raw);if(count($rows)>self::PAGE)self::invalid();$out=[];
            foreach($rows as $r){$v=self::row($r,$node,$source==='active');if($since!==null&&($v['started_at']<$since||$v['started_at']>$until))self::invalid();if(isset($out[$v['upid']]))self::invalid();$out[$v['upid']]=$v;}
            return array_values($out);
        });
    }
    /** One archive page per node. Split dense windows instead of offset-paging a moving archive.
     * A single second with >=100 tasks is the only offset fallback (overlapping pages). */
    private static function archive(callable $get,string $node,array &$c,int $now,array &$rows):string
    {
        if(!isset($c['next']))$c['next']=max(0,$now-self::BACKFILL);
        if(!isset($c['until'])){
            $c['until']=$now;$c['end']=$now;$c['offset']=0;
        }
        $r=self::page($get,$node,'archive',$c['offset'],$c['next'],$c['until']);
        if($r['state']!=='available')return $r['state'];
        foreach($r['data'] as $t)self::merge($rows,$t);
        if(count($r['data'])===self::PAGE){
            if($c['next']<$c['until']){$c['until']=$c['next']+intdiv($c['until']-$c['next'],2);$c['offset']=0;}
            else $c['offset']+=self::PAGE-10;
            return 'catching_up';
        }
        if($c['until']<$c['end']){$c['next']=$c['until']+1;$c['until']=$c['end'];$c['offset']=0;return 'catching_up';}
        // Replay recent time to cover delayed archive writes and same-second boundaries.
        $c['next']=max(0,$c['end']-self::OVERLAP);$c['covered_through']=$c['end'];unset($c['until'],$c['end'],$c['offset']);return 'available';
    }
    /** Follow the archive head by UPID, independently of start time: a long-running
     * task may enter the archive well after its start-time window has closed. */
    private static function head(callable $get,string $node,array &$c,array &$rows):string
    {
        $scan=$c['head_scan']??null;$offset=$scan['offset']??0;
        $r=self::page($get,$node,'archive',$offset);if($r['state']!=='available')return $r['state'];
        $ids=array_column($r['data'],'upid');foreach($r['data'] as $t)self::merge($rows,$t);
        if($scan===null){
            if($ids===[])return 'available';
            $scan=['candidate'=>$ids[0],'anchor'=>$c['head']??null,'offset'=>0];
        }
        if($scan['anchor']===null||in_array($scan['anchor'],$ids,true)||count($ids)<self::PAGE){
            $c['head']=$scan['candidate'];unset($c['head_scan']);return $scan['anchor']!==null&&!in_array($scan['anchor'],$ids,true)?'anchor_lost':'available';
        }
        $scan['offset']=$offset+self::PAGE-10;$c['head_scan']=$scan;return 'catching_up';
    }
    public static function collect(callable $get,array $info,array $previous,int $now,int $deadline):ConnectorResult
    {
        $membership=$info['membership']??null;unset($info['membership']);
        $nodes=ProxmoxApiClient::rows($get('/api2/json/nodes'));if(count($nodes)>256)self::invalid();$visible=[];
        foreach($nodes as $n){$name=$n['node']??null;if(!is_string($name)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D',$name)||isset($visible[$name])||($membership!==null&&!isset($membership['members'][$name])))self::invalid();$visible[$name]=$n['status']??'unknown';}
        ksort($visible,SORT_STRING);$names=array_map('strval',array_keys($visible));$cursor=$previous['cursor']??['nodes'=>[],'next_node'=>null];$rows=[];$checked=[];$states=[];$limited=false;
        // Poll old running tasks even if their start time is outside the replay window.
        $pollDeadline=min($deadline,hrtime(true)+20_000_000_000);
        foreach($previous['pending']??[] as $p){
            if($limited||hrtime(true)>=$pollDeadline)break;
            $id=self::identity($p['upid']);$node=$id['node'];if(!isset($visible[$node])||$visible[$node]==='offline'){$checked[$id['upid']]='offline';continue;}
            if(isset($rows[$id['upid']])&&$rows[$id['upid']]['state']!=='running')continue;
            $r=self::fetch($get,'/api2/json/nodes/'.$node.'/tasks/'.rawurlencode($id['upid']).'/status',static function($raw)use($id):array{
                if(!$raw instanceof \stdClass||!in_array($raw->status??null,['running','stopped'],true))self::invalid();
                if(isset($raw->upid)&&$raw->upid!==$id['upid'])self::invalid();
                if(isset($raw->starttime)&&self::integer($raw->starttime)!==$id['started_at'])self::invalid();
                $end=isset($raw->endtime)?self::integer($raw->endtime):null;if($end!==null&&$end<$id['started_at'])self::invalid();
                return $id+['state'=>$raw->status==='running'?'running':self::outcome($raw->exitstatus??'unknown'),'ended_at'=>$end];
            });
            $checked[$id['upid']]=$r['state'];if($r['state']==='available')$rows[$id['upid']]=$r['data'];if($r['state']==='rate_limited')$limited=true;
        }
        // Persist fairness across runs; keep cursors for temporarily invisible nodes.
        $first=0;foreach($names as $i=>$name)if(strcmp($name,(string)($cursor['next_node']??''))>=0){$first=$i;break;}
        $ordered=[...array_slice($names,$first),...array_slice($names,0,$first)];
        foreach($ordered as $i=>$node){
            $states[$node]=['archive'=>'budget_exhausted','active'=>'budget_exhausted','head'=>'budget_exhausted'];
            if($i>=4||$limited||hrtime(true)>=$deadline)continue;
            $cursor['next_node']=$ordered[($i+1)%count($ordered)];
            if($visible[$node]==='offline'){$states[$node]=['archive'=>'offline','active'=>'offline','head'=>'offline'];continue;}
            $c=$cursor['nodes'][$node]??[];
            $a=self::page($get,$node,'active',(int)($c['active_offset']??0));$states[$node]['active']=$a['state'];
            if($a['state']==='available'){
                foreach($a['data'] as $t)self::merge($rows,$t);
                $c['active_offset']=count($a['data'])===self::PAGE?(int)($c['active_offset']??0)+self::PAGE-10:0;
                if($c['active_offset']>0)$states[$node]['active']='catching_up';
            }
            if($a['state']==='rate_limited')$limited=true;
            if(!$limited&&hrtime(true)<$deadline){$states[$node]['head']=self::head($get,$node,$c,$rows);if($states[$node]['head']==='rate_limited')$limited=true;}
            if(!$limited&&hrtime(true)<$deadline){$states[$node]['archive']=self::archive($get,$node,$c,$now,$rows);if($states[$node]['archive']==='rate_limited')$limited=true;}
            $cursor['nodes'][$node]=$c;
            $states[$node]['covered_through']=$c['covered_through']??null;
        }
        $info['task_discovery']=['schema_version'=>1,'visibility'=>'reported_only','nodes'=>(object)$states,'cursor'=>$cursor,'expected_revision'=>$previous['revision']??0,'tasks'=>array_values($rows),'checked'=>$checked,'observed_count'=>count($rows),'rate_limited'=>$limited];
        return new ConnectorResult([],false,[],$info);
    }
}
