<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use RuntimeException;

/** Proxmox VE driver using the shared verified API client. */
final class ProxmoxConnector implements ConnectorInterface, ConnectorActionDriver, \Cajeer\Servers\Metrics\MetricDriver
{
    private const MAX_NODES = 256;
    private const MAX_GUESTS = 1500;

    /** Optional transport is for isolated real-response tests; production uses verified HTTPS streams. */
    public function __construct(private readonly ?\Closure $transport = null, private readonly ?string $caFile = null, private readonly ?HttpsTransport $https = null, private readonly bool $requireProfile=false) {}

    public function manifest(): ConnectorManifest
    {
        return new ConnectorManifest('proxmox','api',['proxmox_api','api_token'],
            ['inspect','sync','validate_rotation','health'],['hypervisor_node','virtual_machine','container','cluster'],'https');
    }

    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret): ConnectorResult
    {
        $manifest=$this->manifest();
        if ($context->provider!==$manifest->provider || !$manifest->supports($operation) || !$manifest->accepts($context->credentialType,'api'))
            throw new RuntimeException('Unsupported Proxmox operation or credential.');
        [$items,$info]=$this->client()->read($context,$secret,static function(callable $get,array $info)use($operation):array {
            $nodes=ProxmoxApiClient::rows($get('/api2/json/nodes'));
            $guests=$operation==='health'?[]:ProxmoxApiClient::rows($get('/api2/json/cluster/resources?type=vm'));
            return [self::observations($nodes,$guests),$info];
        },$operation==='validate_rotation',$operation==='health');
        if (in_array($operation,['health','validate_rotation'],true) && !array_filter($items,static fn(array $i):bool=>$i['type']==='hypervisor_node'))
            throw new RuntimeException('Remote API returned no Proxmox nodes.');
        // PVE ACLs may hide guests even on legacy targets. Never infer absence from list success.
        return new ConnectorResult($items,false,[],$info);
    }

    /** Named cluster collector, available only with an identity-checked connection profile. */
    public function cluster(ConnectorContext $context,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if($context->provider!=='proxmox'||$context->proxmoxProfile===null)throw new ConnectorTransportException('connector_trust_failed');
        $deadline=ProxmoxClusterDiscovery::deadline();
        return $this->client()->read($context,$secret,static fn(callable $get,array $info):ConnectorResult=>ProxmoxClusterDiscovery::collect($get,$info,$deadline),false,false,true);
    }

    /** Detailed, bounded QEMU/LXC collection requires the verified PVE profile. */
    public function guests(ConnectorContext $context,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if($context->provider!=='proxmox'||$context->proxmoxProfile===null)throw new ConnectorTransportException('connector_trust_failed');
        $deadline=ProxmoxGuestDiscovery::deadline();
        return $this->client()->read($context,$secret,static fn(callable $get,array $info):ConnectorResult=>ProxmoxGuestDiscovery::collect($get,$info,$deadline),false,false,true);
    }

    /** Storage and network discovery requires the verified PVE profile. */
    public function storageNetwork(ConnectorContext $context,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if($context->provider!=='proxmox'||$context->proxmoxProfile===null)throw new ConnectorTransportException('connector_trust_failed');
        $deadline=ProxmoxStorageNetworkDiscovery::deadline();
        return $this->client()->read($context,$secret,static fn(callable $get,array $info):ConnectorResult=>ProxmoxStorageNetworkDiscovery::collect($get,$info,$deadline),false,false,true);
    }

    /** Read-only task history, using the same verified endpoint for each attempt. */
    public function tasks(ConnectorContext $context,#[\SensitiveParameter] string $secret,array $previous):ConnectorResult
    {
        if($context->provider!=='proxmox'||$context->proxmoxProfile===null)throw new ConnectorTransportException('connector_trust_failed');
        $now=time();$deadline=hrtime(true)+60_000_000_000;
        return $this->client()->read($context,$secret,static fn(callable $get,array $info):ConnectorResult=>ProxmoxTaskDiscovery::collect($get,$info,$previous,$now,$deadline),false,false,true);
    }

    public function metrics(ConnectorContext $context,#[\SensitiveParameter] string $secret):array
    {
        [$nodes,$guests]=$this->client()->read($context,$secret,static fn(callable $get)=>[ProxmoxApiClient::rows($get('/api2/json/nodes')),ProxmoxApiClient::rows($get('/api2/json/cluster/resources?type=vm'))]);
        self::observations($nodes,$guests);$out=[];
        foreach([...$nodes,...$guests] as $r){$id=isset($r['vmid'])?$r['type'].':'.$r['vmid']:'node:'.$r['node'];
            foreach(['cpu'=>['cpu.utilization','ratio','gauge'],'mem'=>['memory.used','bytes','gauge'],'maxmem'=>['memory.total','bytes','gauge'],'disk'=>['disk.used','bytes','gauge'],'maxdisk'=>['disk.total','bytes','gauge'],'uptime'=>['uptime','seconds','gauge'],'netin'=>['network.receive','bytes','counter'],'netout'=>['network.transmit','bytes','counter'],'diskread'=>['disk.read','bytes','counter'],'diskwrite'=>['disk.write','bytes','counter']] as $key=>[$name,$unit,$kind]) {
                if(!isset($r[$key]))continue;$v=$r[$key];if(!is_numeric($v)||(float)$v<0||($key==='cpu'&&(float)$v>1))throw new ConnectorTransportException('connector_protocol_error');
                $out[]=\Cajeer\Servers\Metrics\MetricBatch::sample('proxmox.'.$name,$v,$id,[],$kind,$unit);
            }
        }
        return $out;
    }

    public function actions(): array { return ProxmoxSnapshotPolicy::ALL_ACTIONS; }

    /** Resolve live location and verify the resource before any durable dispatch. */
    public function prepareAction(ConnectorContext $context,string $action,string $externalId,#[\SensitiveParameter] string $secret): array
    {
        if ($context->provider!=='proxmox'||!ProxmoxPowerPolicy::matches($action,$externalId)) throw new RuntimeException('Unsupported guest action.');
        if($context->proxmoxProfile===null) throw new \Cajeer\Servers\Http\Exception\HttpException(409,'A verified Proxmox profile is required for power actions.','connector_trust_failed');
        return $this->client()->read($context,$secret,fn(callable $get,array $info):array=>$this->liveAction($get,$info,$action,$externalId),true);
    }
    private function liveAction(callable $get,array $info,string $action,string $externalId,?string $snapshot=null,?string $revision=null):array
    {
        if(($info['quorate']??null)===false)ProxmoxPowerPolicy::deny('guest_quorum','Proxmox cluster has no quorum.');
        [$type,$id]=explode(':',$externalId);$id=(int)$id;
        $nodes=ProxmoxApiClient::rows($get('/api2/json/nodes'));
        $guests=ProxmoxApiClient::rows($get('/api2/json/cluster/resources?type=vm'));
        $items=self::observations($nodes,$guests);$node=null;$matches=0;
        foreach($items as $item)if(in_array($item['external_id'],['qemu:'.$id,'lxc:'.$id],true)){
            $matches++;if($item['external_id']===$externalId)$node=self::node(substr($item['parent_external_id'],5));
        }
        if($matches!==1||$node===null)ProxmoxPowerPolicy::deny('guest_identity_conflict','Guest identity is not unique and visible in the cluster.');
        if(!in_array($node,array_column(array_filter($nodes,static fn($r)=>($r['status']??null)==='online'),'node'),true))ProxmoxPowerPolicy::deny('guest_node_offline','Guest node is not online.');
        $base='/api2/json/nodes/'.$node.'/'.$type.'/'.$id;
        $config=$get($base.'/config');$status=$get($base.'/status/current');
        // Cluster PVE status must reveal HA ownership; standalone has no HA services.
        if(($info['cluster']??null)!==null&&!isset($status->ha))ProxmoxPowerPolicy::deny('guest_ha_managed','HA ownership could not be verified.');
        $snap=ProxmoxSnapshotPolicy::matches($action,$externalId);
        $policyAction=$snap?($type==='qemu'?'vm.':'lxc.').(($status->status??null)==='stopped'?'start':'shutdown'):$action;
        $evidence=ProxmoxPowerPolicy::inspect($policyAction,$type,$id,$config,$status);
        if($snap){
            if(!ProxmoxSnapshotPolicy::name($snapshot))throw new \InvalidArgumentException('Invalid snapshot name.');
            $rows=ProxmoxSnapshotPolicy::rows($get($base.'/snapshot'));
            ProxmoxSnapshotPolicy::check($action,$snapshot,$revision,$rows,$evidence['state']);
            if(ProxmoxSnapshotPolicy::op($action)==='rollback'&&$type==='qemu'){
                $saved=$get($base.'/snapshot/'.$snapshot.'/config');
                if(!$saved instanceof \stdClass||!empty($saved->vmstate))ProxmoxPowerPolicy::deny('snapshot_ram_state','RAM snapshots cannot be rolled back by this dispatcher.');
            }
            $evidence+=['snapshot'=>$snapshot,'snapshot_revision'=>$revision,'snapshot_tree'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
        }
        return ['node'=>$node,'vmid'=>$id,'type'=>$type]+$evidence;
    }
    public function dispatchAction(ConnectorContext $context,string $action,array $resource,#[\SensitiveParameter] string $secret): string
    {
        $node=self::node($resource['node']??null);$id=$resource['vmid']??null;$type=$resource['type']??null;
        if ($context->provider!=='proxmox'||$context->proxmoxProfile===null||!is_int($id)||!is_string($type)||!(ProxmoxPowerPolicy::matches($action,$type.':'.$id)||ProxmoxSnapshotPolicy::matches($action,$type.':'.$id))) throw new RuntimeException('Invalid guest action.');
        if(ProxmoxSnapshotPolicy::matches($action,$type.':'.$id))return $this->dispatchSnapshot($context,$action,$resource,$secret);
        $op=ProxmoxPowerPolicy::operation($action);$path='/api2/json/nodes/'.$node.'/'.$type.'/'.$id.'/status/'.$op;
        $task=self::taskId($this->client()->power($context,$path,$secret,function(callable $get,array $info)use($resource,$action,$type,$id):void {
            $fresh=$this->liveAction($get,$info,$action,$type.':'.$id);
            if($fresh!==$resource)ProxmoxPowerPolicy::deny('guest_state_conflict','Guest location, configuration or state changed before dispatch.');
        }),$node);
        $parts=explode(':',$task);
        if ($parts[5]!==($type==='qemu'?'qm':'vz').$op || $parts[6]!== (string)$id) throw new RuntimeException('Remote task does not match requested action.');
        return $task;
    }
    public function prepareSnapshot(ConnectorContext $context,string $action,string $external,string $name,?string $revision,#[\SensitiveParameter] string $secret):array
    {
        if($context->provider!=='proxmox'||$context->proxmoxProfile===null||!ProxmoxSnapshotPolicy::matches($action,$external))throw new RuntimeException('Invalid snapshot action.');
        return $this->client()->read($context,$secret,fn(callable $get,array $info)=>$this->liveAction($get,$info,$action,$external,$name,$revision),true);
    }
    private function dispatchSnapshot(ConnectorContext $ctx,string $action,array $resource,#[\SensitiveParameter] string $secret):string
    {
        $node=$resource['node'];$type=$resource['type'];$id=$resource['vmid'];$op=ProxmoxSnapshotPolicy::op($action);
        $task=self::taskId($this->client()->snapshot($ctx,"/api2/json/nodes/$node/$type/$id/snapshot",$op,$resource['snapshot'],$secret,function(callable $get,array $info)use($resource,$action,$type,$id):void {
            if($this->liveAction($get,$info,$action,$type.':'.$id,$resource['snapshot'],$resource['snapshot_revision'])!==$resource)ProxmoxPowerPolicy::deny('snapshot_conflict','Guest or snapshot tree changed before dispatch.');
        }),$node);
        $parts=explode(':',$task);$worker=($type==='qemu'?'qm':'vz').match($op){'create'=>'snapshot','delete'=>'delsnapshot','rollback'=>'rollback'};
        if($parts[5]!==$worker||$parts[6]!== (string)$id)throw new RuntimeException('Remote task does not match snapshot action.');
        return $task;
    }
    /** Live read for snapshot inventory and native console hand-off, no remote mutation. */
    public function guestAccess(ConnectorContext $ctx,string $external,string $purpose,#[\SensitiveParameter] string $secret):array
    {
        if($ctx->provider!=='proxmox'||$ctx->proxmoxProfile===null||!preg_match('/^(qemu|lxc):[1-9][0-9]{0,8}$/D',$external)||!in_array($purpose,['snapshots','console'],true))throw new RuntimeException('Invalid guest access.');
        return $this->client()->read($ctx,$secret,function(callable $get,array $info)use($ctx,$external,$purpose):array {
            [$type,$id]=explode(':',$external);$id=(int)$id;
            $nodes=ProxmoxApiClient::rows($get('/api2/json/nodes'));$items=self::observations($nodes,ProxmoxApiClient::rows($get('/api2/json/cluster/resources?type=vm')));
            $node=null;$count=0;foreach($items as $item)if(in_array($item['external_id'],['qemu:'.$id,'lxc:'.$id],true)){$count++;if($item['external_id']===$external)$node=self::node(substr($item['parent_external_id'],5));}
            if($count!==1||$node===null||!in_array($node,array_column(array_filter($nodes,static fn($r)=>($r['status']??null)==='online'),'node'),true))ProxmoxPowerPolicy::deny('resource_unavailable','Guest is not uniquely visible on an online node.');
            $base="/api2/json/nodes/$node/$type/$id";$config=$get($base.'/config');if(!$config instanceof \stdClass)throw new ConnectorTransportException('connector_protocol_error');
            $remote=['node'=>$node,'type'=>$type,'vmid'=>$id,'fingerprint'=>ProxmoxGuestDiscovery::config($config,$type)['identity_fingerprint']??null];
            if($purpose==='snapshots')return $remote+['snapshots'=>ProxmoxSnapshotPolicy::rows($get($base.'/snapshot'))];
            return $remote+['url'=>ProxmoxSnapshotPolicy::consoleUrl($ctx,$node,$type,$id)];
        },true);
    }
    private static function taskId(mixed $task,string $node):string
    {
        if (!is_string($task)||strlen($task)>512||!preg_match('/^UPID:'.preg_quote($node,'/').':[A-Fa-f0-9]+:[A-Fa-f0-9]+:[A-Fa-f0-9]+:[A-Za-z0-9_-]+:[A-Za-z0-9_.@!-]*:[A-Za-z0-9_.@!-]+:$/D',$task)) throw new RuntimeException('Invalid remote task identity.');
        $identity=ProxmoxTaskDiscovery::identity($task);
        if($identity['node']!==$node)throw new RuntimeException('Invalid remote task node.');
        return $task;
    }
    public function actionStatus(ConnectorContext $context,string $node,string $task,#[\SensitiveParameter] string $secret): string
    {
        $node=self::node($node);self::taskId($task,$node);
        return $this->client()->read($context,$secret,static function(callable $get)use($node,$task):string {
            $data=$get('/api2/json/nodes/'.$node.'/tasks/'.rawurlencode($task).'/status');
            if (!$data instanceof \stdClass||(isset($data->upid)&&$data->upid!==$task)||(isset($data->node)&&$data->node!==$node)) throw new RuntimeException('Invalid task status.');
            return match($data->status??null) {
                'running'=>'submitted',
                'stopped'=>is_string($data->exitstatus??null)&&$data->exitstatus!==''&&strtolower($data->exitstatus)!=='unknown'?($data->exitstatus==='OK'?'succeeded':'failed'):throw new RuntimeException('Missing task result.'),
                default=>throw new RuntimeException('Invalid task state.'),
            };
        });
    }

    /** @return list<array{type:string,external_id:string,fields:array<string,string>}> */
    public function discover(string $host, int $port, string $credentialType, #[\SensitiveParameter] string $plaintext, ?string $caFile = null): array
    {
        $ctx=new ConnectorContext('proxmox',$host,$port,$credentialType);
        return $this->client($caFile)->read($ctx,$plaintext,static fn(callable $get)=>self::observations(ProxmoxApiClient::rows($get('/api2/json/nodes')),ProxmoxApiClient::rows($get('/api2/json/cluster/resources?type=vm'))));
    }
    private function client(?string $caFile=null):ProxmoxApiClient
    {return new ProxmoxApiClient($this->https??new HttpsTransport(),$caFile??$this->caFile,$this->transport,$this->requireProfile);}

    /** @return list<array{type:string,external_id:string,fields:array<string,string>}> */
    public static function observations(array $nodes, array $guests): array
    {
        if (count($nodes) > self::MAX_NODES || count($guests) > self::MAX_GUESTS) {
            throw new RuntimeException('Remote inventory exceeds the safe import limit.');
        }
        $items = [];
        foreach ($nodes as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid Proxmox nodes response.');
            $node = self::node($row['node'] ?? null);
            $key = 'node:'.$node;
            if (isset($items[$key])) throw new RuntimeException('Duplicate remote node identity.');
            $items[$key] = ['type'=>'hypervisor_node','external_id'=>$key,
                'fields'=>['name'=>$node,'status'=>self::status($row['status']??null, false)]];
        }
        foreach ($guests as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid Proxmox guests response.');
            $type = $row['type'] ?? null;
            if (!in_array($type, ['qemu','lxc'], true)) throw new RuntimeException('Unsupported Proxmox guest type.');
            $node = self::node($row['node']??null);
            if (!isset($items['node:'.$node])) throw new RuntimeException('Guest node missing from discovery; refusing inconsistent snapshot.');
            $id = $row['vmid'] ?? null;
            if ((!is_int($id) && !(is_string($id) && preg_match('/^[1-9][0-9]{0,8}$/D',$id)))
                || (int)$id < 1 || (int)$id > 999999999) throw new RuntimeException('Invalid Proxmox guest ID.');
            $key = $type.':'.(int)$id; // VMIDs are unique across the cluster even if guests migrate nodes.
            if (isset($items[$key])) throw new RuntimeException('Duplicate remote guest identity.');
            $fallback = strtoupper($type).' '.(int)$id;
            $items[$key] = ['type'=>$type==='qemu'?'virtual_machine':'container','external_id'=>$key,'parent_external_id'=>'node:'.$node,
                'fields'=>['name'=>self::label($row['name']??null,$fallback),
                    'status'=>self::status($row['status']??null, true)]];
        }
        return array_values($items);
    }

    private static function node(mixed $value): string
    {
        if (!is_string($value) || strlen($value)>120 || preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9._-]*[a-zA-Z0-9])?$/D',$value)!==1)
            throw new RuntimeException('Invalid Proxmox node identity.');
        return $value;
    }
    private static function label(mixed $value, string $fallback): string
    {
        if (!is_string($value) || trim($value)==='') return $fallback;
        $value=trim($value);
        return strlen($value)<=255 && preg_match('//u',$value) && !preg_match('/[\x00-\x1f\x7f]/',$value) ? $value : $fallback;
    }
    private static function status(mixed $value, bool $guest): string
    {
        return match($value) {
            'online', 'running' => 'online',
            'offline', 'stopped' => 'offline',
            'paused', 'suspended' => $guest ? 'maintenance' : 'unknown',
            default => 'unknown',
        };
    }
}
