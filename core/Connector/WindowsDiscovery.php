<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Strict allowlist for fixed PowerShell probes. Never persist raw CIM/registry objects. */
final class WindowsDiscovery
{
    public const SCOPES=['system','software','services','storage','network','updates'];
    private const SECTIONS=['system'=>['system','processors'],'software'=>['applications'],'services'=>['services'],'storage'=>['disks','partitions','volumes'],'network'=>['interfaces','addresses','routes'],'updates'=>['hotfixes','reboot']];
    private const FIELDS=[
        'system'=>['hostname','os_name','os_version','build','architecture','product_type','manufacturer','model','cpu_count','memory_bytes','memory_available_bytes','boot_time'],
        'processors'=>['id','model','cores','logical_processors','max_clock_mhz'],
        'applications'=>['id','registry_view','name','version','publisher','install_date'],
        'services'=>['name','display_name','state','start_mode','status','process_id'],
        'disks'=>['id','index','model','interface_type','size_bytes','status'],
        'partitions'=>['id','disk_index','index','type','size_bytes','offset_bytes'],
        'volumes'=>['id','drive_letter','label','filesystem','size_bytes','available_bytes'],
        'interfaces'=>['index','name','mac','speed_bps','status'],
        'addresses'=>['index','address','prefix','family','state'],
        'routes'=>['index','destination','gateway','family','metric'],
        'hotfixes'=>['id','description','installed_on'],
        'reboot'=>['cbs','windows_update','pending_file_rename','detected'],
    ];
    private static function bad():never {throw new ConnectorTransportException('connector_protocol_error');}
    public static function normalize(array $data,string $scope):array
    {
        if(!isset(self::SECTIONS[$scope])||count($data)!==3||($data['scope']??null)!==$scope||!is_array($data['sections']??null)||!is_string($data['identity']??null)||!preg_match('/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12})$/D',$data['identity'])||trim(str_replace('-','',$data['identity']),'0')==='')self::bad();
        $expected=self::SECTIONS[$scope];$sections=$data['sections'];
        if(count($sections)!==count($expected)||array_diff($expected,array_keys($sections)))self::bad();
        $clean=[];$counts=[];
        foreach($expected as $section){
            $rows=$sections[$section];$fields=self::FIELDS[$section];
            if(!is_array($rows)||!array_is_list($rows)||count($rows)>10000||(in_array($section,['system','reboot'],true)&&count($rows)!==1))self::bad();
            $seen=[];$clean[$section]=[];
            foreach($rows as $row){
                if(!is_array($row)||count($row)!==count($fields)||array_diff($fields,array_keys($row)))self::bad();
                $item=[];
                foreach($fields as $field){
                    $v=$row[$field];
                    if($v!==null&&(!is_string($v)||strlen($v)>1024||!preg_match('//u',$v)||preg_match('/[\x00-\x1f\x7f]/',$v)))self::bad();
                    if($v!==null&&(str_ends_with($field,'_bytes')||in_array($field,['cpu_count','cores','logical_processors','max_clock_mhz','product_type','process_id','index','disk_index','speed_bps','prefix','metric'],true))&&!preg_match('/^(0|[1-9][0-9]{0,39})$/D',$v))self::bad();
                    $item[$field]=$v;
                }
                if($section==='system'&&(!$item['hostname']||!$item['os_name']||!$item['os_version']||!$item['cpu_count']||!$item['memory_bytes']))self::bad();
                if($section==='applications'&&(!$item['name']||!in_array($item['registry_view'],['Registry32','Registry64'],true)))self::bad();
                if(in_array($section,['addresses','routes'],true)){
                    $family=$item['family'];if(!in_array($family,['IPv4','IPv6'],true))self::bad();$flag=$family==='IPv4'?FILTER_FLAG_IPV4:FILTER_FLAG_IPV6;$max=$family==='IPv4'?32:128;
                    if($section==='addresses'&&(!filter_var($item['address'],FILTER_VALIDATE_IP,$flag)||$item['prefix']===null||(int)$item['prefix']>$max))self::bad();
                    if($section==='routes'){$parts=explode('/',$item['destination']??'');if(count($parts)!==2||!filter_var($parts[0],FILTER_VALIDATE_IP,$flag)||!preg_match('/^(0|[1-9][0-9]{0,2})$/D',$parts[1])||(int)$parts[1]>$max||!filter_var($item['gateway'],FILTER_VALIDATE_IP,$flag))self::bad();}
                }
                if($section==='reboot'){foreach($item as $v)if(!in_array($v,['true','false'],true))self::bad();if(($item['detected']==='true')!==in_array('true',[$item['cbs'],$item['windows_update'],$item['pending_file_rename']],true))self::bad();}
                $key=match($section){'applications'=>[$item['id'],$item['registry_view']], 'services'=>[$item['name']], 'processors','disks','partitions','volumes'=>[$item['id']], 'interfaces'=>[$item['index']], 'system'=>[$item['hostname']],default=>null};
                if($key!==null){foreach($key as $k)if($k===null||$k==='')self::bad();$hash=json_encode($key,JSON_THROW_ON_ERROR);if(isset($seen[$hash]))self::bad();$seen[$hash]=true;}
                // QFE can legitimately enumerate the same KB more than once; retain distinct dates/descriptions.
                if($section==='hotfixes'){if(!$item['id'])self::bad();$hash=json_encode($item,JSON_THROW_ON_ERROR);if(isset($seen[$hash]))continue;$seen[$hash]=true;}
                $clean[$section][]=$item;
            }
            $counts[$section]=count($clean[$section]);
        }
        $snapshot=['scope'=>$scope,'complete'=>true,'counts'=>$counts,'sections'=>$clean];
        if(strlen(json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>750000)self::bad();
        return $snapshot;
    }
}
