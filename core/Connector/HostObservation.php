<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Strict allowlist of the host collector output; no arbitrary remote metadata. */
final class HostObservation
{
    private static function bad():never { throw new ConnectorTransportException('connector_protocol_error'); }
    private static function text(mixed $v,int $max):string
    {
        if (!is_string($v)||$v===''||strlen($v)>$max||!preg_match('//u',$v)||preg_match('/[\x00-\x1f\x7f]/',$v)) self::bad();
        return $v;
    }
    private static function positive(mixed $v,int $max=PHP_INT_MAX):int
    { if (!is_int($v)||$v<1||$v>$max) self::bad(); return $v; }
    public static function result(array $data,string $provider,string $operation):ConnectorResult
    {
        $keys=['identity','name','os_family','os_name','os_version','cpu_count','memory_bytes','disks','interfaces'];
        if (count($data)!==count($keys)||array_diff($keys,array_keys($data))) self::bad();
        $identity=strtolower(self::text($data['identity'],36));
        if (!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})$/D',$identity)||trim(str_replace('-','',$identity),'0')==='') self::bad();
        $identity=str_replace('-','',$identity);
        $family=$data['os_family'];
        if (!in_array($family,$provider==='ssh'?['linux','debian','ubuntu']:['windows'],true)) self::bad();
        $cpu=self::positive($data['cpu_count'],65536); $memory=self::positive($data['memory_bytes']);
        foreach (['disks','interfaces'] as $key) if (!is_array($data[$key])||!array_is_list($data[$key])||count($data[$key])>64) self::bad();
        $disks=[];$interfaces=[];$ips=[];
        foreach ($data['disks'] as $disk) {
            if (!is_array($disk)||count($disk)!==2||!isset($disk['name'],$disk['size_bytes'])) self::bad();
            $name=self::text($disk['name'],160); if (isset($disks[$name])) self::bad();
            $disks[$name]=['name'=>$name,'size_bytes'=>self::positive($disk['size_bytes'])];
        }
        foreach ($data['interfaces'] as $interface) {
            if (!is_array($interface)||count($interface)!==2||!isset($interface['name'],$interface['addresses'])) self::bad();
            $name=self::text($interface['name'],160);
            if (isset($interfaces[$name])||!is_array($interface['addresses'])||!array_is_list($interface['addresses'])||count($interface['addresses'])>64) self::bad();
            $addresses=[];
            foreach ($interface['addresses'] as $ip) {
                if (!is_string($ip)||!filter_var($ip,FILTER_VALIDATE_IP)) self::bad();
                $ip=inet_ntop(inet_pton($ip));$addresses[$ip]=true;$ips[$ip]=true;
            }
            $addresses=array_keys($addresses);sort($addresses,SORT_STRING);
            $interfaces[$name]=['name'=>$name,'addresses'=>$addresses];
        }
        if (count($ips)>64) self::bad();
        $ips=array_keys($ips);sort($ips,SORT_STRING);ksort($disks,SORT_STRING);ksort($interfaces,SORT_STRING);
        $attrs=['host_hardware'=>['os_name'=>self::text($data['os_name'],255),'os_version'=>self::text($data['os_version'],128),'cpu_count'=>$cpu,'memory_bytes'=>$memory,'disks'=>array_values($disks),'interfaces'=>array_values($interfaces)]];
        if (strlen(json_encode($attrs,JSON_THROW_ON_ERROR))>16000) self::bad();
        return new ConnectorResult([['type'=>'server','external_id'=>'host:'.hash('sha256',$provider.':'.$identity),
            'fields'=>['name'=>self::text($data['name'],255),'status'=>'online','os_family'=>$family,'ip_addresses'=>$ips,'attributes'=>$attrs]]],$operation!=='health');
    }
}
