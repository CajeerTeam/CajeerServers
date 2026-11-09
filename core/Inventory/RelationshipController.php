<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\Rbac;

/** Session-only API with explicit global RBAC, workspace membership and CSRF. */
final class RelationshipController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly Rbac $rbac,
        private readonly RelationshipService $relations,
        private readonly TopologyService $topology,
    ) {}
    private function actor(HttpRequest $r,bool $write):string
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        if($write)$this->auth->requireCsrf($r,$principal);
        $this->rbac->requirePermission($principal,$write?'inventory.relationships.manage':'inventory.relationships.read');
        return (string)$principal['user']['id'];
    }
    private static function query(HttpRequest $r,string $name,bool $required=true):?string
    {
        $v=$r->query[$name]??null;
        if($v===null && !$required)return null;
        return RelationshipService::uuid($v,$name);
    }
    private static function id(HttpRequest $r):string{return RelationshipService::uuid($r->attribute('id'),'id');}
    private static function limit(HttpRequest $r,string $name,int $default,int $max):int
    {
        $value=$r->query[$name]??$default;
        if ((!is_int($value)&&!is_string($value))||!preg_match('/^(0|[1-9][0-9]{0,4})$/D',(string)$value)) {
            throw new HttpException(422,'Invalid pagination.','validation_failed');
        }
        $n=(int)$value;
        if(($name==='limit' && $n<1)||$n>$max)throw new HttpException(422,'Invalid pagination.','validation_failed');
        return $n;
    }
    private static function expected(HttpRequest $r,string $id):int
    {
        $value=$r->header('If-Match');
        if($value===null)throw new HttpException(428,'If-Match required.','precondition_required');
        if(!preg_match('/^"([a-f0-9-]{36}):([1-9][0-9]{0,17})"$/iD',$value,$m)
            || !InfrastructureAsset::isUuid($m[1]) || strtolower($m[1])!==$id) {
            throw new HttpException(400,'Invalid If-Match.','invalid_precondition');
        }
        return (int)$m[2];
    }
    private static function rid(HttpRequest $r):?string{$v=$r->attribute('request_id');return is_string($v)?$v:null;}
    private static function json(array $row,int $status=200):HttpResponse
    {
        return HttpResponse::json(['data'=>$row],$status,['ETag'=>$row['etag']]);
    }
    public function index(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,false);
        $kind=$r->query['kind']??null;
        if($kind!==null && !is_string($kind))throw new HttpException(422,'Invalid kind filter.','validation_failed');
        return HttpResponse::json($this->relations->list($actor,self::query($r,'workspace_id'),
            self::limit($r,'limit',50,100),self::limit($r,'offset',0,10000),$kind,self::query($r,'asset_id',false)));
    }
    public function show(HttpRequest $r):HttpResponse
    {
        return self::json($this->relations->read($this->actor($r,false),self::query($r,'workspace_id'),self::id($r)));
    }
    public function create(HttpRequest $r):HttpResponse
    {
        return self::json($this->relations->create($this->actor($r,true),$r->attribute('json'),self::rid($r)),201);
    }
    public function update(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,true);$id=self::id($r);
        return self::json($this->relations->updateKind($actor,self::query($r,'workspace_id'),$id,
            self::expected($r,$id),$r->attribute('json')['kind'],self::rid($r)));
    }
    public function delete(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,true);$id=self::id($r);
        $this->relations->delete($actor,self::query($r,'workspace_id'),$id,self::expected($r,$id),self::rid($r));
        return new HttpResponse(204,'');
    }
    public function topology(HttpRequest $r):HttpResponse
    {
        // Topology contains asset names, CIDR and IP addresses: do not bypass
        // their distinct read scopes merely by granting relationship access.
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        foreach (['inventory.relationships.read','inventory.assets.read','inventory.network.read'] as $scope)
            $this->rbac->requirePermission($principal,$scope);
        return HttpResponse::json(['data'=>$this->topology->graph((string)$principal['user']['id'],self::query($r,'workspace_id'),
            self::limit($r,'limit',100,200))]);
    }
}
