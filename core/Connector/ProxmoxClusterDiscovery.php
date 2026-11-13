<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Read-only, bounded PVE membership and physical node snapshots. No remote response is stored raw. */
final class ProxmoxClusterDiscovery
{
    public const MAX_NODES=256;
    private const DETAIL_BUDGET_NS=60_000_000_000;

    private static function invalid():never { throw new ConnectorTransportException('connector_protocol_error'); }
    private static function name(mixed $v):string
    {
        if(!is_string($v)||!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,118}[A-Za-z0-9])?$/D',$v))self::invalid();
        return $v;
    }
    private static function number(mixed $v,int $max=PHP_INT_MAX):int
    {
        if(is_string($v)&&preg_match('/^(0|[1-9][0-9]{0,18})$/D',$v)&&strlen($v)<=strlen((string)$max)&& (strlen($v)<strlen((string)$max)||strcmp($v,(string)$max)<=0))$v=(int)$v;
        if(!is_int($v)||$v<0||$v>$max)self::invalid();
        return $v;
    }
    private static function flag(mixed $v):bool
    {
        if(!in_array($v,[0,1,'0','1',false,true],true))self::invalid();
        return in_array($v,[1,'1',true],true);
    }
    private static function text(mixed $v,int $max=256):string
    {
        if(!is_string($v)||strlen($v)>$max||!preg_match('//u',$v)||preg_match('/[\x00-\x1f\x7f]/',$v))self::invalid();
        return $v;
    }
    /** Project only non-secret, documented membership fields from the identity-checked response. */
    public static function membership(array $rows, array $identity):array
    {
        $cluster=null;$members=[];$ids=[];
        foreach($rows as $row){
            if(($row['type']??null)==='cluster'){
                if($cluster!==null)self::invalid();
                $cluster=['name'=>self::name($row['name']??null),'quorate'=>self::flag($row['quorate']??null)];
                foreach(['nodes'=>'reported_nodes','version'=>'config_version','expected_votes'=>'expected_votes','total_votes'=>'total_votes','votes'=>'votes'] as $key=>$dest)
                    if(isset($row[$key]))$cluster[$dest]=self::number($row[$key],2147483647);
                continue;
            }
            if(($row['type']??null)!=='node')continue;
            $name=self::name($row['name']??null);
            if(isset($members[$name])||count($members)>=self::MAX_NODES)self::invalid();
            $member=['name'=>$name,'node_id'=>null,'online'=>null,'local'=>false];
            if(isset($row['nodeid'])){
                $id=self::number($row['nodeid'],2147483647);
                if($identity['cluster']!==null&&($id<1||isset($ids[$id])))self::invalid();
                $ids[$id]=true;$member['node_id']=$id;
            }elseif($identity['cluster']!==null)self::invalid();
            foreach(['online','local'] as $key)if(isset($row[$key]))$member[$key]=self::flag($row[$key]);
            if(isset($row['ip'])){
                if(!is_string($row['ip'])||filter_var($row['ip'],FILTER_VALIDATE_IP)===false)self::invalid();
                $member['ip']=inet_ntop(inet_pton($row['ip']));
            }
            if(isset($row['votes']))$member['votes']=self::number($row['votes'],2147483647);
            $members[$name]=$member;
        }
        if(!$members||($cluster===null)!==($identity['cluster']===null))self::invalid();
        ksort($members,SORT_STRING);
        return ['cluster'=>$cluster,'members'=>$members];
    }
    /** Node /status is an object; store allowlisted hardware, never arbitrary config or credentials. */
    public static function details(mixed $raw):array
    {
        if(!$raw instanceof \stdClass)self::invalid();
        $out=[];
        if(isset($raw->pveversion))$out['pve_version']=self::text($raw->pveversion);
        if(isset($raw->uptime))$out['uptime_seconds']=self::number($raw->uptime);
        if(isset($raw->cpu)){
            if(!is_int($raw->cpu)&&!is_float($raw->cpu))self::invalid();
            $v=(float)$raw->cpu;if(!is_finite($v)||$v<0||$v>1)self::invalid();$out['cpu_utilization']=$v;
        }
        if(isset($raw->cpuinfo)){
            if(!$raw->cpuinfo instanceof \stdClass)self::invalid();
            $cpu=[];
            foreach(['cpus'=>'logical_cpus','cores'=>'cores','sockets'=>'sockets'] as $k=>$dest)if(isset($raw->cpuinfo->$k))$cpu[$dest]=self::number($raw->cpuinfo->$k,65536);
            if(isset($raw->cpuinfo->model))$cpu['model']=self::text($raw->cpuinfo->model);
            if($cpu)$out['cpu']=$cpu;
        }
        foreach(['memory'=>'memory_bytes','swap'=>'swap_bytes','rootfs'=>'rootfs_bytes'] as $key=>$dest){
            if(!isset($raw->$key))continue;
            $data=$raw->$key;if(!$data instanceof \stdClass)self::invalid();$values=[];
            foreach(['total','used','free','avail','available'] as $field)if(isset($data->$field))$values[$field]=(string)self::number($data->$field);
            if($values)$out[$dest]=$values;
        }
        return $out;
    }
    /** Cluster membership is not proof of complete Inventory or ACL visibility. Always partial. */
    public static function collect(callable $get,array $info,int $deadline):ConnectorResult
    {
        $membership=$info['membership'];unset($info['membership']);
        $cluster=$membership['cluster'];$members=$membership['members'];
        $listed=[];
        foreach(ProxmoxApiClient::rows($get('/api2/json/nodes')) as $row){
            $name=self::name($row['node']??null);
            if(isset($listed[$name])||count($listed)>=self::MAX_NODES)self::invalid();
            $status=$row['status']??null;
            $listed[$name]=in_array($status,['online','offline'],true)?$status:'unknown';
        }
        // A node listed outside the identity response can reflect a concurrent membership change.
        // Do not combine incompatible views; allow a later scheduled retry to obtain a coherent view.
        if(array_diff_key($listed,$members))self::invalid();
        $items=[];$clusterId=$cluster===null?null:'cluster:'.$cluster['name'];
        if($cluster!==null)$items[]=['type'=>'cluster','external_id'=>$clusterId,'fields'=>['name'=>$cluster['name'],'status'=>$cluster['quorate']?'online':'unknown']];
        $nodes=[];$rateLimited=false;
        // Rotate the order for large clusters so the detail budget does not always favor the first nodes.
        $names=array_keys($members);
        if(count($names)>16){$start=(int)(floor(time()/60)%count($names));$names=[...array_slice($names,$start),...array_slice($names,0,$start)];}
        foreach($names as $name){
            $node=$members[$name];$name=$node['name'];$visible=array_key_exists($name,$listed);$reported=$listed[$name]??'unknown';
            $membershipStatus=$node['online']===null?'unknown':($node['online']?'online':'offline');
            $status=$membershipStatus==='unknown'?$reported:($reported==='unknown'||$reported===$membershipStatus?$membershipStatus:'unknown');
            $state='not_visible';$detail=null;
            if($visible){
                if($status!=='online')$state=$status==='offline'?'offline':'status_uncertain';
                elseif($rateLimited)$state='rate_limited';
                elseif(hrtime(true)>=$deadline)$state='budget_exhausted';
                else{
                    try{$detail=self::details($get('/api2/json/nodes/'.$name.'/status'));$state=$detail?'available':'no_data';}
                    catch(ConnectorTransportException $e){
                        // Only explicit permission denial or remote node availability errors are partial.
                        // Authentication, TLS, malformed data and transport failure still fail the attempt.
                        if($e->httpStatus===403)$state='permission_denied';
                        elseif($e->httpStatus===404||$e->category==='connector_unavailable')$state='unavailable';
                        elseif($e->category==='connector_rate_limited'){$state='rate_limited';$rateLimited=true;}
                        else throw $e;
                    }
                }
            }
            $node+=['external_id'=>'node:'.$name,'status'=>$status,'visible_in_nodes'=>$visible,'details_state'=>$state,'details'=>$detail];
            $nodes[$name]=$node;
            $fields=['name'=>$name,'status'=>$status];
            $items[]=['type'=>'hypervisor_node','external_id'=>'node:'.$name,'fields'=>$fields,...($clusterId===null?[]:['parent_external_id'=>$clusterId])];
        }
        ksort($nodes,SORT_STRING);
        $info['cluster_discovery']=['schema_version'=>1,'cluster'=>$cluster,'standalone'=>$cluster===null,'membership_scope'=>'reported_only','visible_node_count'=>count($listed),'member_count'=>count($members),'reported_node_count_mismatch'=>isset($cluster['reported_nodes'])&&$cluster['reported_nodes']!==count($members),'details_complete'=>!array_filter($nodes,static fn($n)=>$n['details_state']!=='available'),'nodes'=>array_values($nodes)];
        return new ConnectorResult($items,false,[],$info);
    }
    public static function deadline():int{return hrtime(true)+self::DETAIL_BUDGET_NS;}
}
