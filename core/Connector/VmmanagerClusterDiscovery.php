<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** VMmanager 6 v3 cluster/node list schemas. Only allowlisted fields enter persistence. */
final class VmmanagerClusterDiscovery
{
    public static function build(array $clusters,array $nodes,bool $full):array
    {
        if(count($clusters)+count($nodes)>2000) throw new ConnectorTransportException('connector_protocol_error');
        $cs=[];$ns=[];$items=[];$unlinked=0;
        foreach($clusters as $row){
            $id=VmmanagerApiClient::id($row->id??null);
            if(isset($cs[$id]))throw new ConnectorTransportException('connector_protocol_error');
            $name=self::name($row->name??null,'Cluster '.$id);
            $type=in_array($row->virtualization_type??null,['kvm','lxd'],true)?$row->virtualization_type:'unknown';
            $cs[$id]=['id'=>$id,'external_id'=>'cluster:'.$id,'name'=>$name,'virtualization_type'=>$type,'visible_nodes'=>0];
            $items[]=['type'=>'cluster','external_id'=>'cluster:'.$id,'fields'=>['name'=>$name]];
        }
        foreach($nodes as $row){
            $id=VmmanagerApiClient::id($row->id??null);
            if(isset($ns[$id]))throw new ConnectorTransportException('connector_protocol_error');
            $cluster=$row->cluster??null;
            $cid=$cluster===null?null:VmmanagerApiClient::id($cluster instanceof \stdClass?($cluster->id??null):$cluster);
            $linked=$cid!==null&&isset($cs[$cid]);
            if(!$linked){$unlinked++;if($full)throw new ConnectorTransportException('connector_protocol_error');}
            $name=self::name($row->name??null,'Node '.$id);
            $maintenance=($row->maintenance_mode??null)===true;
            $state=self::name($row->state??null,'unknown',32);
            $status=$maintenance?'maintenance':match($state){'active','online','running'=>'online','offline','inactive','unreachable'=>'offline',default=>'unknown'};
            $ips=[];foreach(['ip','ipv6'] as $key){$ip=$row->$key??null;if(is_string($ip)&&filter_var($ip,FILTER_VALIDATE_IP)!==false)$ips[]=inet_ntop(inet_pton($ip));}
            $ips=array_values(array_unique($ips));
            $ns[$id]=['id'=>$id,'external_id'=>'node:'.$id,'name'=>$name,'cluster_id'=>$cid,'cluster_visible'=>$linked,'status'=>$status,'remote_state'=>$state,'maintenance_mode'=>$maintenance,'ip_addresses'=>$ips,'cpu_number'=>self::number($row->cpu_number??null),'ram_mib'=>self::capacity($row->ram_mib??null),'storage_mib'=>self::capacity($row->storage_mib??null),'host_count'=>self::number($row->host_count??null),'host_limit'=>in_array($row->host_limit??null,[-1,'-1'],true)?'-1':self::number($row->host_limit??null)];
            if($linked)$cs[$cid]['visible_nodes']++;
            $fields=['name'=>$name,'status'=>$status];if($ips!==[])$fields['ip_addresses']=$ips;
            $items[]=['type'=>'hypervisor_node','external_id'=>'node:'.$id,'fields'=>$fields,...($linked?['parent_external_id'=>'cluster:'.$cid]:[])];
        }
        $snapshot=['schema_version'=>1,'complete'=>$full&&$unlinked===0,'visibility'=>$full?'administrator':'restricted','counts'=>['clusters'=>count($cs),'nodes'=>count($ns),'unlinked_nodes'=>$unlinked],'clusters'=>array_values($cs),'nodes'=>array_values($ns)];
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>850000)throw new ConnectorTransportException('connector_protocol_error');
        return [$snapshot,$items];
    }
    private static function name(mixed $v,string $fallback,int $max=255):string
    {return is_string($v)&&trim($v)!==''&&strlen($v)<=$max&&preg_match('//u',$v)&&!preg_match('/[\x00-\x1f\x7f]/',$v)?trim($v):$fallback;}
    private static function number(mixed $v):?string
    {return (is_int($v)&&$v>=0)||(is_string($v)&&preg_match('/^(0|[1-9][0-9]{0,19})$/D',$v))?(string)$v:null;}
    private static function capacity(mixed $v):?array
    {
        if(!$v instanceof \stdClass)return null;
        $total=self::number($v->total??null);$used=self::number($v->used??null);
        return $total===null&&$used===null?null:['total'=>$total,'used'=>$used];
    }
}
