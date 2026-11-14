<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Standard MIB discovery. Only fixed numeric OIDs; no SET, raw response or vendor commands. */
final class SnmpDiscovery
{
    public const SCOPES=['device','interfaces','topology'];
    private const IF='.1.3.6.1.2.1.2.2.1.';
    private const IFX='.1.3.6.1.2.1.31.1.1.1.';
    private const ENTITY='.1.3.6.1.2.1.47.1.1.1.1.';
    private const BRIDGE='.1.3.6.1.2.1.17.1.4.1.2';
    private const VLAN='.1.3.6.1.2.1.17.7.1.4.';
    private const LLDP='.1.0.8802.1.1.2.1.';
    private array $tables=[];
    private int $rows=0;
    /** @param \Closure(string,int):array $walk */
    public function __construct(private readonly \Closure $walk,private readonly \Closure $walkColumns){}
    private static function bad():never {throw new ConnectorTransportException('connector_protocol_error');}
    private function column(string $root,string $name,int $limit=2048):array
    {
        $rows=($this->walk)($root,$limit);
        $this->rows+=count($rows);if($this->rows>24000)self::bad();
        $this->tables[$name]=$rows===[]?'not_visible_or_empty':'observed';
        return $rows;
    }
    private static function number(mixed $v,int $max=2147483647,bool $zero=true):string
    {
        if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$v)||(float)$v>$max||(!$zero&&$v==='0'))self::bad();return $v;
    }
    private static function text(string $v):string
    {
        if(strlen($v)>512)self::bad();
        if(!preg_match('//u',$v)||preg_match('/[\x00-\x1f\x7f]/',$v))return 'hex:'.bin2hex($v);
        return $v;
    }
    private static function mac(string $v):?string
    {
        if($v==='')return null;
        if(strlen($v)!==6)return 'hex:'.bin2hex($v); // other media addresses are not Ethernet MACs
        return implode(':',str_split(bin2hex($v),2));
    }
    private static function id(string|int $key):string{return self::number((string)$key,2147483647,false);}
    private static function vlanIndex(string|int $v):string {$v=self::id($v);if($v==='4095')self::bad();return $v;}
    /** Join columns by exact OID suffix, never row position. */
    private function table(string $prefix,array $fields,string $label,callable $index):array
    {
        $rows=[];$roots=[];foreach($fields as $col=>$name)$roots[]=$prefix.$col;
        $columns=($this->walkColumns)($roots,2048);
        foreach($fields as $col=>$name){
            $values=$columns[$prefix.$col];$this->rows+=count($values);if($this->rows>24000)self::bad();
            $this->tables[$label.'.'.$name]=$values===[]?'not_visible_or_empty':'observed';
            foreach($values as $key=>$v){
                $key=(string)$key;$index($key);$rows[$key][$name]=$v;
            }
        }
        return $rows;
    }
    private static function val(array $row,string $field,?callable $convert=null):?string
    {return array_key_exists($field,$row)?($convert??self::text(...))($row[$field]):null;}
    private function interfaces(object $session):array
    {
        $expected=self::number($session->get('.1.3.6.1.2.1.2.1.0'),2048);
        $base=$this->table(self::IF,[2=>'description',3=>'type',4=>'mtu',5=>'speed',6=>'address',7=>'admin',8=>'oper'],'if',self::id(...));
        $extra=$this->table(self::IFX,[1=>'name',15=>'high_speed',18=>'alias',19=>'discontinuity'],'ifx',self::id(...));
        $rows=[];
        foreach($base as $id=>$r){
            $id=(string)$id;$x=$extra[$id]??[];
            if(!isset($r['description'],$r['oper']))self::bad();
            $speed=self::val($r,'speed',fn($v)=>self::number($v,4294967295));
            $high=self::val($x,'high_speed',fn($v)=>self::number($v,4294967295));
            $rows[$id]=['if_index'=>$id,'name'=>self::val($x,'name'),'description'=>self::val($r,'description'),'alias'=>self::val($x,'alias'),
                'type'=>self::val($r,'type',self::number(...)),'mtu'=>self::val($r,'mtu',self::number(...)),
                'mac'=>self::val($r,'address',self::mac(...)),'speed_bps'=>$speed!==null&&$speed!=='0'&&$speed!=='4294967295'?$speed:($high!==null&&$high!=='0'?(string)((int)$high*1000000):null),
                'admin_status'=>self::val($r,'admin',fn($v)=>self::number($v,3,false)), 'oper_status'=>self::number($r['oper'],7,false),
                'counter_discontinuity_ticks'=>self::val($x,'discontinuity',fn($v)=>self::number($v,4294967295))];
        }
        if(count($rows)!==(int)$expected||array_diff_key($extra,$base)||$session->get('.1.3.6.1.2.1.2.1.0')!==$expected)self::bad();
        ksort($rows,SORT_NUMERIC);return $rows;
    }
    private function addresses(array $interfaces):array
    {
        $out=[];
        foreach(['.1.3.6.1.2.1.4.20.1.2'=>false,'.1.3.6.1.2.1.4.34.1.3'=>true] as $root=>$modern){
            foreach($this->column($root,$modern?'ip.modern':'ip.legacy') as $key=>$v){
                $parts=explode('.',(string)$key);
                if($modern){$type=array_shift($parts);$len=array_shift($parts);if(!in_array($type,['1','2'],true))continue;if($len!==($type==='1'?'4':'16')||count($parts)!==(int)$len)self::bad();}
                elseif(count($parts)!==4)self::bad();
                $raw='';foreach($parts as $p)$raw.=chr((int)self::number($p,255));
                $ip=inet_ntop($raw);if($ip===false)self::bad();$idx=self::number($v);if($idx==='0')continue;
                if(!isset($interfaces[$idx]))self::bad();$out[$idx.'|'.$ip]=['if_index'=>$idx,'address'=>$ip];
            }
        }return array_values($out);
    }
    private function hardware():array
    {
        $rows=$this->table(self::ENTITY,[2=>'description',4=>'parent',5=>'class',7=>'name',8=>'hardware_revision',9=>'firmware_revision',10=>'software_revision',11=>'serial',12=>'manufacturer',13=>'model'],'entity',self::id(...));
        $out=[];foreach($rows as $id=>$r){$entry=['entity_index'=>(string)$id];foreach(['description','name','hardware_revision','firmware_revision','software_revision','serial','manufacturer','model'] as $field)$entry[$field]=self::val($r,$field);
            $entry['parent_index']=self::val($r,'parent',self::number(...));$entry['class']=self::val($r,'class',fn($v)=>self::number($v,2147483647,false));$out[]=$entry;}
        return $out;
    }
    /** Q-BRIDGE PortList: most significant bit corresponds to bridge port 1. */
    private static function ports(string $bitmap):array
    {
        if(strlen($bitmap)>512)self::bad();$ports=[];
        for($i=0;$i<strlen($bitmap);$i++)for($bit=0;$bit<8;$bit++)if(ord($bitmap[$i])&(128>>$bit))$ports[]=(string)($i*8+$bit+1);
        return $ports;
    }
    private function vlan(array $interfaces):array
    {
        $bridge=$this->column(self::BRIDGE,'bridge.port_ifindex');$ports=[];
        foreach($bridge as $port=>$idx){$port=self::id($port);$idx=self::id($idx);$ports[$port]=['bridge_port'=>$port,'if_index'=>isset($interfaces[$idx])?$idx:null,'reported_if_index'=>$idx,'pvid'=>null];}
        foreach($this->column(self::VLAN.'5.1.1','vlan.pvid') as $port=>$pvid){$port=self::id($port);$ports[$port]??=['bridge_port'=>$port,'if_index'=>null,'reported_if_index'=>null,'pvid'=>null];$ports[$port]['pvid']=self::vlanIndex($pvid);}
        $names=$this->column(self::VLAN.'3.1.1','vlan.static_name');
        $out=[];$membership=[];$seenVlans=[];
        // Retain timeMark: different observations must not be silently joined by VLAN ID.
        foreach($this->table(self::VLAN.'2.1.',[4=>'egress',5=>'untagged'],'vlan.current',function($k){$p=explode('.',$k);if(count($p)!==2)self::bad();self::number($p[0],4294967295);self::vlanIndex($p[1]);}) as $key=>$row){
            [$time,$vid]=explode('.',(string)$key);$seenVlans[$vid]=true;$eg=isset($row['egress'])?self::ports($row['egress']):null;$un=isset($row['untagged'])?self::ports($row['untagged']):null;
            $out[]=['vlan_id'=>$vid,'time_mark'=>$time,'name'=>isset($names[$vid])?self::text($names[$vid]):null,'membership_state'=>$eg!==null&&$un!==null?(array_diff($un,$eg)?'inconsistent':'observed'):'partial'];
            $egSet=$eg===null?null:array_fill_keys($eg,true);$unSet=$un===null?null:array_fill_keys($un,true);
            foreach(array_unique(array_merge($eg??[],$un??[])) as $port){if(count($membership)>=10000)self::bad();$membership[]=['vlan_id'=>$vid,'time_mark'=>$time,'bridge_port'=>$port,'if_index'=>$ports[$port]['if_index']??null,'egress'=>$eg===null?null:(isset($egSet[$port])?'yes':'no'),'untagged'=>$un===null?null:(isset($unSet[$port])?'yes':'no'),'tagged'=>$eg===null||$un===null?null:(isset($egSet[$port])&&!isset($unSet[$port])?'yes':'no')];}
        }
        foreach($names as $vid=>$name){$vid=self::vlanIndex($vid);if(!isset($seenVlans[$vid]))$out[]=['vlan_id'=>$vid,'time_mark'=>null,'name'=>self::text($name),'membership_state'=>'not_visible'];}
        return ['bridge_ports'=>array_values($ports),'vlans'=>$out,'vlan_membership'=>$membership];
    }
    private static function lldpId(?string $v,?string $type,bool $chassis):?string
    {
        if($v===null)return null;if($type===($chassis?'4':'3'))return self::mac($v);
        return self::text($v);
    }
    private function optionalScalar(string $root,string $label):?string
    {
        $values=$this->column($root,$label,1);
        if($values===[])return null;
        if(count($values)!==1||!array_key_exists(0,$values))self::bad();
        return $values[0];
    }
    private function lldp(array $interfaces):array
    {
        $chassisType=$this->optionalScalar(self::LLDP.'3.1','lldp.chassis_subtype');
        $chassisType=$chassisType===null?null:self::number($chassisType,7,false);
        $chassis=$this->lldpId($this->optionalScalar(self::LLDP.'3.2','lldp.chassis_id'),$chassisType,true);
        $sysName=$this->optionalScalar(self::LLDP.'3.3','lldp.system_name');
        $localDevice=$chassisType===null&&$chassis===null&&$sysName===null?[]:[['chassis_subtype'=>$chassisType,'chassis_id'=>$chassis,'system_name'=>$sysName===null?null:self::text($sysName)]];
        $local=$this->table(self::LLDP.'3.7.1.',[2=>'subtype',3=>'id',4=>'description'],'lldp.local',self::id(...));$ports=[];
        foreach($local as $port=>$r){$type=self::val($r,'subtype',fn($v)=>self::number($v,7,false));$id=self::lldpId($r['id']??null,$type,false);$matches=[];
            // LLDP local port number is NOT ifIndex. Only exact unique ifName/MAC is evidence.
            if($id!==null&&$id!=='')foreach($interfaces as $idx=>$i)if(($type==='5'&&$i['name']===$id)||($type==='3'&&$i['mac']===$id&&preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/D',$id)))$matches[]=(string)$idx;
            $ports[$port]=['local_port'=>(string)$port,'port_subtype'=>$type,'port_id'=>$id,'description'=>self::val($r,'description'),'if_index'=>count($matches)===1?$matches[0]:null];
        }
        $remote=$this->table(self::LLDP.'4.1.1.',[4=>'chassis_subtype',5=>'chassis',6=>'port_subtype',7=>'port',8=>'description',9=>'name'],'lldp.remote',function($k){$p=explode('.',$k);if(count($p)!==3)self::bad();self::number($p[0],4294967295);self::id($p[1]);self::id($p[2]);});
        $neighbors=[];foreach($remote as $key=>$r){[$time,$port,$idx]=explode('.',(string)$key);$ct=self::val($r,'chassis_subtype',fn($v)=>self::number($v,7,false));$pt=self::val($r,'port_subtype',fn($v)=>self::number($v,7,false));
            $neighbors[]=['time_mark'=>$time,'local_port'=>$port,'remote_index'=>$idx,'if_index'=>$ports[$port]['if_index']??null,'chassis_subtype'=>$ct,'chassis_id'=>self::lldpId($r['chassis']??null,$ct,true),'port_subtype'=>$pt,'port_id'=>self::lldpId($r['port']??null,$pt,false),'port_description'=>self::val($r,'description'),'system_name'=>self::val($r,'name')];}
        return ['lldp_local'=>$localDevice,'lldp_ports'=>array_values($ports),'neighbors'=>$neighbors];
    }
    public function collect(object $session,string $scope):array
    {
        if(!in_array($scope,self::SCOPES,true))self::bad();
        $uptime=self::number($session->get('.1.3.6.1.2.1.1.3.0'),4294967295);
        if($scope==='device')$sections=['device'=>[['name'=>self::text($session->get('.1.3.6.1.2.1.1.5.0')),'description'=>self::text($session->get('.1.3.6.1.2.1.1.1.0')),'object_id'=>self::text($session->get('.1.3.6.1.2.1.1.2.0')),'uptime_ticks'=>$uptime]],'hardware'=>$this->hardware()];
        else {$interfaces=$this->interfaces($session);$sections=['interfaces'=>array_values($interfaces)];if($scope==='interfaces')$sections['addresses']=$this->addresses($interfaces);else $sections+=$this->vlan($interfaces)+$this->lldp($interfaces);}
        if((int)self::number($session->get('.1.3.6.1.2.1.1.3.0'),4294967295)<(int)$uptime)self::bad(); // reboot or wrap: retry next job
        $sections['visibility']=[];foreach($this->tables as $table=>$state)$sections['visibility'][]=['table'=>$table,'state'=>$state];
        // An empty walk is indistinguishable from a restricted MIB view. Never claim complete topology.
        $snapshot=['scope'=>$scope,'complete'=>false,'counts'=>array_map('count',$sections),'sections'=>$sections];
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR))>750000)self::bad();return $snapshot;
    }
}
