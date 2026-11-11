<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\Rbac;

/** Session/RBAC/CSRF secured connector configuration and credential reference HTTP API. */
final class ConnectorBindingController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly Rbac $rbac,
        private readonly ConnectorBindingService $service,
        private readonly ConnectorRuntimeService $runtime,
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
    public function index(HttpRequest $r):HttpResponse
    {
        return HttpResponse::json(['data'=>$this->service->list($this->actor($r,'connectors.targets.read'),self::queryUuid($r),
            self::integer($r,'limit',50,100),self::integer($r,'offset',0,10000))],200,['Cache-Control'=>'private, no-store']);
    }
    public function create(HttpRequest $r):HttpResponse
    {
        return self::etag($this->service->create($this->actor($r,'connectors.targets.manage',true),$r->attribute('json'),self::rid($r)),201);
    }
    public function show(HttpRequest $r):HttpResponse
    {
        return self::etag($this->service->show($this->actor($r,'connectors.targets.read'),self::id($r)));
    }
    public function update(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'connectors.targets.manage',true);$id=self::id($r);
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
    public function inspect(HttpRequest $r):HttpResponse
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        $this->auth->requireCsrf($r,$principal);
        $this->rbac->requirePermission($principal,'connectors.credentials.manage');
        $this->rbac->requirePermission($principal,'vault.credentials.use');
        return HttpResponse::json(['data'=>$this->runtime->run($principal,self::id($r),
            self::version($r,self::id($r)),false,self::rid($r))],200,
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
