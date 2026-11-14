<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Collector\{CollectionPlan, CollectionRuntimeService, CollectorRegistry};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;

/** Operator-owned acceptance using the same authenticated drivers and collector runtime as workers.
 * No persistence, no secret or observed configuration in the report. RouterOS export uses a temporary file.
 */
final class OsNetworkLiveVerifier
{
    private const TYPES=['ssh'=>['ssh_password','ssh_private_key'],'winrm'=>['winrm'],'snmp'=>['snmp_v3'],'routeros'=>['routeros_api']];
    private const SCOPES=['ssh'=>LinuxDiscovery::SCOPES,'winrm'=>WindowsDiscovery::SCOPES,'snmp'=>SnmpDiscovery::SCOPES,'routeros'=>RouterOsConnector::SCOPES];

    private static function keys(array $data,array $keys):void
    {
        if(count($data)!==count($keys)||array_diff($keys,array_keys($data)))throw new \InvalidArgumentException('Invalid acceptance config.');
    }
    private static function text(mixed $value,int $max=255):bool
    {return is_string($value)&&$value!==''&&strlen($value)<=$max&&!preg_match('/[\x00-\x1f\x7f]/',$value);}
    private static function secret(string $path):string
    {
        if(!str_starts_with($path,'/')||is_link($path)||!is_file($path))throw new \InvalidArgumentException('Private credential file required.');
        $f=@fopen($path,'rb');if($f===false)throw new \InvalidArgumentException('Private credential file required.');
        try{
            $s=fstat($f);if(!$s||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0||$s['size']<1||$s['size']>65536)throw new \InvalidArgumentException('Private credential file required.');
            $v=stream_get_contents($f,65537);if(!is_string($v)||strlen($v)>65536||trim($v)==='')throw new \InvalidArgumentException('Invalid credential.');return $v;
        }finally{fclose($f);}
    }
    private static function evidence(ConnectorResult $r,string $key,string $scope):array
    {
        $s=$r->connection[$key]??null;
        if(($r->connection['profile_verified']??false)!==true||($r->connection['identity_verified']??false)!==true||!is_array($s)||($s['scope']??null)!==$scope||!is_array($s['sections']??null))throw new \RuntimeException('Evidence missing.');
        return $s;
    }
    private static function check(array &$checks,string $name,callable $fn):bool
    {
        try{$ok=$fn()===true;$error=$ok?null:'expected_evidence_missing';}
        catch(HttpException $e){$ok=false;$error=in_array($e->errorCode,ConnectorHealthMonitor::ERRORS,true)?$e->errorCode:'probe_failed';}
        catch(ConnectorTransportException $e){$ok=false;$error=$e->category;}
        catch(\Throwable){$ok=false;$error='probe_failed';}
        $checks[]=['name'=>$name,'ok'=>$ok,'error'=>$error];return $ok;
    }
    public function run(array $config):array
    {
        self::keys($config,['allowed_networks','python','targets','allow_routeros_export']);
        if(!is_array($config['allowed_networks'])||!array_is_list($config['allowed_networks'])||$config['allowed_networks']===[]||!self::text($config['python'],4096)||!str_starts_with($config['python'],'/')||$config['allow_routeros_export']!==true||!is_array($config['targets'])||!array_is_list($config['targets'])||count($config['targets'])<4||count($config['targets'])>16)throw new \InvalidArgumentException('Explicit allowlist, four providers and export consent required.');
        $policy=new EndpointPolicy($config['allowed_networks'],null,true);$targets=[];$seen=[];
        foreach($config['targets'] as $t){
            if(!is_array($t))throw new \InvalidArgumentException('Invalid target.');
            self::keys($t,['provider','host','port','credential_type','secret_file','profile','ca_file','expected_name','expected_interface']);
            $p=$t['provider'];if(!is_string($p)||!isset(self::TYPES[$p])||!in_array($t['credential_type'],self::TYPES[$p],true)||!self::text($t['host'])||!is_int($t['port'])||!self::text($t['secret_file'],4096)||!is_array($t['profile'])||!self::text($t['expected_name'])||!self::text($t['expected_interface']))throw new \InvalidArgumentException('Invalid target.');
            if($t['ca_file']!==null&&(!in_array($p,['winrm','routeros'],true)||!self::text($t['ca_file'],4096)||!str_starts_with($t['ca_file'],'/')||!is_file($t['ca_file'])||!is_readable($t['ca_file'])))throw new \InvalidArgumentException('Invalid CA file.');
            $ctx=new ConnectorContext($p,$t['host'],$t['port'],$t['credential_type'],null,null,$t['profile']);
            $key=$p.'|'.$ctx->host.'|'.$ctx->port;if(isset($seen[$key]))throw new \InvalidArgumentException('Duplicate target.');$seen[$key]=true;
            $targets[]=[$ctx,$t];
        }
        if(array_diff(array_keys(self::TYPES),array_map(fn($entry)=>$entry[0]->provider,$targets)))throw new \InvalidArgumentException('All four providers required.');
        $report=[];$ok=true;
        foreach($targets as [$ctx,$t]){
            $p=$ctx->provider;$checks=[];$secret=self::secret($t['secret_file']);
            try{
                $host=new HostTransport($policy,$config['python'],null,$t['ca_file'],true);
                $driver=match($p){'ssh'=>new LinuxConnector($host),'winrm'=>new WindowsConnector($host),'snmp'=>new RouterConnector(new SnmpTransport($policy,false,null,true)),'routeros'=>new RouterOsConnector(new HttpsTransport($policy),$t['ca_file'])};
                $registry=new ConnectorRegistry($driver);$purpose=$driver->manifest()->purpose;
                $runtime=new CollectionRuntimeService(CollectorRegistry::forConnectors($registry));
                $target=['provider'=>$p,'endpoint_host'=>$ctx->host,'endpoint_port'=>$ctx->port,'credential_type'=>$ctx->credentialType,'purpose'=>$purpose,'os_network_profile'=>$ctx->osNetworkProfile];
                $collect=static function(string $scope,array $previous=[])use($runtime,$target,$ctx,&$secret):ConnectorResult{return $runtime->collect(new CollectionPlan($ctx->provider.'.'.$scope,'inspect'),$target,$ctx->credentialType,$secret,$previous);};
                $health=self::check($checks,'health',function()use($registry,$ctx,$purpose,&$secret,$t):bool{
                    $r=$registry->execute($ctx,'health',$purpose,$secret);
                    return ($r->connection['profile_verified']??false)===true&&($r->connection['identity_verified']??false)===true&&($r->items[0]['fields']['name']??null)===$t['expected_name'];
                });
                if($health){
                    self::check($checks,'inventory',function()use($collect,$t,$p):bool{
                        $r=$collect('inventory');if(($r->items[0]['fields']['name']??null)!==$t['expected_name'])return false;
                        if($p==='routeros')return self::evidence($r,'routeros_discovery','inventory')['complete']===true;
                        return $p==='snmp'||$r->complete;
                    });
                    self::check($checks,'metrics',function()use($collect,$p):bool{
                        $metrics=array_column($collect('metrics')->metricSamples,'metric');
                        $required=match($p){'ssh','winrm'=>['system.cpu.utilization','system.memory.total','system.disk.total'],'snmp'=>['snmp.uptime'],'routeros'=>['routeros.cpu_load','routeros.total_memory']};
                        return !array_diff($required,$metrics);
                    });
                    $section=match($p){'ssh'=>'linux_discovery','winrm'=>'windows_discovery','snmp'=>'snmp_discovery','routeros'=>'routeros_discovery'};
                    foreach(self::SCOPES[$p] as $scope){
                        if($p==='routeros'&&$scope==='inventory')continue;
                        self::check($checks,$scope,function()use($collect,$section,$scope,$p,$t):bool{
                            $s=self::evidence($collect($scope),$section,$scope);$rows=$s['sections'];
                            if($p!=='snmp'&&!$s['complete'])return false;
                            if(in_array($scope,['network','interfaces'],true)){
                                foreach($rows['interfaces']??[] as $row)if(($row['name']??null)===$t['expected_interface']||($p==='snmp'&&($row['description']??null)===$t['expected_interface']))return true;
                                return false;
                            }
                            if($scope==='system')return ($rows['system'][0]['hostname']??null)===$t['expected_name'];
                            if($p==='snmp'&&$scope==='device')return ($rows['device'][0]['name']??null)===$t['expected_name'];
                            if($p==='snmp'&&$scope==='topology')return ($rows['interfaces']??[])!==[]; // empty LLDP is not evidence of adjacency
                            if($scope==='software')return ($rows[$p==='ssh'?'packages':'applications']??[])!==[];
                            if($scope==='services')return ($rows['services']??[])!==[];
                            if($scope==='storage')return ($rows[$p==='ssh'?'filesystems':'volumes']??[])!==[];
                            if($scope==='updates')return count($rows['reboot']??[])===1;
                            if($scope==='routing')return ($rows['routing_tables']??[])!==[];
                            return false;
                        });
                    }
                    if(in_array($p,['ssh','winrm'],true))self::check($checks,'events',function()use($collect):bool{
                        $first=$collect('events')->connection['system_events']??null;if(!is_array($first)||!isset($first['events'],$first['cursor'])||$first['cursor']===[])return false;
                        $next=$collect('events',$first['cursor'])->connection['system_events']??null;if(!is_array($next))return false;
                        return array_intersect(array_column($first['events'],'key'),array_column($next['events'],'key'))===[];
                    });
                    if($p==='routeros')self::check($checks,'configuration_backup',function()use($collect):bool{
                        $data=$collect('configuration-backup')->connection['network_configuration_backup']??null;
                        return is_string($data)&&NetworkConfigurationBackup::validate($data)!=='';
                    });
                }
            }finally{sodium_memzero($secret);}
            $passed=!in_array(false,array_column($checks,'ok'),true);$ok=$ok&&$passed;$report[]=['provider'=>$p,'ok'=>$passed,'checks'=>$checks];
        }
        return ['ok'=>$ok,'mode'=>'live_driver_acceptance','targets'=>$report];
    }
}
