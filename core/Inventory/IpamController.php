<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\Rbac;

/** Authenticated, workspace-scoped management API. All writes require a session and CSRF. */
final class IpamController
{
    public function __construct(private readonly AuthenticationService $auth,private readonly Rbac $rbac,private readonly IpamService $ipam) {}
    private function actor(HttpRequest $r,bool $write):string
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        if ($write) $this->auth->requireCsrf($r,$principal);
        $this->rbac->requirePermission($principal,$write?'inventory.network.manage':'inventory.network.read');
        return (string)$principal['user']['id'];
    }
    private static function workspace(HttpRequest $r):string
    {
        $ws=$r->query['workspace_id']??null;
        if (!is_string($ws)) throw new HttpException(422,'workspace_id is required.','validation_failed');
        return Network::uuid($ws,'workspace_id');
    }
    private static function id(HttpRequest $r):string
    {
        $id=$r->attribute('id');
        if (!is_string($id) || !InfrastructureAsset::isUuid($id)) throw new HttpException(404,'IPAM resource not found.','not_found');
        return strtolower($id);
    }
    private static function pagination(HttpRequest $r,string $field,int $default):int
    {
        $v=$r->query[$field]??$default;
        if (!is_string($v) && !is_int($v)) Network::invalid($field);
        if (!preg_match('/^[0-9]{1,5}$/D',(string)$v)) Network::invalid($field);
        return (int)$v;
    }
    private static function version(HttpRequest $r,string $id):int
    {
        $etag=$r->header('If-Match');
        if ($etag===null) throw new HttpException(428,'If-Match ETag required.','precondition_required');
        if (!preg_match('/^"([a-f0-9-]{36}):([1-9][0-9]{0,17})"$/iD',$etag,$match)
            || !InfrastructureAsset::isUuid($match[1]) || strtolower($match[1])!==$id) {
            throw new HttpException(400,'Invalid If-Match ETag.','invalid_precondition');
        }
        return (int)$match[2];
    }
    private static function rid(HttpRequest $r):?string
    {
        $id=$r->attribute('request_id');return is_string($id)?$id:null;
    }
    private static function response(array $row,int $status=200):HttpResponse
    {
        return HttpResponse::json(['data'=>$row],$status,['ETag'=>$row['etag']]);
    }
    public function index(HttpRequest $r,string $kind):HttpResponse
    {
        $actor=$this->actor($r,false);
        return HttpResponse::json($this->ipam->list($actor,$kind,self::workspace($r),self::pagination($r,'limit',50),self::pagination($r,'offset',0)));
    }
    public function show(HttpRequest $r,string $kind):HttpResponse
    {
        $actor=$this->actor($r,false);
        return self::response($this->ipam->show($actor,$kind,self::workspace($r),self::id($r)));
    }
    public function create(HttpRequest $r,string $kind):HttpResponse
    {
        $actor=$this->actor($r,true);$data=$r->attribute('json');
        if (!is_array($data)) throw new HttpException(400,'Invalid JSON request.','invalid_json');
        return self::response($this->ipam->create($actor,$kind,self::workspace($r),$data,self::rid($r)),201);
    }
    public function update(HttpRequest $r,string $kind):HttpResponse
    {
        $actor=$this->actor($r,true);$id=self::id($r);$data=$r->attribute('json');
        if (!is_array($data)) throw new HttpException(400,'Invalid JSON request.','invalid_json');
        return self::response($this->ipam->update($actor,$kind,self::workspace($r),$id,self::version($r,$id),$data,self::rid($r)));
    }
    public function delete(HttpRequest $r,string $kind):HttpResponse
    {
        $actor=$this->actor($r,true);$id=self::id($r);
        $this->ipam->delete($actor,$kind,self::workspace($r),$id,self::version($r,$id),self::rid($r));
        return HttpResponse::json(['deleted'=>true]);
    }
    public function assign(HttpRequest $r,bool $unassign=false):HttpResponse
    {
        $actor=$this->actor($r,true);$id=self::id($r);
        $payload=$r->attribute('json');
        if (!$unassign && (!is_array($payload) || !isset($payload['interface_id']))) Network::invalid('interface_id');
        if ($unassign && trim($r->body)!=='') Network::invalid('body');
        $interface=$unassign?null:Network::uuid($payload['interface_id'],'interface_id');
        return self::response($this->ipam->assign($actor,self::workspace($r),$id,self::version($r,$id),$interface,self::rid($r)));
    }
}
