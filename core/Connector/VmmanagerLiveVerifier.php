<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Operator-owned live acceptance. Uses the production driver; no writes or raw remote output. */
final class VmmanagerLiveVerifier
{
    private array $checks=[];
    private function check(string $name,callable $fn):bool
    {
        try{$ok=$fn()===true;$error=$ok?null:'expected_evidence_missing';}
        catch(ConnectorTransportException $e){$ok=false;$error=$e->category;}
        catch(\Throwable){$ok=false;$error='probe_failed';}
        $this->checks[]=['name'=>$name,'ok'=>$ok,'error'=>$error];return $ok;
    }
    private static function keys(array $a,array $keys):void
    {if(array_diff(array_keys($a),$keys)||array_diff($keys,array_keys($a)))throw new \InvalidArgumentException('Invalid acceptance configuration.');}
    private static function secret(string $path):string
    {
        if(is_link($path)||!is_file($path))throw new \InvalidArgumentException('Private credential file required.');
        $f=@fopen($path,'rb');if($f===false)throw new \InvalidArgumentException('Private credential file required.');
        try{
            $s=fstat($f);if(!$s||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0||$s['size']<1||$s['size']>16384)throw new \InvalidArgumentException('Private credential file required.');
            $v=stream_get_contents($f,16385);if(!is_string($v)||strlen($v)>16384||($v=rtrim($v,"\r\n"))==='')throw new \InvalidArgumentException('Invalid credential file.');return $v;
        }finally{fclose($f);}
    }
    /** Never combine successful observations from different authenticated principals. */
    private static function observation(ConnectorResult $result,string $section,int $owner):array
    {
        $c=$result->connection;$a=$c['authentication']??[];
        if(($c['tls_pinned']??false)!==true||($c['identity_verified']??false)!==true||($a['owner_id']??null)!==$owner||($a['trustee_id']??null)!==$owner||($a['administrator']??false)!==true)throw new ConnectorTransportException('connector_auth_failed');
        if(!is_array($c[$section]??null))throw new ConnectorTransportException('connector_protocol_error');
        return $c[$section];
    }
    public function run(array $config):array
    {
        $this->checks=[];self::keys($config,['allowed_networks','target','expect']);
        foreach(['allowed_networks','target','expect'] as $k)if(!is_array($config[$k]))throw new \InvalidArgumentException('Invalid acceptance configuration.');
        if(!array_is_list($config['allowed_networks'])||$config['allowed_networks']===[])throw new \InvalidArgumentException('Explicit network allowlist required.');
        $t=$config['target'];$e=$config['expect'];
        self::keys($t,['host','port','ca_file','credential_type','secret_file','profile']);
        if(!is_string($t['host'])||!is_int($t['port'])||!is_string($t['ca_file'])||!is_file($t['ca_file'])||!is_readable($t['ca_file'])||!is_string($t['secret_file'])||!is_array($t['profile'])||!in_array($t['credential_type'],['api_token','vmmanager_api'],true))throw new \InvalidArgumentException('Invalid target.');
        self::keys($e,['owner_id','kvm_host','lxd_host','storage_id','os_id','preset_id','image_id','iso_id','network_id','pool_id','address','task_id']);
        foreach($e as $k=>$v)if($k!=='address'&&(!is_int($v)||$v<1||$v>2147483647))throw new \InvalidArgumentException('Positive expected IDs required.');
        if($e['kvm_host']===$e['lxd_host']||!is_string($e['address'])||filter_var($e['address'],FILTER_VALIDATE_IP)===false)throw new \InvalidArgumentException('Invalid expected resource.');
        $e['address']=inet_ntop(inet_pton($e['address']));
        $ctx=new ConnectorContext('vmmanager',$t['host'],$t['port'],$t['credential_type'],null,$t['profile']);
        $driver=new VmmanagerConnector(new HttpsTransport(new EndpointPolicy($config['allowed_networks'],null,true)),$t['ca_file'],true);
        $secret=self::secret($t['secret_file']);
        try{
            $health=null;$clusters=null;$guests=null;$network=null;$storage=null;$templates=null;$tasks=null;
            $authenticated=$this->check('vmmanager.authentication',function()use($driver,$ctx,&$secret,$e,&$health):bool{
                $health=$driver->execute($ctx,'health',$secret)->connection;
                $a=$health['authentication'];return $health['tls_pinned']&&$health['identity_verified']&&$a['owner_id']===$e['owner_id']&&$a['trustee_id']===$e['owner_id']&&$a['administrator'];
            });
            if(!$authenticated)return ['ok'=>false,'mode'=>'read_only_expected_resources','checks'=>$this->checks];
            $this->check('vmmanager.capabilities',fn()=>is_array($health)&&!array_diff(['nodes','guests','clusters','tasks','networks','pools','addresses'],array_keys(array_filter($health['capabilities'],fn($v)=>$v==='available')))&&count(array_filter($health['action_capabilities'],fn($v)=>$v==='advertised'))===3);
            $this->check('vmmanager.clusters',function()use($driver,$ctx,&$secret,&$clusters,$e):bool{
                $clusters=self::observation($driver->cluster($ctx,$secret),'vmmanager_cluster_discovery',$e['owner_id']);
                return $clusters['complete']&&$clusters['counts']['nodes']>0&&$clusters['counts']['clusters']>0;
            });
            $this->check('vmmanager.guests',function()use($driver,$ctx,&$secret,$e,&$guests,&$clusters):bool{
                $guests=self::observation($driver->guests($ctx,$secret),'vmmanager_guest_discovery',$e['owner_id']);
                if(!$guests['complete']||!is_array($clusters))return false;
                $rows=array_column($guests['guests'],null,'id');$nodes=array_column($clusters['nodes'],null,'id');$cs=array_column($clusters['clusters'],null,'id');
                foreach(['kvm_host'=>'kvm','lxd_host'=>'lxd'] as $key=>$type){$g=$rows[$e[$key]]??null;$n=$nodes[$g['node_id']??0]??null;$c=$cs[$g['cluster_id']??0]??null;
                    if(!$g||!$n||!$c||$n['cluster_id']!==$g['cluster_id']||$g['virtualization_type']!==$type||$c['virtualization_type']!==$type||$g['cpu_number']===null||$g['ram_mib']===null||!$g['disks']||!$g['interfaces'])return false;}
                return true;
            });
            $this->check('vmmanager.network',function()use($driver,$ctx,&$secret,$e,&$network):bool{
                $network=self::observation($driver->network($ctx,$secret),'vmmanager_network_discovery',$e['owner_id']);
                $pool=array_column($network['pools'],null,'id')[$e['pool_id']]??null;$net=array_column($network['networks'],null,'id')[$e['network_id']]??null;
                if(!$network['complete']||!$pool||!$net||!in_array($e['network_id'],$pool['network_ids']??[],true))return false;
                $nics=array_column($network['interfaces'],null,'key');
                foreach($network['addresses'] as $ip)if($ip['address']===$e['address']&&$ip['network_id']===$e['network_id']&&$ip['pool_id']===$e['pool_id']&&$ip['status']==='busy'&&$ip['issue']===null&&($nics[$ip['nic_key']]['host_id']??null)===$e['kvm_host'])return true;
                return false;
            });
            $this->check('vmmanager.storage',function()use($driver,$ctx,&$secret,$e,&$storage):bool{
                $storage=self::observation($driver->storage($ctx,$secret),'vmmanager_storage_discovery',$e['owner_id']);
                return $storage['complete']&&isset(array_column($storage['storages'],null,'id')[$e['storage_id']])&&array_filter($storage['volumes'],fn($v)=>$v['guest_id']===$e['kvm_host']&&$v['storage_id']===$e['storage_id']&&$v['storage_visible']&&$v['size_mib']!==null)!==[];
            });
            $this->check('vmmanager.templates',function()use($driver,$ctx,&$secret,$e,&$templates):bool{
                $templates=self::observation($driver->templates($ctx,$secret),'vmmanager_templates_discovery',$e['owner_id']);
                if(!$templates['complete'])return false;
                foreach(['os'=>'os_id','presets'=>'preset_id','images'=>'image_id','isos'=>'iso_id'] as $kind=>$key)if(!isset(array_column($templates[$kind],null,'id')[$e[$key]]))return false;
                return array_filter($templates['guest_os_links'],fn($v)=>$v['guest_id']===$e['kvm_host']&&$v['os_id']===$e['os_id']&&$v['os_visible'])!==[];
            });
            $this->check('vmmanager.tasks',function()use($driver,$ctx,&$secret,$e,&$tasks,$health):bool{
                // Start immediately before the expected known task; still use actual collector pagination.
                $identity=$health['authentication'];
                $identity=array_intersect_key($identity,array_flip(['owner_id','trustee_id','administrator']));
                $tasks=self::observation($driver->tasks($ctx,$secret,['cursor'=>['after'=>$e['task_id']-1,'sweep_after'=>0,'identity'=>$identity]]),'vmmanager_task_discovery',$e['owner_id']);
                $row=array_column($tasks['tasks'],null,'remote_id')[$e['task_id']]??null;
                if(!$row||$row['resource_id']!=='host:'.$e['kvm_host']||$row['consul_id']===null||$row['state']!=='succeeded')return false;
                $next=self::observation($driver->tasks($ctx,$secret,['revision'=>1,'cursor'=>$tasks['cursor']]),'vmmanager_task_discovery',$e['owner_id']);
                return $next['expected_revision']===1&&$next['high_water_id']>=$tasks['high_water_id'];
            });
            foreach(['kvm_host','lxd_host'] as $key)$this->check('vmmanager.preflight.'.$key,function()use($driver,$ctx,&$secret,$e,$key,&$guests):bool{
                $g=array_column($guests['guests']??[],null,'id')[$e[$key]]??null;if(!$g||!in_array($g['state'],['running','stopped'],true))return false;
                $r=$driver->prepareAction($ctx,'vmmanager.host.'.($g['state']==='running'?'stop':'start'),'host:'.$e[$key],$secret);
                return $r['identity']['owner_id']===$e['owner_id']&&$r['guest']['id']===$e[$key]&&$r['fingerprint']===VmmanagerPowerPolicy::fingerprint($g);
            });
        }finally{sodium_memzero($secret);}
        return ['ok'=>!in_array(false,array_column($this->checks,'ok'),true),'mode'=>'read_only_expected_resources','checks'=>$this->checks];
    }
}
