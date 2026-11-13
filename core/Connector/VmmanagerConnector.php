<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\CredentialProfile;
use RuntimeException;

/** VMmanager 6 Hosting/Infrastructure, VM API v3, read-only token authentication. */
final class VmmanagerConnector implements ConnectorInterface, \Cajeer\Servers\Metrics\MetricDriver
{
    private readonly VmmanagerApiClient $api;
    private const PAGE = 100;
    private const LIMIT = 2000;
    private const DEADLINE = 60;
    private const MAX_PAGES = 64;

    public function __construct(private readonly HttpsTransport $https, private readonly ?string $caFile = null, private readonly bool $requireProfile = false) { $this->api=new VmmanagerApiClient($https,$caFile); }

    public function manifest(): ConnectorManifest
    {
        return new ConnectorManifest('vmmanager','api',['vmmanager_api','api_token'],
            ['inspect','sync','validate_rotation','health'],['hypervisor_node','virtual_machine','container'],'https');
    }

    public function execute(ConnectorContext $context, string $operation, #[\SensitiveParameter] string $secret): ConnectorResult
    {
        $manifest = $this->manifest();
        if ($context->provider !== $manifest->provider || !$manifest->supports($operation) || !$manifest->accepts($context->credentialType,'api')) {
            throw new RuntimeException('Unsupported VMmanager operation or credential.');
        }
        if($this->requireProfile && $context->vmmanagerProfile===null) throw new ConnectorTransportException('connector_trust_failed');
        $decoded = CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        $token = is_array($decoded) ? $decoded['token'] : $decoded;
        $expiresAt=is_array($decoded)?($decoded['expires_at']??null):null;
        unset($decoded);
        try {
            if (strlen($token)<8 || strlen($token)>8192 || preg_match('/[^\x21-\x7e]/D',$token)) {
                throw new RuntimeException('Invalid VMmanager token format.');
            }
            $deadline = microtime(true)+self::DEADLINE;
            if($expiresAt!==null){
                $expiry=(new \DateTimeImmutable($expiresAt))->getTimestamp();
                if($expiry<=time()) throw new ConnectorTransportException('connector_auth_failed');
                $deadline=min($deadline,(float)$expiry);
            }
            $profile=$context->vmmanagerProfile;
            $connection=['provider'=>'vmmanager','api_version'=>'v3','endpoint_host'=>$context->host,'endpoint_port'=>$context->port,'tls_pinned'=>$profile!==null,'identity_verified'=>false];
            $anchor=[];
            if($profile!==null){
                $anchor=$this->anchor($context,$token,$deadline);
                $connection['identity_verified']=true;
                $connection['anchor_id']=$profile['anchor_id'];$connection['anchor_node']=$profile['anchor_node'];
            }
            $identity=$this->api->identity($context,$token,$deadline);
            $connection['authentication']=$identity+['status'=>'verified','api_version'=>'v4','expiry_source'=>$expiresAt===null?'unknown':'configured','expires_at'=>$expiresAt];
            if ($operation==='health') {
                $page=$profile!==null?$anchor:$this->page($context,'node',0,1,$token,$deadline);
                if ($page===[]) throw new RuntimeException('VMmanager returned no visible nodes.');
                $connection['capabilities']=$this->api->capabilities($context,$token,$deadline);
                $connection['action_capabilities']=$this->api->actionCapabilities($context,$token,$deadline,$identity);
                if($profile!==null) $this->anchor($context,$token,$deadline);
                if($this->api->identity($context,$token,$deadline)!==$identity) throw new ConnectorTransportException('connector_auth_failed');
                return new ConnectorResult(self::observations($page,[]),false,connection:$connection);
            }
            $nodes=$this->collect($context,'node',$token,$deadline,self::LIMIT);
            if ($nodes===[]) throw new RuntimeException('VMmanager returned no visible nodes.');
            $hosts=$this->collect($context,'host',$token,$deadline,self::LIMIT-count($nodes));
            if($profile!==null){
                VmmanagerProfile::verify($profile,array_values(array_filter($nodes,static fn($row)=>$row['id']===$profile['anchor_id'])));
                $this->anchor($context,$token,$deadline);
            }
            if($this->api->identity($context,$token,$deadline)!==$identity) throw new ConnectorTransportException('connector_auth_failed');
            // A scoped user may see only a subset. Never use such a snapshot to mark other resources missing.
            return new ConnectorResult(self::observations($nodes,$hosts),$identity['administrator']&&$identity['owner_id']===$identity['trustee_id'],connection:$connection);
        } finally { sodium_memzero($token); }
    }

    public function metrics(ConnectorContext $context,#[\SensitiveParameter] string $secret):array
    {
        // The supported inventory API exposes state; use observed availability, not invented utilization.
        $snapshot=$this->execute($context,'inspect',$secret);$out=[];
        foreach($snapshot->items as $item){$state=$item['fields']['status'];if(in_array($state,['online','offline'],true))$out[]=\Cajeer\Servers\Metrics\MetricBatch::sample('vmmanager.resource.up',$state==='online'?1:0,$item['external_id'],[],'gauge','boolean');}
        return $out;
    }

    private function anchor(ConnectorContext $context,#[\SensitiveParameter] string $token,float $deadline): array
    {
        $profile=$context->vmmanagerProfile;
        $rows=$this->page($context,'node',$profile['anchor_id']-1,1,$token,$deadline,true);
        VmmanagerProfile::verify($profile,$rows);
        return $rows;
    }

    /** Keyset pagination avoids shifting offsets on inserts/deletes and detects ignored filters.
     * Exhaustion must be observed explicitly; a short page is not proof of completion. */
    private function collect(ConnectorContext $context,string $kind,#[\SensitiveParameter] string $token,float $deadline,int $limit):array
    {
        $rows=[]; $last=0;
        // Also bound servers that return tiny pages or ignore the requested LIMIT.
        for ($page=0;$page<self::MAX_PAGES;$page++) {
            $batch=$this->page($context,$kind,$last,self::PAGE,$token,$deadline);
            if ($batch===[]) return $rows;
            foreach ($batch as $row) {
                $last=self::id($row['id']??null);
                $rows[]=$row;
                if (count($rows)>$limit) throw new RuntimeException('VMmanager inventory exceeds safe import limit.');
            }
        }
        throw new RuntimeException('VMmanager pagination did not terminate.');
    }

    private function page(ConnectorContext $context,string $kind,int $after,int $limit,#[\SensitiveParameter] string $token,float $deadline,bool $strictName=false):array
    {
        $batch=$this->api->page($context,$kind==='node'?'nodes':'guests',$after,$limit,$token,$deadline);
        $rows=[];
        foreach ($batch as $row) {
            $id=self::id($row->id??null);
            // Keep only the fields consumed by the mapper; discard bulky or sensitive remote metadata.
            if($strictName && (!is_string($row->name??null)||self::name($row->name,'')!==$row->name||$row->name==='')) throw new ConnectorTransportException('connector_trust_failed');
            $state=$row->state??null;
            $clean=['id'=>$id,'name'=>self::name($row->name??null,($kind==='node'?'Node ':'Host ').$id),
                'state'=>is_bool($state)||(is_string($state)&&strlen($state)<=32)?$state:null];
            if ($kind==='node') {
                $clean['maintenance_mode']=($row->maintenance_mode??false)===true;
            } else {
                $clean['node']=self::id(($row->node??null) instanceof \stdClass?($row->node->id??null):($row->node??null));
                $type=($row->cluster??null) instanceof \stdClass?($row->cluster->virtualization_type??null):null;
                if (!in_array($type,['kvm','lxd'],true)) throw new RuntimeException('Unsupported VMmanager virtualization type.');
                $clean['cluster']=['virtualization_type'=>$type];
            }
            $rows[]=$clean;
        }
        return $rows;
    }

    public static function observations(array $nodes,array $hosts):array
    {
        if (count($nodes)+count($hosts)>self::LIMIT) throw new RuntimeException('VMmanager inventory exceeds safe import limit.');
        $items=[];
        foreach ($nodes as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid VMmanager node.');
            $id=self::id($row['id']??null); $key='node:'.$id;
            if (isset($items[$key])) throw new RuntimeException('Duplicate VMmanager node.');
            $status=($row['maintenance_mode']??false)===true?'maintenance':match($row['state']??null){
                'active','online','running'=>'online', 'offline','inactive','unreachable'=>'offline', default=>'unknown',
            };
            $items[$key]=['type'=>'hypervisor_node','external_id'=>$key,'fields'=>['name'=>self::name($row['name']??null,'Node '.$id),'status'=>$status]];
        }
        foreach ($hosts as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid VMmanager host.');
            $id=self::id($row['id']??null); $key='host:'.$id;
            if (isset($items[$key])) throw new RuntimeException('Duplicate VMmanager host.');
            $node=$row['node']??null;
            $parent='node:'.self::id(is_array($node)?($node['id']??null):$node);
            if (!isset($items[$parent])) throw new RuntimeException('VMmanager host node missing from snapshot.');
            // The v3 list embeds cluster.virtualization_type; LXD hosts are containers.
            $cluster=$row['cluster']??null;
            $virtualization=is_array($cluster)?($cluster['virtualization_type']??null):null;
            $type=match($virtualization){'kvm'=>'virtual_machine','lxd'=>'container',default=>throw new RuntimeException('Unsupported VMmanager virtualization type.')};
            $status=match($row['state']??null){true,'running','online'=>'online',false,'stopped','offline'=>'offline',default=>'unknown'};
            $items[$key]=['type'=>$type,'external_id'=>$key,'parent_external_id'=>$parent,
                'fields'=>['name'=>self::name($row['name']??null,'Host '.$id),'status'=>$status]];
        }
        return array_values($items);
    }

    private static function id(mixed $value):int
    {
        if ((!is_int($value) && !(is_string($value)&&preg_match('/^[1-9][0-9]{0,9}$/D',$value))) || (int)$value<1 || (int)$value>2147483647) {
            throw new RuntimeException('Invalid VMmanager resource ID.');
        }
        return (int)$value;
    }
    private static function name(mixed $value,string $fallback):string
    {
        if (!is_string($value)) return $fallback;
        $value=trim($value);
        return $value!=='' && strlen($value)<=255 && preg_match('//u',$value) && !preg_match('/[\x00-\x1f\x7f]/',$value)?$value:$fallback;
    }
}
