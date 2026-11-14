<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Validates the bounded, fixed Linux probes before exposing any remote data. */
final class LinuxDiscovery
{
    public const SCOPES=['system','software','services','storage','network'];
    private const FIELDS=[
        'system'=>['hostname','os_id','os_name','os_version','kernel','architecture','cpu_count','cpu_model','cpu_sockets','cpu_cores','memory_bytes','memory_available_bytes','swap_bytes','swap_free_bytes','uptime_seconds','manufacturer','model'],
        'packages'=>['name','version','architecture'],
        'services'=>['name','enabled','load','active','sub'],
        'devices'=>['name','kernel_name','parent','type','size_bytes','filesystem','read_only'],
        'filesystems'=>['mount','source','filesystem','size_bytes','used_bytes','available_bytes'],
        'interfaces'=>['name','index','mac','mtu','state','master'],
        'addresses'=>['interface','address','prefix','family','scope'],
        'routes'=>['family','destination','gateway','interface','table','metric','type'],
    ];
    private const SECTIONS=['system'=>['system'],'software'=>['packages'],'services'=>['services'],'storage'=>['devices','filesystems'],'network'=>['interfaces','addresses','routes']];
    private static function bad():never {throw new ConnectorTransportException('connector_protocol_error');}
    public static function normalize(array $data,string $scope):array
    {
        if(!isset(self::SECTIONS[$scope])||count($data)!==3||($data['scope']??null)!==$scope||!is_array($data['sections']??null)||!is_string($data['identity']??null)||!preg_match('/^[a-f0-9]{32}$/D',$data['identity'])||trim($data['identity'],'0')==='')self::bad();
        $sections=$data['sections'];$expected=self::SECTIONS[$scope];
        if(count($sections)!==count($expected)||array_diff($expected,array_keys($sections)))self::bad();
        $clean=[];$counts=[];
        foreach($expected as $section){
            $rows=$sections[$section];$fields=self::FIELDS[$section];
            if(!is_array($rows)||!array_is_list($rows)||count($rows)>10000||($section==='system'&&count($rows)!==1))self::bad();
            $seen=[];$clean[$section]=[];
            foreach($rows as $row){
                if(!is_array($row)||count($row)!==count($fields)||array_diff($fields,array_keys($row)))self::bad();
                $item=[];
                foreach($fields as $field){
                    $v=$row[$field];
                    if($v!==null&&(!is_string($v)||strlen($v)>1024||!preg_match('//u',$v)||preg_match('/[\x00-\x1f\x7f]/',$v)))self::bad();
                    if($v!==null&&(str_ends_with($field,'_bytes')||in_array($field,['cpu_count','cpu_sockets','cpu_cores','uptime_seconds','index','mtu','prefix','metric'],true))&&!preg_match('/^(0|[1-9][0-9]{0,39})$/D',$v))self::bad();
                    $item[$field]=$v;
                }
                if($section==='packages'&&empty($item['version']))self::bad();
                if($section==='filesystems'&&empty($item['mount']))self::bad();
                if($section==='system'&&(!in_array($item['os_id'],['debian','ubuntu'],true)||empty($item['hostname'])||empty($item['cpu_count'])||empty($item['memory_bytes'])))self::bad();
                if($section==='addresses'&&(!is_string($item['address'])||!in_array($item['family'],['inet','inet6'],true)||!filter_var($item['address'],FILTER_VALIDATE_IP,$item['family']==='inet'?FILTER_FLAG_IPV4:FILTER_FLAG_IPV6)||$item['prefix']===null||(int)$item['prefix']>($item['family']==='inet'?32:128)))self::bad();
                if($section==='routes'&&(!in_array($item['family'],['4','6'],true)||($item['gateway']!==null&&!filter_var($item['gateway'],FILTER_VALIDATE_IP,$item['family']==='4'?FILTER_FLAG_IPV4:FILTER_FLAG_IPV6))))self::bad();
                $key=match($section){'packages'=>[$item['name'],$item['architecture']], 'services','interfaces'=>[$item['name']], 'devices'=>[$item['kernel_name']], 'system'=>[$item['hostname']],default=>null};
                if($key!==null){foreach($key as $k)if($k===null||$k==='')self::bad();$hash=json_encode($key,JSON_THROW_ON_ERROR);if(isset($seen[$hash]))self::bad();$seen[$hash]=true;}
                $clean[$section][]=$item;
            }
            $counts[$section]=count($clean[$section]);
        }
        $snapshot=['scope'=>$scope,'complete'=>true,'counts'=>$counts,'sections'=>$clean];
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>750000)self::bad();
        return $snapshot;
    }
}
