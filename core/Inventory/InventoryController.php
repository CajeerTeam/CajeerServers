<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\Rbac;

/** Session + global RBAC + CSRF; service re-checks workspace membership on every call. */
final class InventoryController
{
    public function __construct(private readonly AuthenticationService $auth,private readonly Rbac $rbac,private readonly InventoryService $service) {}

    private function actor(HttpRequest $r,string $scope,bool $mutate=false):string
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        if ($mutate) $this->auth->requireCsrf($r,$principal);
        $this->rbac->requirePermission($principal,$scope);
        return (string)$principal['user']['id'];
    }
    private static function id(HttpRequest $r):string
    {
        $id=$r->attribute('id');
        if (!is_string($id)||!InfrastructureAsset::isUuid($id)) throw new HttpException(404,'Asset not found.','not_found');
        return strtolower($id);
    }
    private static function intParam(HttpRequest $r,string $key,int $default):int
    {
        $value=$r->query[$key]??$default;
        if (is_int($value)) return $value;
        if (!is_string($value)||!preg_match('/^[0-9]{1,5}$/D',$value)) throw new HttpException(422,'Invalid pagination.','validation_failed');
        return (int)$value;
    }
    private static function textParam(HttpRequest $r,string $key):?string
    {
        $value=$r->query[$key]??null;
        if ($value!==null && !is_string($value)) throw new HttpException(422,'Invalid filter.','validation_failed');
        return $value;
    }
    private static function includeDeleted(HttpRequest $r):bool
    {
        $value=$r->query['include_deleted']??'0';
        if (!in_array($value,['0','1',0,1],true)) throw new HttpException(422,'Invalid include_deleted parameter.','validation_failed');
        return (string)$value==='1';
    }
    private static function version(HttpRequest $r,string $id):int
    {
        $header=$r->header('If-Match');
        if ($header===null) throw new HttpException(428,'An If-Match ETag is required.','precondition_required');
        if (!preg_match('/^"([0-9a-fA-F-]{36}):([1-9][0-9]{0,17})"$/D',$header,$matches)
            || strtolower($matches[1])!==$id || !InfrastructureAsset::isUuid($matches[1])) {
            throw new HttpException(400,'Invalid If-Match ETag.','invalid_precondition');
        }
        return (int)$matches[2];
    }
    private static function requestId(HttpRequest $r):?string
    {
        $id=$r->attribute('request_id');return is_string($id)?$id:null;
    }
    private static function response(array $row,int $status=200):HttpResponse
    {
        return HttpResponse::json(['data'=>$row],$status,['ETag'=>(string)$row['etag']]);
    }
    public function index(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.assets.read');
        $workspace=self::textParam($r,'workspace_id');
        if ($workspace===null || !InfrastructureAsset::isUuid($workspace)) throw new HttpException(422,'workspace_id is required.','validation_failed');
        $result=$this->service->list($actor,$workspace,self::intParam($r,'limit',50),self::intParam($r,'offset',0),
            self::textParam($r,'type'),self::textParam($r,'status'),self::textParam($r,'search'),self::includeDeleted($r),
            array_filter(array_combine(['tag','group_id','owner_user_id','environment','source','os_family','sort','cursor'],
                array_map(static fn(string $k):?string => self::textParam($r,$k),['tag','group_id','owner_user_id','environment','source','os_family','sort','cursor'])),
                static fn($v):bool => $v!==null && $v!==''));
        return HttpResponse::json($result);
    }
    public function show(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.assets.read');
        return self::response($this->service->read($actor,self::id($r),self::includeDeleted($r)));
    }
    public function create(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.assets.create',true);
        return self::response($this->service->create($actor,$r->attribute('json'),self::requestId($r)),201);
    }
    public function update(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.assets.update',true);$id=self::id($r);
        return self::response($this->service->update($actor,$id,self::version($r,$id),$r->attribute('json'),self::requestId($r)));
    }
    public function delete(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.assets.delete',true);$id=self::id($r);
        return self::response($this->service->delete($actor,$id,self::version($r,$id),self::requestId($r)));
    }
    public function restore(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.assets.delete',true);$id=self::id($r);
        return self::response($this->service->restore($actor,$id,self::version($r,$id),self::requestId($r)));
    }
}
