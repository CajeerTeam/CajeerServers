<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Read-only guest inventory. Remote configuration is projected, never persisted verbatim. */
final class ProxmoxGuestDiscovery
{
    private const MAX_DETAILS=48;
    private const MAX_CONFIG_BYTES=4096;
    public static function deadline():int{return hrtime(true)+60_000_000_000;}
    private static function invalid():never{throw new ConnectorTransportException('connector_protocol_error');}
    private static function integer(mixed $v,int $max=PHP_INT_MAX):int
    {
        if(is_string($v)&&preg_match('/^(0|[1-9][0-9]{0,18})$/D',$v)&&strlen($v)<=strlen((string)$max)&&(strlen($v)<strlen((string)$max)||strcmp($v,(string)$max)<=0))$v=(int)$v;
        if(!is_int($v)||$v<0||$v>$max)self::invalid();return $v;
    }
    private static function flag(mixed $v):bool {if(!in_array($v,[0,1,'0','1',false,true],true))self::invalid();return in_array($v,[1,'1',true],true);}
    private static function token(mixed $v,int $max=128):?string
    {return is_string($v)&&strlen($v)<=$max&&preg_match('/^[A-Za-z0-9][A-Za-z0-9._+:-]*$/D',$v)?$v:null;}
    private static function text(mixed $v,int $max=128):?string
    {return is_string($v)&&strlen($v)<=$max&&preg_match('//u',$v)&&!preg_match('/[\x00-\x1f\x7f]/',$v)?$v:null;}
    /** PVE property strings: only selected keys are read; reject duplicate keys and ambiguous entries. */
    private static function properties(string $v):array
    {
        if(strlen($v)>4096||!preg_match('//u',$v)||preg_match('/[\x00-\x1f\x7f]/',$v))self::invalid();
        $out=[];foreach(explode(',',$v) as $i=>$part){
            if($part==='')self::invalid();
            if(!str_contains($part,'=')){if($i!==0)self::invalid();$key='';$val=$part;}
            else {[$key,$val]=explode('=',$part,2);if(!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D',$key))self::invalid();}
            if(array_key_exists($key,$out))self::invalid();$out[$key]=$val;
        }return $out;
    }
    private static function disk(string $key,string $value):array
    {
        $p=self::properties($value);$file=$p['file']??$p['volume']??$p['']??'';
        $d=['device'=>$key];
        // Host paths, bind mounts, raw block devices and arbitrary options are never exposed.
        if(preg_match('~^([A-Za-z0-9][A-Za-z0-9_.-]{0,63}):([A-Za-z0-9][A-Za-z0-9_.+/-]{0,191})$~D',$file,$m)&&!str_contains($m[2],'..')){$d['storage']=$m[1];$d['volume_id']=$file;}
        elseif($file==='none'||$file==='cdrom')$d['media']=$file;
        else $d['source']='host_or_unresolved';
        if(isset($p['size'])&&preg_match('/^[0-9]+(?:\.[0-9]+)?[KMGTPE]?$/D',$p['size']))$d['size']=$p['size'];
        if(isset($p['media'])&&in_array($p['media'],['disk','cdrom'],true))$d['media']=$p['media'];
        foreach(['backup','readonly','ssd','discard'] as $f)if(isset($p[$f])&&in_array($p[$f],['0','1','on','ignore'],true))$d[$f]=$p[$f];
        return $d;
    }
    private static function nic(string $key,string $value):array
    {
        $p=self::properties($value);$n=['device'=>$key];
        foreach(['virtio','e1000','e1000e','rtl8139','vmxnet3','ne2k_pci','pcnet','i82551','i82557b','i82559er'] as $model)if(isset($p[$model])){$n['model']=$model;$p['macaddr']=$p[$model];break;}
        if(isset($p['model'])&&($m=self::token($p['model']))!==null)$n['model']=$m;
        foreach(['macaddr','hwaddr'] as $k)if(isset($p[$k])&&preg_match('/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/D',$p[$k]))$n['mac']=strtolower($p[$k]);
        foreach(['bridge','name','type'] as $k)if(isset($p[$k])&&($v=self::token($p[$k],64))!==null)$n[$k]=$v;
        foreach(['tag'=>4094,'mtu'=>65535] as $k=>$max)if(isset($p[$k]))$n[$k]=self::integer($p[$k],$max);
        foreach(['firewall','link_down'] as $k)if(isset($p[$k]))$n[$k]=self::flag($p[$k]);
        if(isset($p['trunks'])&&preg_match('/^[0-9]{1,4}(?:;[0-9]{1,4}){0,63}$/D',$p['trunks']))$n['trunks']=array_map(fn($v)=>self::integer($v,4094),explode(';',$p['trunks']));
        foreach(['ip','ip6'] as $k)if(isset($p[$k])){
            $v=$p[$k];if(in_array($v,['dhcp','auto','manual'],true))$n[$k]=$v;
            elseif(preg_match('~^([^/]+)/([0-9]{1,3})$~D',$v,$m)&&filter_var($m[1],FILTER_VALIDATE_IP,$k==='ip'?FILTER_FLAG_IPV4:FILTER_FLAG_IPV6)!==false&&(int)$m[2]<=($k==='ip'?32:128))$n[$k]=$v;
        }
        return $n;
    }
    public static function config(mixed $raw,string $type):array
    {
        if(!$raw instanceof \stdClass||!in_array($type,['qemu','lxc'],true))self::invalid();$r=(array)$raw;$out=[];
        foreach(['cores','sockets','vcpus','cpulimit','cpuunits','memory','balloon','swap'] as $k){
            if(!isset($r[$k]))continue;
            // cpulimit can be fractional; the other fields are integral.
            if($k==='cpulimit'){if(!is_numeric($r[$k])||!is_finite((float)$r[$k])||(float)$r[$k]<0)self::invalid();$out[$k]=(float)$r[$k];}
            else $out[$k]=self::integer($r[$k],1073741824);
        }
        foreach(['ostype','bios','scsihw','arch','lock'] as $k)if(isset($r[$k])&&($v=self::token($r[$k]))!==null)$out[$k]=$v;
        foreach(['template','unprivileged','onboot'] as $k)if(isset($r[$k]))$out[$k]=self::flag($r[$k]);
        if(isset($r['cpu'])&&is_string($r['cpu'])){$p=self::properties($r['cpu']);$v=self::token($p['cputype']??$p['']??null);if($v!==null)$out['cpu_model']=$v;}
        if(isset($r['machine'])&&is_string($r['machine'])){$p=self::properties($r['machine']);$v=self::token($p['type']??$p['']??null);if($v!==null)$out['machine']=$v;}
        if($type==='lxc'&&isset($r['features'])&&is_string($r['features'])){$p=self::properties($r['features']);foreach(['nesting','keyctl','fuse','mknod'] as $k)if(isset($p[$k]))$out['features'][$k]=self::flag($p[$k]);}
        if(isset($r['boot'])&&is_string($r['boot'])&&preg_match('/^order=([A-Za-z0-9]+(?:;[A-Za-z0-9]+){0,31})$/D',$r['boot'],$m))$out['boot_order']=explode(';',$m[1]);
        if(isset($r['tags'])&&is_string($r['tags'])&&strlen($r['tags'])<=512)$out['tags']=array_values(array_filter(explode(';',$r['tags']),fn($v)=>self::token($v,64)!==null));
        // SMBIOS UUID is a useful but not immutable generation signal. Never store other SMBIOS values.
        if($type==='qemu'&&isset($r['smbios1'])&&is_string($r['smbios1'])){
            $p=self::properties($r['smbios1']);$uuid=$p['uuid']??null;
            if(is_string($uuid)&&preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD',$uuid))$out['identity_fingerprint']=hash('sha256',strtolower($uuid));
        }
        $out['disks']=[];$out['nics']=[];
        foreach($r as $key=>$value){
            if(preg_match($type==='qemu'?'/^(?:(?:ide|sata|scsi|virtio|unused)[0-9]{1,3}|efidisk0|tpmstate0)$/D':'/^(?:rootfs|mp[0-9]{1,3}|unused[0-9]{1,3})$/D',$key)){
                if(!is_string($value)||count($out['disks'])>=64)self::invalid();$out['disks'][]=self::disk($key,$value);
            }elseif(preg_match('/^net[0-9]{1,3}$/D',$key)){
                if(!is_string($value)||count($out['nics'])>=32)self::invalid();$out['nics'][]=self::nic($key,$value);
            }
        }
        foreach(['disks','nics'] as $k)usort($out[$k],fn($a,$b)=>strnatcmp($a['device'],$b['device']));
        return $out;
    }
    public static function status(mixed $raw,int $id):array
    {
        if(!$raw instanceof \stdClass)self::invalid();$r=(array)$raw;$out=[];
        if(isset($r['vmid'])&&self::integer($r['vmid'],999999999)!==$id)self::invalid();
        if(isset($r['status'])){if(!is_string($r['status']))self::invalid();$out['status']=in_array($r['status'],['running','stopped','paused','suspended'],true)?$r['status']:'unknown';}
        foreach(['uptime','mem','maxmem','disk','maxdisk','swap','maxswap','netin','netout','diskread','diskwrite'] as $k)if(isset($r[$k]))$out[$k]=(string)self::integer($r[$k]);
        if(isset($r['cpus']))$out['cpus']=self::integer($r['cpus'],65536);
        if(isset($r['cpu'])){if(!is_numeric($r['cpu'])||!is_finite((float)$r['cpu'])||(float)$r['cpu']<0)self::invalid();$out['cpu']=(float)$r['cpu'];}
        if(isset($r['lock'])&&($v=self::token($r['lock']))!==null)$out['lock']=$v;
        return $out;
    }
    private static function fetch(callable $get,string $path,callable $project,int $deadline,bool &$limited):array
    {
        if($limited)return ['rate_limited',null];if(hrtime(true)>=$deadline)return ['budget_exhausted',null];
        try{$data=$project($get($path));if(strlen(json_encode($data,JSON_THROW_ON_ERROR))>self::MAX_CONFIG_BYTES)return ['size_limited',null];return $data===[]?['no_data',null]:['available',$data];}
        catch(ConnectorTransportException $e){if($e->httpStatus===403)return ['permission_denied',null];if($e->httpStatus===404||$e->category==='connector_unavailable')return ['unavailable',null];if($e->category==='connector_rate_limited'){$limited=true;return ['rate_limited',null];}throw $e;}
    }
    public static function collect(callable $get,array $info,int $deadline):ConnectorResult
    {
        $nodes=ProxmoxApiClient::rows($get('/api2/json/nodes'));$membership=$info['membership']??null;unset($info['membership']);
        if($membership!==null)foreach($nodes as $node)if(!is_string($node['node']??null)||!isset($membership['members'][$node['node']]))self::invalid();
        $rows=ProxmoxApiClient::rows($get('/api2/json/cluster/resources?type=vm'));
        $items=ProxmoxConnector::observations($nodes,$rows);$byId=[];foreach($items as $item)$byId[$item['external_id']]=$item;
        $guests=[];$ids=[];
        foreach($rows as $r){$id=self::integer($r['vmid'],999999999);$type=$r['type'];$key=$type.':'.$id;if(isset($ids[$id]))self::invalid();$ids[$id]=true;
            $g=['external_id'=>$key,'vmid'=>$id,'type'=>$type,'node'=>$r['node'],'name'=>$byId[$key]['fields']['name'],'status'=>$byId[$key]['fields']['status'],'config_state'=>'budget_exhausted','runtime_state'=>'budget_exhausted','config'=>null,'runtime'=>null];
            if(isset($r['pool'])&&($v=self::token($r['pool'],64))!==null)$g['pool']=$v;
            if(isset($r['template']))$g['template']=self::flag($r['template']);
            $guests[$key]=$g;
        }
        ksort($guests,SORT_STRING);$keys=array_keys($guests);$count=count($keys);$limited=false;
        // Rotate bounded detail windows. The entire list is retained, even if details are not requested.
        if($count>self::MAX_DETAILS){$start=(int)(floor(time()/60)%$count);$keys=[...array_slice($keys,$start),...array_slice($keys,0,$start)];}
        foreach(array_slice($keys,0,self::MAX_DETAILS) as $key){$g=&$guests[$key];$path='/api2/json/nodes/'.$g['node'].'/'.$g['type'].'/'.$g['vmid'];
            [$g['config_state'],$g['config']]=self::fetch($get,$path.'/config',fn($raw)=>self::config($raw,$g['type']),$deadline,$limited);
            [$g['runtime_state'],$g['runtime']]=self::fetch($get,$path.'/status/current',fn($raw)=>self::status($raw,$g['vmid']),$deadline,$limited);unset($g);
        }
        // A list recheck prevents associating details with a guest that migrated or changed type during this attempt.
        $again=ProxmoxApiClient::rows($get('/api2/json/cluster/resources?type=vm'));$locations=[];
        foreach($again as $r){$id=self::integer($r['vmid']??null,999999999);$type=$r['type']??null;if(!in_array($type,['qemu','lxc'],true)||!is_string($r['node']??null)||isset($locations[$id]))self::invalid();$locations[$id]=[$type,$r['node']];}
        foreach($guests as $g)if(($locations[$g['vmid']]??null)!==[$g['type'],$g['node']])self::invalid();
        if(count($locations)!==$count)self::invalid();
        $snapshot=['schema_version'=>1,'visibility'=>'reported_only','guest_count'=>$count,'details_complete'=>!array_filter($guests,fn($g)=>$g['config_state']!=='available'||$g['runtime_state']!=='available'),'guests'=>array_values($guests)];
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR))>850000)self::invalid();
        $info['guest_discovery']=$snapshot;return new ConnectorResult($items,false,[],$info);
    }
}
