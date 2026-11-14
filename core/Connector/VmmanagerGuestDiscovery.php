<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Project the v3 host list (including related entities); never store raw host configuration. */
final class VmmanagerGuestDiscovery
{
    private static function invalid():never { throw new ConnectorTransportException('connector_protocol_error'); }
    private static function text(mixed $v,int $max=255):?string
    { return is_string($v)&&trim($v)!==''&&strlen($v)<=$max&&preg_match('//u',$v)&&!preg_match('/[\x00-\x1f\x7f]/',$v)?trim($v):null; }
    private static function number(mixed $v):?string
    { return (is_int($v)&&$v>=0)||(is_string($v)&&preg_match('/^(0|[1-9][0-9]{0,19})$/D',$v))?(string)$v:null; }
    private static function ref(mixed $v):?int
    { if($v instanceof \stdClass)$v=$v->id??null; if($v===null)return null; return VmmanagerApiClient::id($v); }
    private static function label(mixed $v):?string
    { return self::text($v instanceof \stdClass?($v->name??null):$v); }
    private static function rows(mixed $v,int $limit):?array
    {
        if($v===null)return null;
        if($v instanceof \stdClass)$v=isset($v->list)?$v->list:[$v];
        if(!is_array($v)||!array_is_list($v)||count($v)>$limit)self::invalid();
        foreach($v as $row)if(!$row instanceof \stdClass)self::invalid();
        return $v;
    }
    private static function addresses(mixed $v):?array
    {
        if($v===null)return null;
        if($v instanceof \stdClass&&isset($v->list))$v=$v->list;
        if(!is_array($v))$v=[$v];
        if(count($v)>256)self::invalid();
        $out=[];
        foreach($v as $r){
            $ip=$r instanceof \stdClass?($r->name??$r->ip??$r->addr??null):$r;
            if(is_string($ip)&&filter_var($ip,FILTER_VALIDATE_IP)!==false)$out[]=inet_ntop(inet_pton($ip));
        }
        $out=array_values(array_unique($out));sort($out,SORT_STRING);return $out;
    }
    private static function disks(mixed $value):?array
    {
        $rows=self::rows($value,256);if($rows===null)return null;$out=[];
        foreach($rows as $r){
            $id=VmmanagerApiClient::id($r->id??null);if(isset($out[$id]))self::invalid();
            $out[$id]=['id'=>$id,'name'=>self::text($r->name??null),'size_mib'=>self::number($r->size_mib??null),'storage_id'=>self::ref($r->storage_id??null)??(is_string($r->storage??null)&&!ctype_digit($r->storage)?null:self::ref($r->storage??null)),'storage_name'=>self::label($r->storage??null),'type'=>self::text($r->type??null,64),'bus'=>self::text($r->bus??null,64),'device'=>self::text($r->target_dev??null,64)];
        }
        ksort($out,SORT_NUMERIC);return array_values($out);
    }
    private static function nics(mixed $value):?array
    {
        $rows=self::rows($value,128);if($rows===null)return null;$out=[];
        foreach($rows as $r){
            $id=VmmanagerApiClient::id($r->id??null);if(isset($out[$id]))self::invalid();
            $mac=$r->mac??null;
            $out[$id]=['id'=>$id,'name'=>self::text($r->name??null,128),'mac'=>is_string($mac)&&preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/iD',$mac)?strtolower($mac):null,'model'=>self::text($r->model??null,64),'bridge'=>self::text($r->bridge??null,128),'ip_addresses'=>self::addresses($r->ip??$r->ips??null)];
        }
        ksort($out,SORT_NUMERIC);return array_values($out);
    }
    /** Only the dedicated collector uses the enriched projection. IDs remain compatible with legacy inventory. */
    public static function build(array $nodes,array $hosts,bool $full):array
    {
        $nodeItems=[];$nodeNames=[];$nodeClusters=[];$guests=[];$unlinked=0;
        foreach($nodes as $n){
            $id=VmmanagerApiClient::id($n->id??null);if(isset($nodeNames[$id]))self::invalid();
            $nodeNames[$id]=self::text($n->name??null)??'Node '.$id;$nodeClusters[$id]=self::ref($n->cluster??null);
            $nodeItems[]=['id'=>$id,'name'=>$nodeNames[$id],'state'=>$n->state??null,'maintenance_mode'=>($n->maintenance_mode??false)===true];
        }
        $items=VmmanagerConnector::observations($nodeItems,[]);
        foreach($hosts as $h){
            $id=VmmanagerApiClient::id($h->id??null);if(isset($guests[$id]))self::invalid();
            $node=self::ref($h->node??null);$cluster=self::ref($h->cluster??null);
            $virtualization=($h->cluster??null) instanceof \stdClass?($h->cluster->virtualization_type??null):null;
            if(!in_array($virtualization,['kvm','lxd'],true))self::invalid();
            $linked=$node!==null&&isset($nodeNames[$node]);
            if(!$linked){$unlinked++;if($full)self::invalid();}
            if($linked&&$cluster!==null&&$nodeClusters[$node]!==null&&$nodeClusters[$node]!==$cluster)self::invalid();
            $name=self::text($h->name??null)??'Host '.$id;
            $state=match($h->state??null){true,'running','online'=>'running',false,'stopped','offline'=>'stopped',default=>'unknown'};
            $os=$h->os??null;$account=$h->account??null;
            $disks=self::disks($h->disks??$h->disk??null);
            $nics=self::nics($h->interfaces??$h->iface??null);
            $ips=self::addresses($h->ip??$h->ips??null);
            if($nics!==null){foreach($nics as $nic)if($nic['ip_addresses']!==null)$ips=array_merge($ips??[],$nic['ip_addresses']);}
            if($ips!==null){$ips=array_values(array_unique($ips));sort($ips,SORT_STRING);}
            $guest=['id'=>$id,'external_id'=>'host:'.$id,'name'=>$name,'virtualization_type'=>$virtualization,'node_id'=>$node,'node_name'=>$linked?$nodeNames[$node]:null,'cluster_id'=>$cluster,'cluster_name'=>self::label($h->cluster??null),'state'=>$state,'cpu_number'=>self::number($h->cpu_number??null),'ram_mib'=>self::number($h->ram_mib??null),'hdd_mib'=>self::number($h->hdd_mib??null),'os_name'=>self::text($h->os_name??null)??(is_string($os)&&!ctype_digit($os)?self::label($os):($os instanceof \stdClass?self::label($os):null)),'os_id'=>self::ref($h->os_id??null)??($os instanceof \stdClass||is_int($os)||(is_string($os)&&ctype_digit($os))?self::ref($os):null),'account_id'=>$account instanceof \stdClass||is_int($account)||(is_string($account)&&ctype_digit($account))?self::ref($account):null,'ip_addresses'=>$ips,'disks'=>$disks,'interfaces'=>$nics,'rescue_mode'=>is_bool($h->rescue_mode??null)?$h->rescue_mode:null,'ha_restore_on_fail'=>is_bool($h->ha_restore_on_fail??null)?$h->ha_restore_on_fail:null];
            $guests[$id]=$guest;
            $fields=['name'=>$name,'status'=>match($state){'running'=>'online','stopped'=>'offline',default=>'unknown'}];
            // Empty or invisible address lists must not erase IPAM/manual observations.
            if($ips!==null&&$ips!==[]&&count($ips)<=64)$fields['ip_addresses']=$ips;
            $osName=strtolower($guest['os_name']??'');
            foreach(['windows'=>'windows','ubuntu'=>'ubuntu','debian'=>'debian','almalinux'=>'linux','rocky'=>'linux','centos'=>'linux','fedora'=>'linux'] as $prefix=>$family)if(str_starts_with($osName,$prefix)){ $fields['os_family']=$family; break; }
            $items[]=['type'=>$virtualization==='kvm'?'virtual_machine':'container','external_id'=>'host:'.$id,'fields'=>$fields,...($node!==null?['parent_external_id'=>'node:'.$node]:[])];
        }
        $snapshot=['schema_version'=>1,'complete'=>$full&&$unlinked===0,'visibility'=>$full?'administrator':'restricted','counts'=>['nodes'=>count($nodes),'guests'=>count($guests),'unlinked_guests'=>$unlinked],'guests'=>array_values($guests)];
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>850000)self::invalid();
        return [$snapshot,$items];
    }
}
