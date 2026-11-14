<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Credentials\CredentialProfile;
use RuntimeException;

/** VMmanager 6 Hosting/Infrastructure, VM API v3, scoped token authentication and confirmed power actions. */
final class VmmanagerConnector implements ConnectorInterface, ConnectorActionDriver, \Cajeer\Servers\Metrics\MetricDriver
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
            ['inspect','sync','validate_rotation','health'],['hypervisor_node','virtual_machine','container','cluster'],'https');
    }

    public function actions():array {return VmmanagerPowerPolicy::ACTIONS;}

    private function withActionToken(ConnectorContext $context,#[\SensitiveParameter] string $secret,callable $fn):mixed
    {
        if($context->provider!=='vmmanager'||$context->vmmanagerProfile===null||!$this->manifest()->accepts($context->credentialType,'api'))throw new ConnectorTransportException('connector_trust_failed');
        $decoded=CredentialProfile::decodeForInternalUse($context->credentialType,$secret);
        $token=is_array($decoded)?$decoded['token']:$decoded;$expiry=is_array($decoded)?($decoded['expires_at']??null):null;unset($decoded);
        try{
            $deadline=microtime(true)+self::DEADLINE;
            if($expiry!==null){$end=(new \DateTimeImmutable($expiry))->getTimestamp();if($end<=time())throw new ConnectorTransportException('connector_auth_failed');$deadline=min($deadline,(float)$end);}
            $this->anchor($context,$token,$deadline);$identity=$this->api->identity($context,$token,$deadline);
            // Only a non-delegated admin can prove visibility of competing tasks.
            if(!$identity['administrator']||$identity['owner_id']!==$identity['trustee_id'])throw new ConnectorTransportException('connector_auth_failed');
            return $fn($token,$deadline,$identity);
        }finally{sodium_memzero($token);}
    }
    private function liveGuest(ConnectorContext $context,int $id,#[\SensitiveParameter] string $token,float $deadline):array
    {
        $host=$this->api->exact($context,'guests',$id,$token,$deadline);
        $nodeRef=$host->node??null;$nodeId=VmmanagerApiClient::id($nodeRef instanceof \stdClass?($nodeRef->id??null):$nodeRef);
        $node=$this->api->exact($context,'nodes',$nodeId,$token,$deadline);
        [$details]=VmmanagerGuestDiscovery::build([$node],[$host],true);
        $guest=$details['guests'][0];
        // Require actual names, not discovery fallbacks, for mutation identity.
        if(!is_string($host->name??null)||$guest['name']!==$host->name)VmmanagerPowerPolicy::deny('guest_identity_conflict');
        return [$guest,$host,$node];
    }
    private function actionPreflight(ConnectorContext $context,string $action,string $external,#[\SensitiveParameter] string $token,float $deadline,array $identity):array
    {
        if(!VmmanagerPowerPolicy::matches($action,$external))throw new \InvalidArgumentException('Invalid VMmanager action.');
        $id=(int)substr($external,5);$op=VmmanagerPowerPolicy::operation($action);
        if(($this->api->actionCapabilities($context,$token,$deadline,$identity)[$op]??null)!=='advertised')VmmanagerPowerPolicy::deny('capability_unavailable');
        [$guest,$host,$node]=$this->liveGuest($context,$id,$token,$deadline);
        VmmanagerPowerPolicy::preflight($action,$guest,$host,$node);
        if($this->api->activeTasks($context,$id,$token,$deadline)!==[])VmmanagerPowerPolicy::deny('guest_task_pending');
        $this->anchor($context,$token,$deadline);
        if($this->api->identity($context,$token,$deadline)!==$identity)throw new ConnectorTransportException('connector_auth_failed');
        return ['node'=>(string)$guest['node_id'],'guest'=>$guest,'fingerprint'=>VmmanagerPowerPolicy::fingerprint($guest),'digest'=>hash('sha256',json_encode($guest,JSON_THROW_ON_ERROR)),'identity'=>$identity];
    }
    public function prepareAction(ConnectorContext $context,string $action,string $externalId,#[\SensitiveParameter] string $secret):array
    {
        return $this->withActionToken($context,$secret,fn($token,$deadline,$identity)=>$this->actionPreflight($context,$action,$externalId,$token,$deadline,$identity));
    }
    public function dispatchAction(ConnectorContext $context,string $action,array $resource,#[\SensitiveParameter] string $secret):string
    {
        $attempted=false;
        try{
            return $this->withActionToken($context,$secret,function($token,$deadline,$identity)use($context,$action,$resource,&$attempted):string{
                $fresh=$this->actionPreflight($context,$action,$resource['guest']['external_id'],$token,$deadline,$identity);
                if($fresh!==$resource)VmmanagerPowerPolicy::deny('guest_identity_conflict');
                // Everything after this boundary is ambiguous on failure: never retry a POST.
                $attempted=true;
                $response=$this->api->power($context,$resource['guest']['id'],VmmanagerPowerPolicy::operation($action),$token,$deadline);
                return VmmanagerPowerPolicy::receipt($action,$resource,$response);
            });
        }catch(\Throwable $e){if(!$attempted)throw new ConnectorActionNotSentException($e);throw $e;}
    }
    private function checkAction(ConnectorContext $context,string $node,string $task,#[\SensitiveParameter] string $token,float $deadline,array $identity):string
    {
        $receipt=VmmanagerPowerPolicy::parse($task);if($receipt['owner']!==$identity['owner_id'])throw new ConnectorTransportException('connector_auth_failed');$failed=false;$pending=false;$missing=false;
        foreach($receipt['tasks'] as [$id,$name]){
            $remote=$this->api->actionTask($context,$id,$name,$receipt['host'],$token,$deadline);
            if($remote===null&&$receipt['op']==='restart'&&$name==='host_stop')$remote=$this->api->actionTask($context,$id,'host_restart',$receipt['host'],$token,$deadline);
            if($remote===null){$missing=true;continue;}
            if(!VmmanagerTaskDiscovery::terminal($remote['state'])){$pending=true;continue;}
            if($remote['state']==='failed')$failed=true;
        }
        if($pending)return 'submitted';
        if($missing)VmmanagerPowerPolicy::deny('action_task_not_visible');
        if($failed)return 'failed';
        [$guest]=$this->liveGuest($context,$receipt['host'],$token,$deadline);
        if((string)$guest['node_id']!==$node||VmmanagerPowerPolicy::fingerprint($guest)!==$receipt['fingerprint'])VmmanagerPowerPolicy::deny('guest_identity_conflict');
        if($guest['state']!==($receipt['op']==='stop'?'stopped':'running'))VmmanagerPowerPolicy::deny('action_state_unconfirmed');
        return 'succeeded';
    }
    public function actionStatus(ConnectorContext $context,string $node,string $task,#[\SensitiveParameter] string $secret):string
    {
        return $this->withActionToken($context,$secret,function($token,$deadline,$identity)use($context,$node,$task):string{
            $status=$this->checkAction($context,$node,$task,$token,$deadline,$identity);$this->anchor($context,$token,$deadline);
            if($this->api->identity($context,$token,$deadline)!==$identity)throw new ConnectorTransportException('connector_auth_failed');
            return $status;
        });
    }

    public function execute(ConnectorContext $context, string $operation, #[\SensitiveParameter] string $secret): ConnectorResult
    {
        return $this->discover($context,$operation,$secret,'inventory');
    }

    public function cluster(ConnectorContext $context,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if($context->vmmanagerProfile===null)throw new ConnectorTransportException('connector_trust_failed');
        return $this->discover($context,'inspect',$secret,'cluster');
    }

    public function guests(ConnectorContext $context,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if($context->vmmanagerProfile===null)throw new ConnectorTransportException('connector_trust_failed');
        return $this->discover($context,'inspect',$secret,'guests');
    }

    public function network(ConnectorContext $context,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if($context->vmmanagerProfile===null)throw new ConnectorTransportException('connector_trust_failed');
        return $this->discover($context,'inspect',$secret,'network');
    }

    public function storage(ConnectorContext $context,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if($context->vmmanagerProfile===null)throw new ConnectorTransportException('connector_trust_failed');
        return $this->discover($context,'inspect',$secret,'storage');
    }

    public function templates(ConnectorContext $context,#[\SensitiveParameter] string $secret):ConnectorResult
    {
        if($context->vmmanagerProfile===null)throw new ConnectorTransportException('connector_trust_failed');
        return $this->discover($context,'inspect',$secret,'templates');
    }

    public function tasks(ConnectorContext $context,#[\SensitiveParameter] string $secret,array $previous):ConnectorResult
    {
        if($context->vmmanagerProfile===null)throw new ConnectorTransportException('connector_trust_failed');
        return $this->discover($context,'inspect',$secret,'tasks',$previous);
    }

    private function discover(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret,string $scope,array $previous=[]):ConnectorResult
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
            if($scope==='tasks'){
                $batch=VmmanagerTaskDiscovery::collect(fn(int $after,int $limit)=>$this->api->page($context,'tasks',$after,$limit,$token,$deadline),$previous,$identity);
                $batch['action_checks']=[];
                if($identity['administrator']&&$identity['owner_id']===$identity['trustee_id'])foreach(array_slice($previous['actions']??[],0,4) as $action){
                    if(microtime(true)>$deadline-10)break;
                    try{$status=$this->checkAction($context,$action['node'],$action['remote_task'],$token,$deadline,$identity);}
                    catch(ConnectorTransportException $e){if(!in_array($e->httpStatus,[403,404],true))throw $e;$status='submitted';}
                    catch(\Cajeer\Servers\Http\Exception\HttpException $e){if(!in_array($e->errorCode,['guest_identity_conflict','action_task_not_visible','action_state_unconfirmed'],true))throw $e;$status='submitted';}
                    $batch['action_checks'][]=['id'=>$action['id'],'remote_task'=>$action['remote_task'],'status'=>$status];
                }
                $this->anchor($context,$token,$deadline);
                if($this->api->identity($context,$token,$deadline)!==$identity)throw new ConnectorTransportException('connector_auth_failed');
                return new ConnectorResult([],false,connection:$connection+['vmmanager_task_discovery'=>$batch]);
            }
            if($scope==='cluster'){
                $clusters=$this->clusterPages($context,'clusters',$token,$deadline,self::LIMIT);
                $nodes=$this->clusterPages($context,'nodes',$token,$deadline,self::LIMIT-count($clusters));
                $visible=array_values(array_filter($nodes,static fn($r)=>VmmanagerApiClient::id($r->id??null)===$profile['anchor_id']));
                VmmanagerProfile::verify($profile,array_map(static fn($r)=>['id'=>VmmanagerApiClient::id($r->id),'name'=>$r->name??null],$visible));
                [$details,$items]=VmmanagerClusterDiscovery::build($clusters,$nodes,$identity['administrator']&&$identity['owner_id']===$identity['trustee_id']);
                $this->anchor($context,$token,$deadline);
                if($this->api->identity($context,$token,$deadline)!==$identity)throw new ConnectorTransportException('connector_auth_failed');
                // This collector has no guests; never claim completeness for the target-wide reconciliation.
                return new ConnectorResult($items,false,connection:$connection+['vmmanager_cluster_discovery'=>$details]);
            }
            if(in_array($scope,['storage','templates'],true)){
                $nodes=$this->clusterPages($context,'nodes',$token,$deadline,self::LIMIT);
                $hosts=$this->clusterPages($context,'guests',$token,$deadline,self::LIMIT-count($nodes));
                $visible=array_values(array_filter($nodes,static fn($r)=>VmmanagerApiClient::id($r->id??null)===$profile['anchor_id']));
                VmmanagerProfile::verify($profile,array_map(static fn($r)=>['id'=>VmmanagerApiClient::id($r->id),'name'=>$r->name??null],$visible));
                $full=$identity['administrator']&&$identity['owner_id']===$identity['trustee_id'];
                [$guestDetails]=VmmanagerGuestDiscovery::build($nodes,$hosts,$full);
                $sections=['nodes'=>'available','guests'=>'available'];$catalogs=[];$remaining=self::LIMIT;
                foreach($scope==='storage'?['storages']:['os','presets','images','isos'] as $resource){
                    try{
                        $catalogs[$resource]=$this->clusterPages($context,$resource,$token,$deadline,$remaining);
                        $remaining-=count($catalogs[$resource]);$sections[$resource]='available';
                    }catch(ConnectorTransportException $e){
                        // Only explicit ACL/not found may produce a partial catalog. No partial page is retained.
                        $sections[$resource]=match($e->httpStatus){403=>'denied',404=>'not_found',default=>throw $e};
                        $catalogs[$resource]=[];
                    }
                }
                $details=$scope==='storage'?VmmanagerStorageDiscovery::storage($catalogs['storages'],$nodes,$guestDetails['guests'],$full,$sections):VmmanagerStorageDiscovery::templates($catalogs,$guestDetails['guests'],$full,$sections);
                $this->anchor($context,$token,$deadline);
                if($this->api->identity($context,$token,$deadline)!==$identity)throw new ConnectorTransportException('connector_auth_failed');
                return new ConnectorResult([],false,connection:$connection+['vmmanager_'.$scope.'_discovery'=>$details]);
            }
            if($scope==='network'){
                $nodes=$this->clusterPages($context,'nodes',$token,$deadline,self::LIMIT);
                $hosts=$this->clusterPages($context,'guests',$token,$deadline,self::LIMIT-count($nodes));
                $visible=array_values(array_filter($nodes,static fn($r)=>VmmanagerApiClient::id($r->id??null)===$profile['anchor_id']));
                VmmanagerProfile::verify($profile,array_map(static fn($r)=>['id'=>VmmanagerApiClient::id($r->id),'name'=>$r->name??null],$visible));
                $full=$identity['administrator']&&$identity['owner_id']===$identity['trustee_id'];
                [$guestDetails]=VmmanagerGuestDiscovery::build($nodes,$hosts,$full);
                $networks=$this->clusterPages($context,'networks',$token,$deadline,self::LIMIT);
                $pools=$this->clusterPages($context,'pools',$token,$deadline,self::LIMIT-count($networks));
                $addresses=$this->clusterPages($context,'addresses',$token,$deadline,self::LIMIT-count($networks)-count($pools));
                $details=VmmanagerNetworkDiscovery::build($networks,$pools,$addresses,$guestDetails['guests'],$full);
                $this->anchor($context,$token,$deadline);
                if($this->api->identity($context,$token,$deadline)!==$identity)throw new ConnectorTransportException('connector_auth_failed');
                return new ConnectorResult([],false,connection:$connection+['vmmanager_network_discovery'=>$details]);
            }
            if($scope==='guests'){
                $nodes=$this->clusterPages($context,'nodes',$token,$deadline,self::LIMIT);
                $hosts=$this->clusterPages($context,'guests',$token,$deadline,self::LIMIT-count($nodes));
                $visible=array_values(array_filter($nodes,static fn($r)=>VmmanagerApiClient::id($r->id??null)===$profile['anchor_id']));
                VmmanagerProfile::verify($profile,array_map(static fn($r)=>['id'=>VmmanagerApiClient::id($r->id),'name'=>$r->name??null],$visible));
                [$details,$items]=VmmanagerGuestDiscovery::build($nodes,$hosts,$identity['administrator']&&$identity['owner_id']===$identity['trustee_id']);
                $this->anchor($context,$token,$deadline);
                if($this->api->identity($context,$token,$deadline)!==$identity)throw new ConnectorTransportException('connector_auth_failed');
                // Both inventories exhaust the same node/host scope; cluster absence is excluded.
                return new ConnectorResult($items,$details['complete'],connection:$connection+['vmmanager_guest_discovery'=>$details]);
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

    private function clusterPages(ConnectorContext $context,string $resource,#[\SensitiveParameter] string $token,float $deadline,int $limit):array
    {
        $rows=[];$last=0;
        for($page=0;$page<self::MAX_PAGES;$page++){
            $batch=$this->api->page($context,$resource,$last,self::PAGE,$token,$deadline);
            if($batch===[])return $rows;
            foreach($batch as $row){$last=VmmanagerApiClient::id($row->id??null);$rows[]=$row;if(count($rows)>$limit)throw new ConnectorTransportException('connector_protocol_error');}
        }
        throw new ConnectorTransportException('connector_protocol_error');
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
