<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

/** Read-only acceptance probe for operator-owned PVE/PBS lab targets. No credentials or raw remote data in reports. */
final class ProxmoxLiveVerifier
{
    private array $checks=[];
    private function check(string $name,callable $probe):bool
    {
        try{$ok=$probe()===true;$error=$ok?null:'expected_evidence_missing';}
        catch(ConnectorTransportException $e){$ok=false;$error=$e->category;}
        catch(\Throwable){$ok=false;$error='probe_failed';}
        $this->checks[]=['name'=>$name,'ok'=>$ok,'error'=>$error];return $ok;
    }
    private static function requireKeys(array $input,array $keys):void
    {if(array_diff(array_keys($input),$keys)||array_diff($keys,array_keys($input)))throw new \InvalidArgumentException('Invalid acceptance configuration.');}
    private static function target(array $v,string $provider):ConnectorContext
    {
        self::requireKeys($v,$provider==='proxmox'?['host','port','ca_file','credential_type','secret_file','profile']:['host','port','ca_file','secret_file']);
        if(!is_string($v['host'])||!is_int($v['port'])||!is_string($v['ca_file'])||!is_string($v['secret_file'])||!is_readable($v['ca_file']))throw new \InvalidArgumentException('Invalid acceptance target.');
        if($provider==='proxmox'&&(!in_array($v['credential_type'],['proxmox_api','api_token'],true)||!is_array($v['profile'])))throw new \InvalidArgumentException('Invalid PVE acceptance profile.');
        return new ConnectorContext($provider,$v['host'],$v['port'],$v['credential_type']??'api_token',$v['profile']??null);
    }
    private static function secret(string $path):string
    {
        if(is_link($path)||!is_file($path))throw new \InvalidArgumentException('Private credential file required.');
        $f=@fopen($path,'rb');if($f===false)throw new \InvalidArgumentException('Private credential file required.');
        try{$stat=fstat($f);if(!$stat||($stat['mode']&0170000)!==0100000||($stat['mode']&0077)!==0||$stat['size']<1||$stat['size']>16384)throw new \InvalidArgumentException('Private credential file required.');
            $value=stream_get_contents($f,16385);if(!is_string($value)||strlen($value)>16384)throw new \InvalidArgumentException('Invalid credential file.');$value=rtrim($value,"\r\n");if($value==='')throw new \InvalidArgumentException('Invalid credential file.');return $value;
        }finally{fclose($f);}
    }
    /** Expected identities deliberately require non-empty evidence, not just a 200 response. */
    public function run(array $config):array
    {
        $this->checks=[];self::requireKeys($config,['allowed_networks','pve','pbs','expect']);
        foreach(['allowed_networks','pve','pbs','expect'] as $key)if(!is_array($config[$key]))throw new \InvalidArgumentException('Invalid acceptance configuration.');
        if(!array_is_list($config['allowed_networks'])||$config['allowed_networks']===[])throw new \InvalidArgumentException('Explicit network allowlist required.');
        $pve=self::target($config['pve'],'proxmox');$pbs=self::target($config['pbs'],'pbs');$expect=$config['expect'];
        self::requireKeys($expect,['node','guest','storage','bridge','datastore','namespace','backup_type','backup_id','job_kinds']);
        foreach(['node','guest','storage','bridge','datastore','namespace','backup_type','backup_id'] as $key)if(!is_string($expect[$key])||strlen($expect[$key])>263||($key!=='namespace'&&$expect[$key]===''))throw new \InvalidArgumentException('Expected identities required.');
        if(!preg_match('/^(qemu|lxc):[1-9][0-9]{0,8}$/D',$expect['guest'])||!in_array($expect['backup_type'],['vm','ct','host'],true)||!is_array($expect['job_kinds'])||!array_is_list($expect['job_kinds'])||$expect['job_kinds']===[]||array_filter($expect['job_kinds'],static fn($v)=>!is_string($v))||array_diff($expect['job_kinds'],['sync','verify','prune','gc']))throw new \InvalidArgumentException('Invalid expected resource.');
        $transport=new HttpsTransport(new EndpointPolicy($config['allowed_networks'],null,true));
        $driver=new ProxmoxConnector(null,$config['pve']['ca_file'],$transport,true);
        $secret=self::secret($config['pve']['secret_file']);
        try{
            $clusterOk=$this->check('pve.cluster',function()use($driver,$pve,&$secret,$expect):bool{
                $s=$driver->cluster($pve,$secret)->connection['cluster_discovery'];$node=array_column($s['nodes'],null,'name')[$expect['node']]??null;
                return $node!==null&&$node['status']==='online'&&$node['details_state']==='available'&&!$s['reported_node_count_mismatch'];
            });
            if($clusterOk){
                $this->check('pve.guest',function()use($driver,$pve,&$secret,$expect):bool{
                    $s=$driver->guests($pve,$secret)->connection['guest_discovery'];$g=array_column($s['guests'],null,'external_id')[$expect['guest']]??null;
                    return $g!==null&&$g['node']===$expect['node']&&$g['config_state']==='available'&&$g['runtime_state']==='available';
                });
                $this->check('pve.storage_network',function()use($driver,$pve,&$secret,$expect):bool{
                    $s=$driver->storageNetwork($pve,$secret)->connection['storage_network_discovery'];
                    foreach($s['nodes'] as $n){
                        if($n['node']!==$expect['node'])continue;
                        if($n['storage_state']!=='available'||$n['network_state']!=='available')return false;
                        $stores=array_column($n['storages'],null,'storage');$store=$stores[$expect['storage']]??null;
                        if($store===null||$store['volume_state']!=='available'||!in_array($expect['bridge'],array_column($n['interfaces'],'iface'),true))return false;
                        $g=array_column($s['guests'],null,'guest_id')[$expect['guest']]??null;
                        return $g!==null&&$g['node']===$expect['node']&&$g['state']==='available'
                            &&array_filter($g['disks'],fn($d)=>($d['storage']??null)===$expect['storage']&&$d['volume_resolved'])!==[]
                            &&array_filter($g['nics'],fn($nic)=>($nic['bridge']??null)===$expect['bridge']&&$nic['bridge_resolved'])!==[];
                    }return false;
                });
                $this->check('pve.tasks',function()use($driver,$pve,&$secret,$expect):bool{
                    $s=$driver->tasks($pve,$secret,[])->connection['task_discovery'];$n=((array)$s['nodes'])[$expect['node']]??null;
                    return $n!==null&&!$s['rate_limited']&&in_array($n['archive'],['available','catching_up'],true)&&in_array($n['active'],['available','catching_up'],true)&&$n['head']==='available';
                });
                $this->check('pve.snapshots',function()use($driver,$pve,&$secret,$expect):bool{
                    $r=$driver->guestAccess($pve,$expect['guest'],'snapshots',$secret);return $r['node']===$expect['node']&&is_array($r['snapshots']);
                });
                $this->check('pve.console',function()use($driver,$pve,&$secret,$expect):bool{
                    $r=$driver->guestAccess($pve,$expect['guest'],'console',$secret);return $r['node']===$expect['node']&&str_starts_with($r['url'],'https://');
                });
            }else foreach(['guest','storage_network','tasks','snapshots','console'] as $name)$this->checks[]=['name'=>'pve.'.$name,'ok'=>false,'error'=>'prerequisite_failed'];
        }finally{sodium_memzero($secret);}
        $backup=new PbsConnector($transport,$config['pbs']['ca_file']);$secret=self::secret($config['pbs']['secret_file']);
        try{
            $s=null;$ok=$this->check('pbs.discovery',function()use($backup,$pbs,&$secret,&$s):bool{$s=$backup->execute($pbs,'inspect',$secret)->connection['pbs_discovery'];return true;});
            if($ok){
                $this->check('pbs.datastore',fn()=>isset(array_column($s['datastores'],null,'store')[$expect['datastore']])&&array_column($s['datastores'],null,'store')[$expect['datastore']]['capacity_visible']);
                $match=static fn($r)=>$r['store']===$expect['datastore']&&$r['namespace']===$expect['namespace']&&$r['backup_type']===$expect['backup_type']&&$r['backup_id']===$expect['backup_id'];
                $this->check('pbs.backup',fn()=>array_filter($s['groups'],$match)!==[]&&array_filter($s['snapshots'],$match)!==[]);
                $this->check('pbs.jobs',fn()=>array_diff($expect['job_kinds'],array_column(array_filter($s['jobs'],fn($j)=>$j['store']===$expect['datastore']),'kind'))===[]);
                $this->check('pbs.coverage',fn()=>$s['complete']===true);
            }else foreach(['datastore','backup','jobs','coverage'] as $name)$this->checks[]=['name'=>'pbs.'.$name,'ok'=>false,'error'=>'prerequisite_failed'];
        }finally{sodium_memzero($secret);}
        return ['ok'=>!in_array(false,array_column($this->checks,'ok'),true),'mode'=>'read_only_expected_resources','checks'=>$this->checks];
    }
}
