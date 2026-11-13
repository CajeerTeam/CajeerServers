<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Read-only PVE datastore/volume and configured network topology discovery. */
final class ProxmoxStorageNetworkDiscovery
{
    private const MAX_BYTES=800000;
    private const MAX_ROWS=10000;
    public static function deadline():int {return hrtime(true)+60_000_000_000;}
    private static function invalid():never {throw new ConnectorTransportException('connector_protocol_error');}
    private static function token(mixed $v,int $max=64):string
    {
        if(!is_string($v)||strlen($v)>$max||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.+:-]*$/D',$v)||str_contains($v,'..'))self::invalid();
        return $v;
    }
    private static function number(mixed $v):string
    {
        if(is_int($v)&&$v>=0)return (string)$v;
        if(is_string($v)&&preg_match('/^(0|[1-9][0-9]{0,19})$/D',$v))return $v;
        self::invalid();
    }
    private static function flag(mixed $v):bool
    {if(!in_array($v,[0,1,'0','1',false,true],true))self::invalid();return in_array($v,[1,'1',true],true);}
    private static function volumeId(mixed $v,string $storage):string
    {
        if(!is_string($v)||strlen($v)>256||!preg_match('~^([A-Za-z0-9][A-Za-z0-9_.-]{0,63}):([A-Za-z0-9][A-Za-z0-9_.+/:=@ -]{0,191})$~D',$v,$m)||$m[1]!==$storage||str_contains($m[2],'..'))self::invalid();
        return $v;
    }
    private static function names(mixed $v):array
    {
        if(!is_string($v)||strlen($v)>4096)self::invalid();
        $names=preg_split('/[\s,]+/',trim($v),-1,PREG_SPLIT_NO_EMPTY);if(count($names)>128)self::invalid();
        return array_values(array_unique(array_map(fn($x)=>self::token($x),$names)));
    }
    private static function rows(mixed $data):array
    { $rows=ProxmoxApiClient::rows($data);if(count($rows)>self::MAX_ROWS)self::invalid();return $rows; }
    private static function storage(array $r):array
    {
        $id=self::token($r['storage']??null);if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D',$id))self::invalid();$out=['storage'=>$id,'type'=>self::token($r['type']??null)];
        foreach(['active','enabled','shared','disable'] as $k)if(isset($r[$k]))$out[$k]=self::flag($r[$k]);
        foreach(['total','used','avail'] as $k)if(isset($r[$k]))$out[$k]=self::number($r[$k]);
        if(isset($r['content']))$out['content']=self::names($r['content']);
        if(isset($r['nodes']))$out['nodes']=self::names($r['nodes']);
        return $out;
    }
    private static function volume(array $r,string $storage):array
    {
        $out=['volume_id'=>self::volumeId($r['volid']??null,$storage)];
        foreach(['content','format'] as $k)if(isset($r[$k]))$out[$k]=self::token($r[$k]);
        foreach(['size','used','ctime','vmid','approximate-size'] as $k)if(isset($r[$k]))$out[str_replace('-','_',$k)]=self::number($r[$k]);
        if(!isset($out['content'])&&preg_match('~^[^:]+:(backup|iso|vztmpl|snippets|import)/~D',$out['volume_id'],$m))$out['content']=$m[1];
        if(isset($r['parent']))$out['parent_volume_id']=self::volumeId($r['parent'],$storage);
        if(isset($r['protected']))$out['protected']=self::flag($r['protected']);
        return $out;
    }
    private static function network(array $r):array
    {
        $out=['iface'=>self::token($r['iface']??null),'type'=>self::token($r['type']??null)];
        foreach(['active','exists','autostart','bridge_vlan_aware'] as $k)if(isset($r[$k]))$out[$k]=self::flag($r[$k]);
        foreach(['bridge_ports','slaves'] as $k)if(isset($r[$k]))$out[$k]=$r[$k]==='none'?[]:self::names($r[$k]);
        foreach(['vlan-raw-device'=>'vlan_raw_device','bond-primary'=>'bond_primary','bond_mode'=>'bond_mode','bond_xmit_hash_policy'=>'bond_xmit_hash_policy','ovs_bridge'=>'ovs_bridge','method'=>'method','method6'=>'method6','vxlan-physdev'=>'vxlan_physdev'] as $k=>$name)if(isset($r[$k]))$out[$name]=self::token($r[$k]);
        if(isset($r['bridge_vids'])&&is_string($r['bridge_vids'])&&strlen($r['bridge_vids'])<=1024&&preg_match('/^[0-9 -]+$/D',$r['bridge_vids']))$out['bridge_vids']=$r['bridge_vids'];
        if(isset($r['netmask6'])){$v=self::number($r['netmask6']);if((int)$v>128)self::invalid();$out['netmask6']=(int)$v;}
        foreach(['ovs_ports','ovs_bonds'] as $k)if(isset($r[$k]))$out[$k]=self::names($r[$k]);
        foreach(['vlan-id'=>4094,'ovs_tag'=>4094,'bridge-access'=>4094,'vxlan-id'=>16777215,'mtu'=>65535] as $k=>$max)if(isset($r[$k])){$v=self::number($r[$k]);if((int)$v>$max)self::invalid();$out[str_replace('-','_',$k)]=(int)$v;}
        foreach(['cidr','cidr6','address','address6','gateway','gateway6','netmask'] as $k)if(isset($r[$k])){
            $v=$r[$k];if(!is_string($v)||strlen($v)>80)self::invalid();$p=explode('/',$v);$family=str_ends_with($k,'6')?FILTER_FLAG_IPV6:FILTER_FLAG_IPV4;
            if(count($p)>2||filter_var($p[0],FILTER_VALIDATE_IP,$family)===false||(isset($p[1])&&(!ctype_digit($p[1])||(int)$p[1]>($family===FILTER_FLAG_IPV6?128:32))))continue;
            $out[$k]=$v;
        }
        return $out;
    }
    /** Independent ACL/budget states; transport/trust/auth errors fail the whole attempt. */
    private static function fetch(callable $get,string $path,callable $project,int $deadline,int &$calls,bool &$limited):array
    {
        if($limited)return ['state'=>'rate_limited','items'=>[]];
        if($calls>=96||hrtime(true)>=$deadline)return ['state'=>'budget_exhausted','items'=>[]];
        ++$calls;
        try{return ['state'=>'available','items'=>$project($get($path))];}
        catch(ConnectorTransportException $e){
            if($e->httpStatus===403)return ['state'=>'permission_denied','items'=>[]];
            if($e->httpStatus===404)return ['state'=>'unavailable','items'=>[]];
            if($e->category==='connector_rate_limited'){$limited=true;return ['state'=>'rate_limited','items'=>[]];}
            throw $e;
        }
    }
    private static function listOf(mixed $raw,callable $project,string $key,int $limit):array
    {
        $out=[];foreach(self::rows($raw) as $r){$v=$project($r);$id=$v[$key];if(isset($out[$id]))self::invalid();$out[$id]=$v;}
        ksort($out,SORT_STRING);if(count($out)>$limit)return ['items'=>self::window(array_values($out),$limit,random_int(0,PHP_INT_MAX)),'truncated'=>true];
        return ['items'=>array_values($out),'truncated'=>false];
    }
    private static function unpack(array $result):array
    {
        if($result['state']==='available'){$result['state']=$result['items']['truncated']?'size_limited':'available';$result['items']=$result['items']['items'];}
        return $result;
    }
    /** Rotate work fairly, while keeping the list and explicit state for every visible resource. */
    private static function window(array $keys,int $count,int $epoch):array
    {
        if(count($keys)<=$count)return $keys;$start=$epoch%count($keys);
        return array_slice([...array_slice($keys,$start),...array_slice($keys,0,$start)],0,$count);
    }
    private static function locations(array $rows):array
    {
        $out=[];foreach($rows as $r){$id=self::number($r['vmid']??null);$type=$r['type']??null;if(!in_array($type,['qemu','lxc'],true)||$id==='0'||strlen($id)>9||isset($out[$id]))self::invalid();$out[$id]=[$type,self::token($r['node']??null,120)];}ksort($out,SORT_STRING);return $out;
    }
    public static function collect(callable $get,array $info,int $deadline):ConnectorResult
    {
        $membership=$info['membership']??null;unset($info['membership']);
        $nodes=self::rows($get('/api2/json/nodes'));$guests=self::rows($get('/api2/json/cluster/resources?type=vm'));
        $items=ProxmoxConnector::observations($nodes,$guests);$locations=self::locations($guests);$epoch=random_int(0,PHP_INT_MAX);$calls=0;$limited=false;
        $byNode=[];foreach($nodes as $r){$node=self::token($r['node'],120);if($membership!==null&&!isset($membership['members'][$node]))self::invalid();$byNode[$node]=['node'=>$node,'status'=>in_array($r['status']??null,['online','offline'],true)?$r['status']:'unknown','storage_state'=>'budget_exhausted','network_state'=>'budget_exhausted','storages'=>[],'interfaces'=>[]];}ksort($byNode,SORT_STRING);
        $definitions=self::unpack(self::fetch($get,'/api2/json/storage',fn($r)=>self::listOf($r,self::storage(...),'storage',256),$deadline,$calls,$limited));
        $volumeJobs=[];
        foreach(self::window(array_keys($byNode),12,$epoch) as $node){
            if($byNode[$node]['status']==='offline'){$byNode[$node]['storage_state']=$byNode[$node]['network_state']='offline';continue;}
            $base='/api2/json/nodes/'.$node;
            $s=self::unpack(self::fetch($get,$base.'/storage',fn($r)=>self::listOf($r,self::storage(...),'storage',128),$deadline,$calls,$limited));
            $project=fn($r)=>self::listOf($r,self::network(...),'iface',256);
            // PVE 8 does not support include_sdn. Its any_bridge filter includes visible VNets.
            if(str_starts_with($info['version']??'','8.')){
                $n=self::unpack(self::fetch($get,$base.'/network',$project,$deadline,$calls,$limited));
                $bridges=self::unpack(self::fetch($get,$base.'/network?type=any_bridge',$project,$deadline,$calls,$limited));
                $merged=array_column($n['items'],null,'iface');foreach($bridges['items'] as $iface)$merged[$iface['iface']]??=$iface;
                ksort($merged,SORT_STRING);$n['items']=array_values($merged);
                if($n['state']==='available')$n['state']=$bridges['state'];
            }else $n=self::unpack(self::fetch($get,$base.'/network?type=include_sdn',$project,$deadline,$calls,$limited));
            $byNode[$node]['storage_state']=$s['state'];$byNode[$node]['network_state']=$n['state'];$byNode[$node]['interfaces']=$n['items'];
            $known=array_column($n['items'],null,'iface');foreach($byNode[$node]['interfaces'] as &$iface){$iface['links']=[];foreach(['bridge_ports','slaves','ovs_ports','ovs_bonds','vlan_raw_device','ovs_bridge','vxlan_physdev','bond_primary'] as $kind)foreach((array)($iface[$kind]??[]) as $peer)$iface['links'][]=['kind'=>$kind,'iface'=>$peer,'resolved'=>isset($known[$peer])];}unset($iface);
            foreach($s['items'] as $storage){$id=$storage['storage'];$storage['volume_state']='budget_exhausted';$storage['storage_key']=($storage['shared']??false)?'shared:'.$id:'node:'.$node.':'.$id;$storage['volumes']=[];$byNode[$node]['storages'][$id]=$storage;$volumeJobs[]=[$node,$id];}
        }
        // Query content on each node separately: identical local storage IDs are not shared disks.
        foreach(self::window($volumeJobs,24,$epoch) as [$node,$id]){
            $s=&$byNode[$node]['storages'][$id];
            if(($s['active']??true)===false||($s['enabled']??true)===false){$s['volume_state']='inactive';unset($s);continue;}
            $v=self::unpack(self::fetch($get,'/api2/json/nodes/'.$node.'/storage/'.$id.'/content',fn($r)=>self::listOf($r,fn($row)=>self::volume($row,$id),'volume_id',200),$deadline,$calls,$limited));
            $s['volume_state']=$v['state'];$s['volumes']=$v['items'];unset($s);
        }
        $attachments=[];foreach($locations as $id=>[$type,$node])$attachments[$id]=['guest_id'=>$type.':'.$id,'node'=>$node,'state'=>'budget_exhausted','disks'=>[],'nics'=>[]];
        foreach(self::window(array_keys($attachments),32,$epoch) as $id){[$type,$node]=$locations[$id];
            if($byNode[$node]['status']==='offline'){$attachments[$id]['state']='offline';continue;}
            $r=self::fetch($get,'/api2/json/nodes/'.$node.'/'.$type.'/'.$id.'/config',fn($raw)=>ProxmoxGuestDiscovery::config($raw,$type),$deadline,$calls,$limited);
            $a=&$attachments[$id];$a['state']=$r['state'];
            if(isset($r['items']['identity_fingerprint']))$a['identity_fingerprint']=$r['items']['identity_fingerprint'];
            foreach($r['items']['disks']??[] as $disk){$storage=$disk['storage']??null;$vid=$disk['volume_id']??null;$disk['storage_resolved']=$storage!==null&&isset($byNode[$node]['storages'][$storage]);if($disk['storage_resolved'])$disk['storage_key']=$byNode[$node]['storages'][$storage]['storage_key'];$disk['volume_resolved']=false;
                if($disk['storage_resolved']&&$vid!==null)foreach($byNode[$node]['storages'][$storage]['volumes'] as $v)if($v['volume_id']===$vid){$disk['volume_resolved']=true;break;}$a['disks'][]=$disk;}
            $ifaces=array_column($byNode[$node]['interfaces'],null,'iface');foreach($r['items']['nics']??[] as $nic){$nic['bridge_resolved']=isset($nic['bridge'])&&isset($ifaces[$nic['bridge']]);$a['nics'][]=$nic;}unset($a);
        }
        // Config was fetched on the reported node; refuse a mixed snapshot after a guest move/reuse.
        if(self::locations(self::rows($get('/api2/json/cluster/resources?type=vm')))!==$locations)self::invalid();
        $again=self::rows($get('/api2/json/nodes'));$names=array_map(fn($r)=>self::token($r['node']??null,120),$again);sort($names,SORT_STRING);if($names!==array_keys($byNode))self::invalid();
        foreach($byNode as &$node)$node['storages']=array_values($node['storages']);unset($node);
        $snapshot=['schema_version'=>1,'visibility'=>'reported_only','definitions_state'=>$definitions['state'],'definitions'=>$definitions['items'],'nodes'=>array_values($byNode),'guests'=>array_values($attachments)];
        // Keep the wire/state budget explicit: omit complete sections rather than silently truncating JSON.
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR))>self::MAX_BYTES){
            foreach($snapshot['nodes'] as &$node)foreach($node['storages'] as &$s){if($s['volumes']!==[]){$s['volumes']=[];$s['volume_state']='size_limited';}}unset($s,$node);
            foreach($snapshot['guests'] as &$a)foreach($a['disks'] as &$d)$d['volume_resolved']=false;unset($d,$a);
        }
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR))>self::MAX_BYTES)self::invalid();
        $complete=$snapshot['definitions_state']==='available';foreach($snapshot['nodes'] as $node){$complete=$complete&&$node['storage_state']==='available'&&$node['network_state']==='available';foreach($node['storages'] as $s)$complete=$complete&&$s['volume_state']==='available';}foreach($snapshot['guests'] as $a)$complete=$complete&&$a['state']==='available';
        $snapshot['details_complete']=$complete;$info['storage_network_discovery']=$snapshot;
        return new ConnectorResult($items,false,[],$info);
    }
}
