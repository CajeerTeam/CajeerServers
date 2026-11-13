<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Integration\ConnectorRegistry;

/** Session/RBAC/CSRF secured connector configuration and credential reference HTTP API. */
final class ConnectorBindingController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly Rbac $rbac,
        private readonly ConnectorBindingService $service,
        private readonly ConnectorRuntimeService $runtime,
        private readonly ConnectorRegistry $registry,
        private readonly ?ConnectorExecutionService $execution=null,
        private readonly bool $writeActions=false,
    ) {}

    private function actor(HttpRequest $r,string $permission,bool $write=false):string
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        if ($write) $this->auth->requireCsrf($r,$principal);
        $this->rbac->requirePermission($principal,$permission);
        return (string)$principal['user']['id'];
    }
    private static function id(HttpRequest $r,string $key='id'):string
    {
        try {return CredentialInput::uuid($r->attribute($key));}
        catch(HttpException){throw new HttpException(404,'Connector resource not found.','not_found');}
    }
    private static function queryUuid(HttpRequest $r):string{return CredentialInput::uuid($r->query['workspace_id']??null);}
    private static function rid(HttpRequest $r):?string{$id=$r->attribute('request_id');return is_string($id)?$id:null;}
    private static function etag(array $row,int $code=200):HttpResponse
    {
        return HttpResponse::json(['data'=>$row],$code,['ETag'=>$row['etag'],'Cache-Control'=>'private, no-store']);
    }
    private static function version(HttpRequest $r,string $id):int
    {
        return CredentialInput::version($id,$r->header('If-Match'));
    }
    private static function integer(HttpRequest $r,string $name,int $default,int $max):int
    {
        $v=$r->query[$name]??$default;
        if ((!(is_string($v)||is_int($v)))||preg_match('/^(0|[1-9][0-9]{0,5})$/D',(string)$v)!==1 || (int)$v>$max)
            throw new HttpException(422,'Invalid pagination.','validation_failed');
        if ($name==='limit' && (int)$v<1)throw new HttpException(422,'Invalid pagination.','validation_failed');
        return (int)$v;
    }
    private function execution():ConnectorExecutionService
    {
        return $this->execution ?? throw new HttpException(503,'Connector execution unavailable.','connector_unavailable');
    }
    public function issueGrant(HttpRequest $r):HttpResponse
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        $this->auth->requireCsrf($r,$principal);
        $b=$r->attribute('json'); $id=self::id($r);
        return HttpResponse::json(['data'=>$this->execution()->issue($principal,$id,self::version($r,$id),$b['operation'],$b['ttl_seconds'],$b['interval_seconds']??null)],201,['Cache-Control'=>'private, no-store']);
    }
    public function grants(HttpRequest $r):HttpResponse
    {
        return HttpResponse::json(['data'=>$this->execution()->grants($this->actor($r,'connectors.credentials.read'),self::id($r))],200,['Cache-Control'=>'private, no-store']);
    }
    public function revokeGrant(HttpRequest $r):HttpResponse
    {
        $this->execution()->revoke($this->actor($r,'connectors.credentials.manage',true),self::id($r),self::id($r,'grantId'));
        return new HttpResponse(204,'');
    }
    public function enqueueRun(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'connectors.credentials.manage',true); $b=$r->attribute('json'); $id=self::id($r);
        $run=$this->execution()->enqueue($actor,$id,$b['grant_id'],$r->header('Idempotency-Key',''),$b['collector']??null);
        return HttpResponse::json(['data'=>$run],202,['Cache-Control'=>'private, no-store','Location'=>'/api/v1/connectors/targets/'.$id.'/runs/'.$run['id']]);
    }
    public function runStatus(HttpRequest $r):HttpResponse
    {
        return HttpResponse::json(['data'=>$this->execution()->status($this->actor($r,'connectors.targets.read'),self::id($r),self::id($r,'runId'))],200,['Cache-Control'=>'private, no-store']);
    }
    public function cancelRun(HttpRequest $r):HttpResponse
    {
        return HttpResponse::json(['data'=>$this->execution()->cancel($this->actor($r,'connectors.credentials.manage',true),self::id($r),self::id($r,'runId'))],200,['Cache-Control'=>'private, no-store']);
    }
    public function runs(HttpRequest $r):HttpResponse
    {
        return HttpResponse::json(['data'=>$this->execution()->runs($this->actor($r,'connectors.targets.read'),self::id($r),self::integer($r,'limit',50,100),self::integer($r,'offset',0,10000),self::filter($r,'plan_id'),self::collectorFilter($r))],200,['Cache-Control'=>'private, no-store']);
    }
    private static function collectorFilter(HttpRequest $r):?string
    {
        $value=$r->query['collector']??null;
        if($value!==null&&(!is_string($value)||!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$value)))throw new HttpException(422,'Invalid collector filter.','validation_failed');
        return $value;
    }
    public function proxmoxCluster(HttpRequest $r):HttpResponse
    {
        return HttpResponse::json(['data'=>$this->runtime->proxmoxCluster($this->actor($r,'connectors.targets.read'),self::id($r))],200,['Cache-Control'=>'private, no-store']);
    }
    public function metricSeries(HttpRequest $r):HttpResponse
    {
        $metric=$r->query['metric']??null;if($metric!==null&&!is_string($metric))throw new HttpException(422,'Invalid metric filter.','validation_failed');
        return HttpResponse::json(['data'=>$this->runtime->metricSeries($this->actor($r,'connectors.targets.read'),self::id($r),self::integer($r,'limit',100,100),self::integer($r,'offset',0,10000),$metric)],200,['Cache-Control'=>'private, no-store']);
    }
    public function metricQuery(HttpRequest $r):HttpResponse
    {
        $from=$r->query['from']??null;$to=$r->query['to']??null;$aggregation=$r->query['aggregation']??'raw';
        if(!is_string($from)||!is_string($to)||!is_string($aggregation))throw new HttpException(422,'Invalid metric query.','validation_failed');
        return HttpResponse::json(['data'=>$this->runtime->metricQuery($this->actor($r,'connectors.targets.read'),self::id($r),self::id($r,'seriesId'),$from,$to,$aggregation,self::integer($r,'step',60,86400))],200,['Cache-Control'=>'private, no-store']);
    }
    public function collectionPlans(HttpRequest $r):HttpResponse
    {
        return HttpResponse::json(['data'=>$this->execution()->plans($this->actor($r,'connectors.targets.read'),self::id($r),self::integer($r,'limit',50,100),self::integer($r,'offset',0,10000))],200,['Cache-Control'=>'private, no-store']);
    }
    public function createCollectionPlan(HttpRequest $r):HttpResponse
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);$this->auth->requireCsrf($r,$principal);
        $id=self::id($r);
        return HttpResponse::json(['data'=>$this->execution()->createPlan($principal,$id,self::version($r,$id),$r->attribute('json'))],201,['Cache-Control'=>'private, no-store']);
    }
    public function changeCollectionPlan(HttpRequest $r):HttpResponse
    {
        return HttpResponse::json(['data'=>$this->execution()->setPlanEnabled($this->actor($r,'connectors.credentials.manage',true),self::id($r),self::id($r,'planId'),$r->attribute('json')['enabled'])],200,['Cache-Control'=>'private, no-store']);
    }
    public function runCollectionPlan(HttpRequest $r):HttpResponse
    {
        $id=self::id($r);$b=$r->attribute('json');
        $run=$this->execution()->runPlan($this->actor($r,'connectors.credentials.manage',true),$id,self::id($r,'planId'),$r->header('Idempotency-Key',''),$b['redrive_of']??null);
        return HttpResponse::json(['data'=>$run],202,['Cache-Control'=>'private, no-store','Location'=>'/api/v1/connectors/targets/'.$id.'/runs/'.$run['id']]);
    }
    public function drivers(HttpRequest $r):HttpResponse
    {
        $this->actor($r,'connectors.targets.read');
        return HttpResponse::json(['data'=>array_values(array_map(fn($m)=>[...get_object_vars($m),'actions'=>$this->registry->actions($m->provider),'write_actions_enabled'=>$this->writeActions,'collectors'=>array_values(array_filter($this->runtime->collectionRuntime()->catalog(),fn($row)=>$row['provider']===$m->provider))],$this->registry->manifests()))],200,['Cache-Control'=>'private, no-store']);
    }
    public function health(HttpRequest $r):HttpResponse
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        $this->auth->requireCsrf($r,$principal);
        foreach (['connectors.credentials.manage','vault.credentials.use'] as $scope) $this->rbac->requirePermission($principal,$scope);
        $id=self::id($r);
        return HttpResponse::json(['data'=>$this->runtime->health($principal,$id,self::version($r,$id),self::rid($r))],200,['Cache-Control'=>'private, no-store']);
    }
    public function index(HttpRequest $r):HttpResponse
    {
        return HttpResponse::json(['data'=>$this->service->list($this->actor($r,'connectors.targets.read'),self::queryUuid($r),
            self::integer($r,'limit',50,100),self::integer($r,'offset',0,10000),self::filter($r,'search'),self::filter($r,'provider'),self::filter($r,'state'))],200,['Cache-Control'=>'private, no-store']);
    }
    private static function filter(HttpRequest $r,string $name):?string
    { $value=$r->query[$name]??null;if ($value!==null&&!is_string($value)) throw new HttpException(422,'Invalid connector filter.','validation_failed');return $value; }
    private function profileSession(HttpRequest $r):void
    {
        if(array_key_exists('proxmox_profile',$r->attribute('json'))){
            $p=$this->auth->authenticate($r,sessionOnly:true);
            if(($p['mfa_verified']??false)!==true)throw new HttpException(403,'Active MFA session required to configure Proxmox trust.','mfa_required');
        }
    }
    public function create(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'connectors.targets.manage',true);$this->profileSession($r);
        return self::etag($this->service->create($actor,$r->attribute('json'),self::rid($r)),201);
    }
    public function show(HttpRequest $r):HttpResponse
    {
        return self::etag($this->service->show($this->actor($r,'connectors.targets.read'),self::id($r)));
    }
    public function update(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'connectors.targets.manage',true);$id=self::id($r);
        $this->profileSession($r);
        return self::etag($this->service->update($actor,$id,self::version($r,$id),$r->attribute('json'),self::rid($r)));
    }
    public function delete(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'connectors.targets.manage',true);$id=self::id($r);
        $this->service->delete($actor,$id,self::version($r,$id),self::rid($r));
        return new HttpResponse(204,'');
    }
    public function bindings(HttpRequest $r):HttpResponse
    {
        return HttpResponse::json(['data'=>$this->service->listBindings($this->actor($r,'connectors.credentials.read'),self::id($r))],200,
            ['Cache-Control'=>'private, no-store']);
    }
    public function bind(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'connectors.credentials.manage',true);$id=self::id($r);
        $b=$r->attribute('json');
        $withAsset=($b['attach_to_asset']??false)===true;
        if ($withAsset) $this->rbac->requirePermission($this->auth->authenticate($r,sessionOnly:true),'inventory.credentials.manage');
        return HttpResponse::json(['data'=>$this->service->bind($actor,$id,$b['credential_id'],$b['purpose'],self::version($r,$id),self::rid($r),$withAsset)],201,
            ['Cache-Control'=>'private, no-store']);
    }
    public function resources(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'connectors.targets.read');
        $this->rbac->requirePermission($this->auth->authenticate($r,sessionOnly:true),'inventory.assets.read');
        return HttpResponse::json(['data'=>$this->runtime->resources($actor,self::id($r),self::integer($r,'limit',50,100),self::integer($r,'offset',0,10000))],200,['Cache-Control'=>'private, no-store']);
    }
    public function resolveResource(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'connectors.credentials.manage',true);
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        foreach (['inventory.assets.create','inventory.assets.update'] as $scope) $this->rbac->requirePermission($principal,$scope);
        $body=$r->attribute('json'); $id=self::id($r);
        $external=$body['external_id']??null;
        if (!is_string($external) || $external==='' || strlen($external)>180 || preg_match('/[\x00-\x1f\x7f]/',$external)) throw new HttpException(422,'Invalid external identity.','validation_failed');
        if (!in_array($body['mode']??null,['link','create'],true) || !is_int($body['generation']??null) || $body['generation']<1) throw new HttpException(422,'Invalid resolution.','validation_failed');
        $asset=$body['mode']==='link'?CredentialInput::uuid($body['asset_id']??null):null;
        if ($body['mode']==='create' && isset($body['asset_id'])) throw new HttpException(422,'Unexpected asset identity.','validation_failed');
        return HttpResponse::json(['data'=>$this->runtime->resolveResource($actor,$id,self::version($r,$id),$external,$body['generation'],$asset,self::rid($r))],200,['Cache-Control'=>'private, no-store']);
    }
    public function inspect(HttpRequest $r):HttpResponse
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        $this->auth->requireCsrf($r,$principal);
        $this->rbac->requirePermission($principal,'connectors.credentials.manage');
        $this->rbac->requirePermission($principal,'vault.credentials.use');
        $this->rbac->requirePermission($principal,'inventory.assets.read');
        return HttpResponse::json(['data'=>$this->runtime->run($principal,self::id($r),
            self::version($r,self::id($r)),false,self::rid($r))],200,
            ['Cache-Control'=>'private, no-store','Pragma'=>'no-cache']);
    }
    /** Real remote verification of a pending credential. No inventory writes. */
    public function validateRotation(HttpRequest $r):HttpResponse
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        $this->auth->requireCsrf($r,$principal);
        foreach (['connectors.credentials.manage','vault.credentials.use','vault.credentials.update'] as $scope)
            $this->rbac->requirePermission($principal,$scope);
        $body=$r->attribute('json');
        $revision=$body['revision']??null;
        if (!is_int($revision) || $revision<1)
            throw new HttpException(422,'Invalid pending revision.','validation_failed');
        $id=self::id($r);
        return HttpResponse::json(['data'=>$this->runtime->validatePending($principal,$id,
            self::version($r,$id),$revision,self::rid($r))],200,
            ['Cache-Control'=>'private, no-store','Pragma'=>'no-cache']);
    }

    public function sync(HttpRequest $r):HttpResponse
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        $this->auth->requireCsrf($r,$principal);
        foreach (['connectors.credentials.manage','vault.credentials.use','inventory.assets.create','inventory.assets.update'] as $scope)
            $this->rbac->requirePermission($principal,$scope);
        return HttpResponse::json(['data'=>$this->runtime->run($principal,self::id($r),
            self::version($r,self::id($r)),true,self::rid($r))],200,
            ['Cache-Control'=>'private, no-store','Pragma'=>'no-cache']);
    }
    public function unbind(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'connectors.credentials.manage',true);$id=self::id($r);
        $this->service->unbind($actor,$id,self::id($r,'bindingId'),self::version($r,$id),self::rid($r));
        return new HttpResponse(204,'');
    }
}
