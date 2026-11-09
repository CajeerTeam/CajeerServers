<?php
declare(strict_types=1);

namespace Cajeer\Servers\Workspace;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\Rbac;

/** Session + CSRF + global RBAC + membership enforcement; never trusts workspaceId from a principal's claims. */
final class WorkspaceController
{
    public function __construct(private readonly AuthenticationService $auth,private readonly Rbac $rbac,private readonly WorkspaceService $service) {}

    private function actor(HttpRequest $r,string $scope,bool $mutate=false):string
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        if($mutate)$this->auth->requireCsrf($r,$principal);
        $this->rbac->requirePermission($principal,$scope);
        return (string)$principal['user']['id'];
    }

    private static function id(HttpRequest $r,string $field):string
    {
        $id=$r->attribute($field);
        return WorkspaceService::uuid(is_string($id)?$id:'');
    }

    private static function page(HttpRequest $r,string $key,int $default):int
    {
        $value=$r->query[$key]??$default;
        if (is_int($value))return $value;
        if(!is_string($value)||!preg_match('/^[0-9]{1,5}$/D',$value))throw new HttpException(422,'Invalid pagination.','validation_failed');
        return (int)$value;
    }

    private static function requestId(HttpRequest $r):?string
    {
        $value=$r->attribute('request_id');return is_string($value)?$value:null;
    }

    public function organizations(HttpRequest $r):HttpResponse
    {
        return HttpResponse::json(['data'=>$this->service->organizations($this->actor($r,'workspaces.read'))]);
    }
    public function createOrganization(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'organizations.manage',true);$data=$r->attribute('json');
        return HttpResponse::json($this->service->createOrganization($actor,$data['slug'],$data['name'],self::requestId($r)),201);
    }
    public function renameOrganization(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'organizations.manage',true);
        return HttpResponse::json($this->service->renameOrganization($actor,self::id($r,'orgId'),$r->attribute('json')['name'],self::requestId($r)));
    }
    public function workspaces(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'workspaces.read');
        $id=$r->query['organization_id']??null;
        if($id!==null && !is_string($id))throw new HttpException(422,'Invalid organization filter.','validation_failed');
        return HttpResponse::json(['data'=>$this->service->workspaces($actor,$id)]);
    }
    public function createWorkspace(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'workspaces.manage',true);$data=$r->attribute('json');
        return HttpResponse::json($this->service->createWorkspace($actor,$data['organization_id'],$data['slug'],$data['name'],self::requestId($r)),201);
    }
    public function renameWorkspace(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'workspaces.manage',true);
        return HttpResponse::json($this->service->renameWorkspace($actor,self::id($r,'workspaceId'),$r->attribute('json')['name'],self::requestId($r)));
    }
    public function members(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'workspaces.read');
        return HttpResponse::json(['data'=>$this->service->members($actor,self::id($r,'workspaceId'))]);
    }
    public function setMember(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'workspaces.members.manage',true);
        $this->service->setMember($actor,self::id($r,'workspaceId'),self::id($r,'userId'),$r->attribute('json')['role'],self::requestId($r));
        return new HttpResponse(204,'');
    }
    public function removeMember(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'workspaces.members.manage',true);
        $this->service->setMember($actor,self::id($r,'workspaceId'),self::id($r,'userId'),null,self::requestId($r));
        return new HttpResponse(204,'');
    }
    public function assets(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'workspaces.read');
        return HttpResponse::json(['data'=>$this->service->assets($actor,self::id($r,'workspaceId'),self::page($r,'limit',50),self::page($r,'offset',0))]);
    }
    public function assignOwner(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.ownership.manage',true);
        $owner=$r->attribute('json')['owner_user_id'];
        if ($owner!==null && (!is_string($owner) || preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$owner)!==1)) {
            throw new HttpException(422,'Invalid asset owner.','validation_failed');
        }
        return HttpResponse::json($this->service->assignOwner($actor,self::id($r,'workspaceId'),self::id($r,'assetId'),$owner,self::requestId($r)));
    }
    public function transferAsset(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.ownership.manage',true);
        return HttpResponse::json($this->service->transferAsset($actor,self::id($r,'workspaceId'),self::id($r,'assetId'),$r->attribute('json')['destination_workspace_id'],self::requestId($r)));
    }
}
