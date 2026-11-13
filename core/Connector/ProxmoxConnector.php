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

    public function actions(): array { return ['vm.start','vm.shutdown']; }

    /** Resolve the live node by cluster-wide VMID; never accept a node/path from HTTP input. */
    public function prepareAction(ConnectorContext $context,string $action,string $externalId,#[\SensitiveParameter] string $secret): array
    {
        if ($context->provider!=='proxmox'||!in_array($action,$this->actions(),true)||!preg_match('/^qemu:([1-9][0-9]{0,8})$/D',$externalId,$m)) throw new RuntimeException('Unsupported VM action.');
        $items=$this->client()->read($context,$secret,static function(callable $get,array $info):array {if(($info['quorate']??null)===false)throw new ConnectorTransportException('connector_unavailable');return self::observations(ProxmoxApiClient::rows($get('/api2/json/nodes')),ProxmoxApiClient::rows($get('/api2/json/cluster/resources?type=vm')));},true);
        foreach ($items as $item) if ($item['external_id']===$externalId) {
            $node=self::node(substr($item['parent_external_id'],5));
            return ['node'=>$node,'vmid'=>(int)$m[1]];
        }
        throw new RuntimeException('VM is not visible in current cluster.');
    }
    public function dispatchAction(ConnectorContext $context,string $action,array $resource,#[\SensitiveParameter] string $secret): string
    {
        $node=self::node($resource['node']??null);$id=$resource['vmid']??null;
        if ($context->provider!=='proxmox'||!is_int($id)||$id<1||$id>999999999||!in_array($action,$this->actions(),true)) throw new RuntimeException('Invalid power action.');
        $path='/api2/json/nodes/'.$node.'/qemu/'.$id.'/status/'.($action==='vm.start'?'start':'shutdown');
        $task=self::taskId($this->client()->power($context,$path,$secret),$node);
        $parts=explode(':',$task);
        if ($parts[5]!==($action==='vm.start'?'qmstart':'qmshutdown') || $parts[6]!== (string)$id) throw new RuntimeException('Remote task does not match requested action.');
        return $task;
    }
    private static function taskId(mixed $task,string $node):string
    {
        if (!is_string($task)||strlen($task)>512||!preg_match('/^UPID:'.preg_quote($node,'/').':[A-Fa-f0-9]+:[A-Fa-f0-9]+:[A-Fa-f0-9]+:[A-Za-z0-9_-]+:[A-Za-z0-9_.@!-]*:[A-Za-z0-9_.@!-]+:$/D',$task)) throw new RuntimeException('Invalid remote task identity.');
        return $task;
    }
    public function actionStatus(ConnectorContext $context,string $node,string $task,#[\SensitiveParameter] string $secret): string
    {
        $node=self::node($node);self::taskId($task,$node);
        return $this->client()->read($context,$secret,static function(callable $get)use($node,$task):string {
            $data=$get('/api2/json/nodes/'.$node.'/tasks/'.rawurlencode($task).'/status');
            if (!$data instanceof \stdClass) throw new RuntimeException('Invalid task status.');
            return match($data->status??null) {
                'running'=>'submitted',
                'stopped'=>is_string($data->exitstatus??null)&&$data->exitstatus!==''?($data->exitstatus==='OK'?'succeeded':'failed'):throw new RuntimeException('Missing task result.'),
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
