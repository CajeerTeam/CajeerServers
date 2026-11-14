<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

/** Lossless source-scoped network projection. No identity inference from names or addresses. */
final class NetworkObservation
{
    private array $devices=[], $interfaces=[], $networks=[], $addresses=[], $links=[], $issues=[], $lldp=[], $neighbors=[];
    private string $target;
    private string $provider;
    private string $observed;
    private bool $complete;
    private function id(string $kind,string ...$parts):string {return $kind.':'.hash('sha256',json_encode([$this->target,...$parts],JSON_THROW_ON_ERROR));}
    public static function mac(mixed $v):?string {
        if(!is_string($v))return null;$v=strtolower(str_replace('-',':',$v));
        return preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/D',$v)&&$v!=='00:00:00:00:00:00'?$v:null;
    }
    public static function prefix(string $ip,int $bits):?string {
        $b=@inet_pton($ip);if($b===false||$bits<0||$bits>strlen($b)*8)return null;
        $out='';for($i=0;$i<strlen($b);$i++){$n=max(0,min(8,$bits-$i*8));$out.=chr(ord($b[$i])&($n===0?0:(255<<(8-$n))&255));}
        return inet_ntop($out).'/'.$bits;
    }
    private function device(string $key,string $name):string {
        $id=$this->id('device',$key);$this->devices[$id]=['id'=>$id,'external_id'=>$key,'label'=>$name,'target_id'=>$this->target,'provider'=>$this->provider];return $id;
    }
    private function iface(string $device,string $key,string $name,?string $mac=null,?int $vlan=null):string {
        $id=$this->id('interface',$device,$key);$this->interfaces[$id]=['id'=>$id,'device_id'=>$device,'key'=>$key,'name'=>$name,'mac'=>$mac,'vlan_id'=>$vlan,'target_id'=>$this->target];return $id;
    }
    private function network(string $key,string $cidr,?int $vlan=null,?string $scope=null):string {
        $id=$this->id('network',$key);$this->networks[$id]=['id'=>$id,'cidr'=>$cidr,'vlan_id'=>$vlan,'scope'=>$scope??$this->target,'target_id'=>$this->target];return $id;
    }
    private function address(?string $iface,string $ip,?int $prefix=null,?string $network=null,?string $issue=null,string $key=''):void {
        if(!filter_var($ip,FILTER_VALIDATE_IP))return;$ip=inet_ntop(inet_pton($ip));
        // A prefix observed on two interfaces does not prove a shared L2/VRF segment.
        if($network===null&&$prefix!==null&&($cidr=self::prefix($ip,$prefix))!==null)$network=$this->network(($iface??'unresolved').':'.$cidr.':'.($iface===null?'unknown':($this->interfaces[$iface]['vlan_id']??'unknown')),$cidr,$iface===null?null:$this->interfaces[$iface]['vlan_id'],$this->target.':'.($iface??'unresolved'));
        $id=$this->id('address',$iface??'',$ip,$network??'',$key);
        $this->addresses[$id]=['id'=>$id,'interface_id'=>$iface,'address'=>$ip,'network_id'=>$network,'issue'=>$issue,'target_id'=>$this->target];
    }
    private function link(string $a,string $b,string $kind,string $evidence='configuration'):void {
        if($a===$b||!isset($this->interfaces[$a])||!isset($this->interfaces[$b]))return;
        $id=$this->id('link',$a,$b,$kind);$this->links[$id]=['id'=>$id,'source'=>$a,'target'=>$b,'kind'=>$kind,'evidence'=>$evidence];
    }
    private static function vlan(mixed $v):?int {return (is_int($v)||is_string($v)&&preg_match('/^[0-9]{1,4}$/D',$v))&&(int)$v>=1&&(int)$v<=4094?(int)$v:null;}
    private static function bits(mixed $v):?int {return (is_int($v)||is_string($v)&&ctype_digit($v))&&(int)$v<=128&&(int)$v>=0?(int)$v:null;}
    private function cidrAddress(?string $i,mixed $v,?string $issue=null):void {
        if(!is_string($v))return;$p=explode('/',$v);if(count($p)!==2)return;$bits=self::bits($p[1]);if($bits!==null&&self::prefix($p[0],$bits)!==null)$this->address($i,$p[0],$bits,null,$issue);
    }
    public static function project(array $target,array $snapshot,string $observed):array {
        $s=new self;$s->target=$target['id'];$s->provider=$target['provider'];$s->observed=$observed;$s->complete=($snapshot['complete']??$snapshot['details_complete']??false)===true;
        $p=$s->provider;$sections=$snapshot['sections']??[];$d=$s->device('device',$target['name']);$by=[];
        if(in_array($p,['ssh','winrm','snmp','routeros'],true)){
            foreach($sections['interfaces']??[] as $r){
                $key=(string)match($p){'ssh'=>$r['name'],'winrm'=>$r['index'],'snmp'=>$r['if_index'],'routeros'=>$r['.id']};
                $name=$r['name']??$r['description']??$key;$mac=self::mac($r['mac']??$r['mac-address']??null);
                $by[$key]=$s->iface($d,$key,$name,$mac);
            }
            if($p==='routeros'){
                foreach($sections['vlans']??[] as $r){foreach($s->interfaces as $id=>&$i)if($i['name']===($r['name']??null))$i['vlan_id']=self::vlan($r['vlan-id']??null);unset($i);}
                foreach(array_merge($sections['ipv4']??[],$sections['ipv6']??[]) as $r){$i=$by[$r['interface-id']??'']??null;$s->cidrAddress($i,$r['address']??null,($r['disabled']??null)==='true'||($r['invalid']??null)==='true'?'inactive_address':($i===null?'unresolved_interface':null));}
                foreach($sections['bridge_ports']??[] as $r){$a=$by[$r['interface-id']??'']??null;$b=$by[$r['bridge-id']??'']??null;if($a&&$b)$s->link($a,$b,'bridge_member');}
                foreach($sections['vlans']??[] as $r){$a=null;foreach($s->interfaces as $id=>$i)if($i['name']===($r['name']??null))$a=$id;$b=$by[$r['interface-id']??'']??null;if($a&&$b)$s->link($a,$b,'vlan_parent');}
                foreach($sections['neighbors']??[] as $r)$s->neighbors[]=['interface_id'=>$by[$r['interface-id']??'']??null,'name'=>$r['identity']??null,'mac'=>self::mac($r['mac-address']??null),'address'=>$r['address']??null,'evidence'=>'routeros_neighbor'];
                foreach($sections['bridge_vlans']??[] as $r)$s->issues[]=['code'=>'bridge_vlan_membership','vlan_id'=>$r['vlan-ids']??null,'bridge'=>$r['bridge']??null,'tagged'=>$r['tagged']??null,'untagged'=>$r['untagged']??null];
            } else {
                foreach($sections['addresses']??[] as $r){$key=(string)($r['interface']??$r['index']??$r['if_index']??'');$i=$by[$key]??null;$issue=$i===null?'unresolved_interface':null;if($p==='winrm'&&isset($r['state'])&&!in_array(strtolower($r['state']),['preferred'],true))$issue='inactive_address';$s->address($i,$r['address'],self::bits($r['prefix']??null),null,$issue);}
                if($p==='ssh')foreach($sections['interfaces']??[] as $r){$a=$by[$r['name']]??null;$b=$by[$r['master']??'']??null;if($a&&$b)$s->link($a,$b,'master_member');}
                if($p==='snmp'){
                    foreach($sections['lldp_local']??[] as $l){if(($l['chassis_id']??null)!==null&&($l['chassis_subtype']??null)!==null)$s->lldp[]=['chassis_id'=>$l['chassis_id'],'chassis_subtype'=>(string)$l['chassis_subtype'],'ports'=>array_values(array_filter(array_map(fn($r)=>['interface_id'=>$by[$r['if_index']??'']??null,'port_id'=>$r['port_id'],'port_subtype'=>(string)$r['port_subtype']],$sections['lldp_ports']??[]),fn($r)=>$r['interface_id']!==null&&$r['port_id']!==null&&$r['port_subtype']!==''))];}
                    foreach($sections['neighbors']??[] as $r)$s->neighbors[]=['interface_id'=>$by[$r['if_index']??'']??null,'chassis_id'=>$r['chassis_id'],'chassis_subtype'=>(string)$r['chassis_subtype'],'port_id'=>$r['port_id'],'port_subtype'=>(string)$r['port_subtype'],'name'=>$r['system_name'],'evidence'=>'lldp'];
                    foreach($sections['vlan_membership']??[] as $r)if(($r['egress']??null)==='yes'&&isset($by[$r['if_index']??'']))$s->issues[]=['code'=>'vlan_membership','interface_id'=>$by[$r['if_index']],'vlan_id'=>$r['vlan_id'],'tagged'=>$r['tagged']];
                }
            }
        } elseif($p==='proxmox'){
            unset($s->devices[$d]);$nodes=[];
            foreach($snapshot['nodes'] as $n){$d=$s->device('node:'.$n['node'],$n['node']);$by=[];foreach($n['interfaces'] as $r){$by[$r['iface']]=$s->iface($d,$r['iface'],$r['iface'],null,self::vlan($r['vlan_id']??null));foreach(['cidr','cidr6'] as $k)$s->cidrAddress($by[$r['iface']],$r[$k]??null);}
                foreach($n['interfaces'] as $r)foreach($r['links']??[] as $l)if(isset($by[$l['iface']]))$s->link($by[$r['iface']],$by[$l['iface']],$l['kind']);$nodes[$n['node']]=$by;}
            foreach($snapshot['guests'] as $g){$d=$s->device($g['guest_id'],$g['guest_id']);foreach($g['nics'] as $r){$i=$s->iface($d,$r['device'],$r['name']??$r['device'],self::mac($r['mac']??null),self::vlan($r['tag']??null));$bridge=$nodes[$g['node']][$r['bridge']??'']??null;if($bridge)$s->link($i,$bridge,'guest_bridge');foreach(['ip','ip6'] as $k)$s->cidrAddress($i,$r[$k]??null);}}
        } elseif($p==='vmmanager'){
            unset($s->devices[$d]);$nets=[];foreach($snapshot['networks'] as $r)$nets[$r['id']]=$s->network((string)$r['id'],$r['cidr'],$r['vlan_id'],$s->target.':userspace:'.($r['userspace']??'unknown'));
            foreach($snapshot['interfaces'] as $r){$d=$s->device($r['external_id'],$r['external_id']);$by[$r['key']]=$s->iface($d,$r['key'],$r['name']??$r['key'],self::mac($r['mac']));}
            foreach($snapshot['addresses'] as $r)if($r['address']!==null)$s->address($by[$r['nic_key']??'']??null,$r['address'],null,$nets[$r['network_id']??'']??null,$r['issue'],(string)$r['id']);
        }
        return ['target_id'=>$s->target,'provider'=>$p,'observed_at'=>$observed,'complete'=>$s->complete,'devices'=>array_values($s->devices),'interfaces'=>array_values($s->interfaces),'networks'=>array_values($s->networks),'addresses'=>array_values($s->addresses),'links'=>array_values($s->links),'neighbors'=>$s->neighbors,'lldp'=>$s->lldp,'issues'=>$s->issues];
    }
    /** Diagnostics only. Exact duplicates and nearest containing prefixes are kept separate by source. */
    public static function conflicts(array $sources):array {
        $macs=[];$ips=[];$cidrs=[];$prefixes=[];
        foreach($sources as $s){foreach($s['interfaces'] as $i)if($i['mac']!==null)$macs[$i['mac']][]=$i['id'];foreach($s['addresses'] as $a)$ips[$a['address']][]=$a['id'];foreach($s['networks'] as $n){$cidrs[$n['cidr']][]=$n['id'];$prefixes[$n['cidr']]=$n['cidr'];}}
        $out=[];$truncated=false;
        $add=static function(string $kind,string $value,array $ids,int $count,string $code)use(&$out,&$truncated):void{
            if(count($out)>=500){$truncated=true;return;}
            $out[]=['kind'=>$kind,'value'=>$value,'observations'=>array_slice($ids,0,50),'observation_count'=>$count,'code'=>$code];
        };
        foreach(['mac'=>$macs,'address'=>$ips,'cidr'=>$cidrs] as $kind=>$values)foreach($values as $value=>$ids)if(count($ids)>1)$add($kind,$value,$ids,count($ids),'multiple_observations');
        // Sort canonical binary prefixes; stack retains only ancestors. O(N log N), no pairwise scan.
        $rows=[];foreach($prefixes as $cidr){[$ip,$bits]=explode('/',$cidr,2);$bin=inet_pton($ip);$rows[]=['cidr'=>$cidr,'ip'=>$ip,'bin'=>$bin,'bits'=>(int)$bits];}
        usort($rows,static fn($a,$b)=>strlen($a['bin'])<=>strlen($b['bin'])?:strcmp($a['bin'],$b['bin'])?:$a['bits']<=>$b['bits']);
        $stack=[];foreach($rows as $r){while($stack!==[]&&!Network::contains(end($stack)['cidr'],$r['ip']))array_pop($stack);
            if($stack!==[]){$parent=end($stack);$left=$cidrs[$parent['cidr']];$right=$cidrs[$r['cidr']];$add('cidr',$parent['cidr'].' ⊃ '.$r['cidr'],array_merge(array_slice($left,0,25),array_slice($right,0,25)),count($left)+count($right),'overlapping_prefix');}
            $stack[]=$r;
        }
        return ['items'=>$out,'truncated'=>$truncated];
    }
    /** Only reciprocal, unique LLDP chassis/port pairs are promoted to physical links. */
    public static function physicalLinks(array $sources):array {
        $chassis=[];$ports=[];$claims=[];$out=[];
        foreach($sources as $s)foreach($s['lldp'] as $l){$key=json_encode([$l['chassis_subtype'],$l['chassis_id']],JSON_THROW_ON_ERROR);$chassis[$key]=($chassis[$key]??0)+1;
            foreach($l['ports'] as $p)$ports[$key][json_encode([$p['port_subtype'],$p['port_id']],JSON_THROW_ON_ERROR)][]=$p['interface_id'];}
        foreach($sources as $s)foreach($s['neighbors'] as $n){if($n['evidence']!=='lldp'||$n['interface_id']===null||$n['chassis_id']===null||$n['port_id']===null)continue;
            $key=json_encode([$n['chassis_subtype'],$n['chassis_id']],JSON_THROW_ON_ERROR);if(($chassis[$key]??0)!==1)continue;
            $matches=$ports[$key][json_encode([$n['port_subtype'],$n['port_id']],JSON_THROW_ON_ERROR)]??[];if(count($matches)!==1)continue;$claims[$n['interface_id']][$matches[0]]=true;}
        foreach($claims as $a=>$peers){if(count($peers)!==1)continue;$b=array_key_first($peers);if($a===$b||strcmp($a,$b)>=0||count($claims[$b]??[])!==1||!isset($claims[$b][$a]))continue;$out[]=['id'=>'lldp:'.hash('sha256',$a.'|'.$b),'source'=>$a,'target'=>$b,'kind'=>'physical_link','evidence'=>'reciprocal_lldp'];}
        return $out;
    }
}
