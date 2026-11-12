<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Bounded numeric GET/GETNEXT over pinned UDP endpoints. No arbitrary OIDs or write operations. */
final class SnmpTransport
{
    private const NAME='.1.3.6.1.2.1.1.5.0';
    private const OBJECT='.1.3.6.1.2.1.1.2.0';
    private const DESCRIPTION='.1.3.6.1.2.1.1.1.0';
    private const IF_NUMBER='.1.3.6.1.2.1.2.1.0';
    private const IF_DESCR='.1.3.6.1.2.1.2.2.1.2';
    private const IF_STATUS='.1.3.6.1.2.1.2.2.1.8';
    private const IP_IF='.1.3.6.1.2.1.4.20.1.2';
    private const IP_MODERN='.1.3.6.1.2.1.4.34.1.3';
    public function __construct(private readonly EndpointPolicy $policy,private readonly bool $legacy=false,private readonly ?\Closure $factory=null) {}
    public function credentialTypes():array { return $this->legacy?['snmp_v3','snmp_v2c']:['snmp_v3']; }
    private static function bad():never { throw new ConnectorTransportException('connector_protocol_error'); }
    private static function checkTime(float $deadline):void { if (microtime(true)>=$deadline) throw new ConnectorTransportException('connector_transport_failed'); }
    private static function text(string $value,int $limit):string
    {
        if (!preg_match('//u',$value)||strlen($value)>4096) self::bad();
        $value=trim(preg_replace('/[\x00-\x20\x7f]+/',' ',$value));
        if (strlen($value)>$limit) self::bad(); return $value;
    }
    private static function numeric(string $oid):string
    {
        $oid='.'.ltrim($oid,'.');
        if (!preg_match('/^\.(?:0|[1-9][0-9]{0,9})(?:\.(?:0|[1-9][0-9]{0,9})){1,127}$/D',$oid)) self::bad();
        return $oid;
    }
    private static function compare(string $a,string $b):int
    {
        $a=explode('.',ltrim($a,'.'));$b=explode('.',ltrim($b,'.'));
        for($i=0;$i<min(count($a),count($b));$i++) if ((int)$a[$i] !== (int)$b[$i]) return (int)$a[$i]<=>(int)$b[$i];
        return count($a)<=>count($b);
    }
    private static function walk(object $session,string $root,float $deadline,int $limit=64):array
    {
        $cursor=$root;$out=[];
        for ($i=0;$i<=$limit;$i++) {
            self::checkTime($deadline);
            [$oid,$value]=$session->next($cursor);if($oid===null&&$value===null)return $out;
            if(!is_string($oid)||!is_string($value)) self::bad();$oid=self::numeric($oid);
            if (self::compare($oid,$cursor)<=0) self::bad();
            if (!str_starts_with($oid,$root.'.')) return $out;
            if ($i===$limit||strlen($value)>4096) self::bad();
            $out[substr($oid,strlen($root)+1)]=$value;$cursor=$oid;
        }
        self::bad();
    }
    private static function index(string $value):int
    { if (!preg_match('/^[1-9][0-9]{0,9}$/D',$value)||(int)$value>2147483647) self::bad();return (int)$value; }
    private static function ip(string $suffix,bool $modern):?string
    {
        $parts=explode('.',$suffix);
        if ($modern) {
            $type=array_shift($parts);$len=array_shift($parts);
            if (!in_array($type,['1','2'],true)) return null; // scoped/non-IP address families are not imported
            if ($len!==($type==='1'?'4':'16')||count($parts)!==(int)$len) self::bad();
        } elseif(count($parts)!==4) self::bad();
        $bytes='';foreach($parts as $v){if(!ctype_digit($v)||(int)$v>255)self::bad();$bytes.=chr((int)$v);}
        $ip=inet_ntop($bytes);if($ip===false)self::bad();return $ip;
    }
    public function collect(ConnectorContext $context,#[\SensitiveParameter] array $credential,bool $probe):array
    {
        if ($context->provider!=='snmp'||!in_array($context->credentialType,$this->credentialTypes(),true)) throw new ConnectorTransportException('connector_auth_failed');
        if ($context->credentialType==='snmp_v3'&&($credential['privacy_protocol']??null)!=='aes128') throw new ConnectorTransportException('connector_auth_failed');
        $ip=$this->policy->resolve($context->host);
        $session=$this->factory!==null?($this->factory)($ip,$context->port,$context->credentialType,$credential):new SnmpSession($ip,$context->port,$context->credentialType,$credential);
        $deadline=microtime(true)+45;
        try {
            $name=self::text($session->get(self::NAME),255);self::checkTime($deadline);
            $object=self::numeric($session->get(self::OBJECT));self::checkTime($deadline);
            $result=['name'=>$name!==''?$name:$context->host,'object_id'=>$object];
            if ($probe) return $result;
            $result['description']=self::text($session->get(self::DESCRIPTION),512);
            $expected=self::index($session->get(self::IF_NUMBER));if($expected>64) self::bad();
            $names=self::walk($session,self::IF_DESCR,$deadline);$states=self::walk($session,self::IF_STATUS,$deadline);
            if (count($names)!==$expected||count($names)!==count($states)||array_diff_key($names,$states)) self::bad();
            $interfaces=[];$addresses=[];
            foreach($names as $idx=>$name){$idx=self::index((string)$idx);$name=self::text($name,128);$state=$states[$idx];
                if ($name===''||!preg_match('/^[1-7]$/D',$state))self::bad();
                $interfaces[$idx]=['index'=>$idx,'name'=>$name,'oper_status'=>(int)$state,'addresses'=>[]];}
            foreach ([self::IP_IF=>false,self::IP_MODERN=>true] as $root=>$modern) foreach(self::walk($session,$root,$deadline) as $suffix=>$idx){
                $addr=self::ip((string)$suffix,$modern);if($addr===null||$idx==='0')continue;$idx=self::index($idx);
                if (!isset($interfaces[$idx])) self::bad();
                $interfaces[$idx]['addresses'][$addr]=$addr;$addresses[$addr]=$addr;
                if(count($addresses)>64)self::bad();
            }
            ksort($interfaces,SORT_NUMERIC);foreach($interfaces as &$row){ksort($row['addresses'],SORT_STRING);$row['addresses']=array_values($row['addresses']);}unset($row);
            ksort($addresses,SORT_STRING);$result['interfaces']=array_values($interfaces);$result['addresses']=array_values($addresses);
            return $result;
        } finally {$session->close();}
    }
}
