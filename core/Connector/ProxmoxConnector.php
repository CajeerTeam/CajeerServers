<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\CredentialProfile;
use RuntimeException;

/** Read-only Proxmox VE API client. Credentials never leave this server except over verified TLS. */
final class ProxmoxConnector implements ConnectorInterface, ConnectorActionDriver, \Cajeer\Servers\Metrics\MetricDriver
{
    private const MAX_RESPONSE = 2_097_152;
    private const MAX_NODES = 256;
    private const MAX_GUESTS = 1500;

    /** Optional transport is for isolated real-response tests; production uses verified HTTPS streams. */
    public function __construct(private readonly ?\Closure $transport = null, private readonly ?string $caFile = null, private readonly ?HttpsTransport $https = null) {}

    public function manifest(): ConnectorManifest
    {
        return new ConnectorManifest('proxmox','api',['proxmox_api','api_token'],
            ['inspect','sync','validate_rotation','health'],['hypervisor_node','virtual_machine','container'],'https');
    }

    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret): ConnectorResult
    {
        $manifest=$this->manifest();
        if ($context->provider!==$manifest->provider || !$manifest->supports($operation) || !$manifest->accepts($context->credentialType,'api'))
            throw new RuntimeException('Unsupported Proxmox operation or credential.');
        if ($operation==='health') {
            $authorization=self::authorization($context->credentialType,$secret);
            try { $nodes=$this->request($context->host,$context->port,'/api2/json/nodes',$authorization,$this->caFile); }
            finally { sodium_memzero($authorization); }
            $items=self::observations($nodes,[]);
        } else {
            $items=$this->discover($context->host,$context->port,$context->credentialType,$secret,$this->caFile);
        }
        if (in_array($operation,['health','validate_rotation'],true) && !array_filter($items,static fn(array $i):bool=>$i['type']==='hypervisor_node'))
            throw new RuntimeException('Remote API returned no Proxmox nodes.');
        // Full means a successful visible-inventory read, not proof of cluster-wide ACL visibility.
        $complete=$operation!=='health' && $items!==[] && count(array_filter($items,static fn(array $i):bool=>$i['type']==='hypervisor_node'))>0;
        return new ConnectorResult($items,$complete);
    }

    public function metrics(ConnectorContext $context,#[\SensitiveParameter] string $secret):array
    {
        $auth=self::authorization($context->credentialType,$secret);
        try {$nodes=$this->request($context->host,$context->port,'/api2/json/nodes',$auth,$this->caFile);$guests=$this->request($context->host,$context->port,'/api2/json/cluster/resources?type=vm',$auth,$this->caFile);}
        finally{sodium_memzero($auth);}
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
        $items=$this->discover($context->host,$context->port,$context->credentialType,$secret,$this->caFile);
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
        $auth=self::authorization($context->credentialType,$secret);
        try {
            $path='/api2/json/nodes/'.$node.'/qemu/'.$id.'/status/'.($action==='vm.start'?'start':'shutdown');
            $body=($this->https??new HttpsTransport())->powerPost($context->host,$context->port,$path,$auth,$this->caFile);
            $task=HttpsTransport::jsonObject($body)->data??null;
            $task=self::taskId($task,$node);
            $parts=explode(':',$task);
            if ($parts[5]!==($action==='vm.start'?'qmstart':'qmshutdown') || $parts[6]!== (string)$id) throw new RuntimeException('Remote task does not match requested action.');
            return $task;
        } finally { sodium_memzero($auth); }
    }
    private static function taskId(mixed $task,string $node):string
    {
        if (!is_string($task)||strlen($task)>512||!preg_match('/^UPID:'.preg_quote($node,'/').':[A-Fa-f0-9]+:[A-Fa-f0-9]+:[A-Fa-f0-9]+:[A-Za-z0-9_-]+:[A-Za-z0-9_.@!-]*:[A-Za-z0-9_.@!-]+:$/D',$task)) throw new RuntimeException('Invalid remote task identity.');
        return $task;
    }
    public function actionStatus(ConnectorContext $context,string $node,string $task,#[\SensitiveParameter] string $secret): string
    {
        $node=self::node($node);self::taskId($task,$node);
        $auth=self::authorization($context->credentialType,$secret);
        try {
            $body=($this->https??new HttpsTransport())->get($context->host,$context->port,'/api2/json/nodes/'.$node.'/tasks/'.rawurlencode($task).'/status',$auth,$this->caFile);
            $data=HttpsTransport::jsonObject($body)->data??null;
            if (!$data instanceof \stdClass) throw new RuntimeException('Invalid task status.');
            return match($data->status??null) {
                'running'=>'submitted',
                'stopped'=>is_string($data->exitstatus??null)&&$data->exitstatus!==''?($data->exitstatus==='OK'?'succeeded':'failed'):throw new RuntimeException('Missing task result.'),
                default=>throw new RuntimeException('Invalid task state.'),
            };
        } finally { sodium_memzero($auth); }
    }

    /** @return list<array{type:string,external_id:string,fields:array<string,string>}> */
    public function discover(string $host, int $port, string $credentialType, #[\SensitiveParameter] string $plaintext, ?string $caFile = null): array
    {
        $host = ConnectorBindingService::validateHost($host);
        $port = ConnectorBindingService::validatePort($port);
        $authorization = self::authorization($credentialType, $plaintext);
        try {
            $nodes = $this->request($host, $port, '/api2/json/nodes', $authorization, $caFile);
            $guests = $this->request($host, $port, '/api2/json/cluster/resources?type=vm', $authorization, $caFile);
        } finally {
            sodium_memzero($authorization);
        }
        return self::observations($nodes, $guests);
    }

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
    private static function authorization(string $type, #[\SensitiveParameter] string $plaintext): string
    {
        $value = CredentialProfile::decodeForInternalUse($type,$plaintext);
        if ($type==='proxmox_api' && is_array($value)) {
            $tokenId=$value['token_id']; $tokenSecret=$value['token_secret'];
        } elseif ($type==='api_token' && is_string($value) && str_contains($value,'=')) {
            [$tokenId,$tokenSecret]=explode('=',$value,2);
        } else {
            throw new RuntimeException('Proxmox API credentials required.');
        }
        if (preg_match('/^[a-zA-Z0-9._@-]+![a-zA-Z0-9._-]{1,128}$/D',$tokenId)!==1
            || $tokenSecret==='' || strlen($tokenSecret)>8192 || preg_match('/[\x00-\x20\x7f]/',$tokenSecret)) {
            throw new RuntimeException('Invalid Proxmox token format.');
        }
        return 'PVEAPIToken='.$tokenId.'='.$tokenSecret;
    }

    /** @return list<array<string,mixed>> */
    private function request(string $host,int $port,string $path,#[\SensitiveParameter] string $authorization,?string $caFile):array
    {
        $endpoint='https://'.(str_contains($host,':')?'['.$host.']':$host).':'.$port.$path;
        if ($this->transport!==null) {
            $body=($this->transport)($endpoint,$authorization,$caFile);
        } else {
            $body=($this->https??new HttpsTransport())->get($host,$port,$path,$authorization,$caFile);
        }
        if (!is_string($body) || strlen($body)>self::MAX_RESPONSE) throw new RuntimeException('Invalid remote response size.');
        $decoded=HttpsTransport::jsonObject($body);
        if (!isset($decoded->data) || !is_array($decoded->data)) {
            throw new RuntimeException('Unexpected Proxmox API response.');
        }
        return array_map(static function(mixed $row):array {
            if (!$row instanceof \stdClass) throw new ConnectorTransportException('connector_protocol_error');
            return (array)$row;
        },$decoded->data);
    }
}
