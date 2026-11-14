<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Inventory\Network;

/** Explicit projection of IPmanager v3 lists and host interface relationships. */
final class VmmanagerNetworkDiscovery
{
    private static function ref(mixed $v):?int { if($v instanceof \stdClass)$v=$v->id??null; return $v===null||$v===0||$v==='0'?null:VmmanagerApiClient::id($v); }
    private static function userspace(mixed $v):?int {return $v===0||$v==='0'?0:self::ref($v);}
    private static function text(mixed $v):?string {return is_string($v)&&strlen($v)<=120&&trim($v)!==''&&preg_match('//u',$v)&&!preg_match('/[\x00-\x1f\x7f]/',$v)?trim($v):null;}
    private static function ip(mixed $v):?string {return is_string($v)&&filter_var($v,FILTER_VALIDATE_IP)!==false?inet_ntop(inet_pton($v)):null;}
    private static function number(mixed $v):?string {return (is_int($v)&&$v>=0)||(is_string($v)&&preg_match('/^(0|[1-9][0-9]{0,38})$/D',$v))?(string)$v:null;}
    private static function free(?string $total,?string $used):?string
    {
        if($total===null||$used===null||strlen($used)>strlen($total)||(strlen($used)===strlen($total)&&strcmp($used,$total)>0))return null;
        $used=str_pad($used,strlen($total),'0',STR_PAD_LEFT);$borrow=0;$out='';
        for($i=strlen($total)-1;$i>=0;$i--){$digit=(int)$total[$i]-(int)$used[$i]-$borrow;$borrow=$digit<0?1:0;$out=($digit+10*$borrow).$out;}
        return ltrim($out,'0')?:'0';
    }
    private static function cidr(mixed $v):?string {try{return Network::cidr($v);}catch(\Cajeer\Servers\Http\Exception\HttpException){return null;}}
    public static function build(array $networks,array $pools,array $addresses,array $guests,bool $full):array
    {
        $nets=[];$poolRows=[];$ips=[];$nics=[];$claims=[];$hostClaims=[];$issues=[];
        foreach($networks as $r){
            $id=VmmanagerApiClient::id($r->id??null);$cidr=self::cidr($r->name??null);
            if($cidr===null)throw new ConnectorTransportException('connector_protocol_error');
            $vlan=$r->vlan_id??null;$vlan=is_int($vlan)&&$vlan>=1&&$vlan<=4094?$vlan:null;
            $nets[$id]=['id'=>$id,'cidr'=>$cidr,'gateway'=>self::ip($r->gateway??null),'vlan_id'=>$vlan,'userspace'=>self::userspace($r->userspace??null),'parent_id'=>self::ref($r->parent??null),'network_type'=>in_array($r->network_type??null,['active','container'],true)?$r->network_type:'unknown','total_ip'=>self::number($r->total_ip??null),'using_ip'=>self::number($r->using_ip??null)];
        }
        foreach($pools as $r){$id=VmmanagerApiClient::id($r->id??null);$refs=[];$raw=$r->ipnets??null;
            if($raw!==null){if(!is_array($raw)||count($raw)>2000)throw new ConnectorTransportException('connector_protocol_error');foreach($raw as $n){$ref=self::ref($n);if($ref===null)throw new ConnectorTransportException('connector_protocol_error');$refs[]=$ref;}}
            $poolRows[$id]=['id'=>$id,'name'=>self::text($r->name??null)??'Pool '.$id,'userspace'=>self::userspace($r->userspace??null),'network_ids'=>$raw===null?null:array_values(array_unique($refs)),'total_ip'=>self::number($r->total_ip??null),'using_ip'=>self::number($r->using_ip??null)];
        }
        foreach($guests as $g){foreach($g['ip_addresses']??[] as $ip)$hostClaims[$ip][$g['id']]=true;foreach($g['interfaces']??[] as $nic){
            $key=$g['external_id'].':nic:'.$nic['id'];$nics[$key]=['key'=>$key,'host_id'=>$g['id'],'external_id'=>$g['external_id'],'name'=>$nic['name'],'mac'=>$nic['mac'],'bridge'=>$nic['bridge'],'ip_addresses'=>$nic['ip_addresses']];
            foreach($nic['ip_addresses']??[] as $ip)$claims[$ip][]=$key;
        }}
        $macs=[];foreach($nics as $key=>$n)if($n['mac']!==null)$macs[$n['mac']][]=$key;
        foreach($nics as &$n)$n['issue']=$n['mac']!==null&&count($macs[$n['mac']])>1?'duplicate_mac':null;unset($n);
        foreach($nets as &$n)$n['free_ip']=self::free($n['total_ip'],$n['using_ip']);unset($n);
        foreach($poolRows as &$p)$p['free_ip']=self::free($p['total_ip'],$p['using_ip']);unset($p);
        $duplicates=[];
        foreach($addresses as $r){$id=VmmanagerApiClient::id($r->id??null);$name=$r->name??null;$ip=self::ip($name);$prefix=$ip===null?self::cidr($name):null;
            if($ip===null&&$prefix===null)throw new ConnectorTransportException('connector_protocol_error');
            $net=self::ref($r->ipnet??null);$status=in_array($r->status??null,['busy','special','locked','problem','service','extended','dnsbl','deleted','check'],true)?$r->status:'unknown';
            $keys=$ip===null?[]:($claims[$ip]??[]);$nic=count($keys)===1?$keys[0]:null;
            $issue=null;if($prefix!==null)$issue='prefix_allocation';elseif($net===null||!isset($nets[$net])||!Network::contains($nets[$net]['cidr'],$ip))$issue='network_unavailable';elseif(count($keys)>1||($nic!==null&&count($hostClaims[$ip]??[])>1))$issue='ambiguous_nic';elseif($nic!==null&&$nics[$nic]['issue']!==null)$issue=$nics[$nic]['issue'];elseif($net!==null&&isset($nets[$net])&&self::userspace($r->userspace??null)!==null&&$nets[$net]['userspace']!==null&&self::userspace($r->userspace??null)!==$nets[$net]['userspace'])$issue='userspace_mismatch';
            $ips[$id]=['id'=>$id,'address'=>$ip,'prefix'=>$prefix,'network_id'=>$net,'pool_id'=>self::ref($r->ippool??null),'userspace'=>self::userspace($r->userspace??null),'status'=>$status,'locked'=>is_bool($r->locked??null)?$r->locked:null,'nic_key'=>$nic,'issue'=>$issue];
            if($ip!==null&&$status!=='deleted')$duplicates[$ip][]=$id;
        }
        foreach($duplicates as $ids)if(count($ids)>1)foreach($ids as $id)$ips[$id]['issue']='duplicate_address';
        // IPmanager can have overlapping userspaces and hierarchical container networks; local IPAM cannot.
        $active=array_filter($nets,static fn($n)=>$n['network_type']!=='container');
        foreach($active as $id=>$n){foreach($active as $other=>$m){if($other<=$id)continue;if(Network::overlaps($n['cidr'],$m['cidr'])){$issues[$id]='overlapping_network';$issues[$other]='overlapping_network';}}}
        foreach($nets as $id=>&$n)$n['issue']=$issues[$id]??null;unset($n);
        $snapshot=['schema_version'=>1,'complete'=>$full,'visibility'=>$full?'administrator':'restricted','counts'=>['networks'=>count($nets),'pools'=>count($poolRows),'addresses'=>count($ips),'interfaces'=>count($nics)],'networks'=>array_values($nets),'pools'=>array_values($poolRows),'addresses'=>array_values($ips),'interfaces'=>array_values($nics)];
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>750000)throw new ConnectorTransportException('connector_protocol_error');
        return $snapshot;
    }
}
