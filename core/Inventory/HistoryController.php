<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\Rbac;

/** Browser-session-only, global RBAC + workspace member checks; no bearer writes. */
final class HistoryController
{
    public function __construct(private readonly AuthenticationService $auth,private readonly Rbac $rbac,private readonly HistoryService $service){}

    private function actor(HttpRequest $r,string $scope,bool $write=false):string
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        if($write)$this->auth->requireCsrf($r,$principal);
        $this->rbac->requirePermission($principal,'inventory.assets.read');
        $this->rbac->requirePermission($principal,$scope);
        return (string)$principal['user']['id'];
    }
    private static function id(HttpRequest $r):string
    {
        $id=$r->attribute('id');
        if(!is_string($id)||!InfrastructureAsset::isUuid($id))throw new HttpException(404,'Asset not found.','not_found');
        return strtolower($id);
    }
    private static function page(HttpRequest $r,string $key,int $default,int $max):int
    {
        $v=$r->query[$key]??$default;
        if((!is_string($v)&&!is_int($v))||preg_match('/^(0|[1-9][0-9]{0,4})$/D',(string)$v)!==1)throw new HttpException(422,'Invalid pagination.','validation_failed');
        $n=(int)$v;if($n> $max||($key==='limit'&&$n<1))throw new HttpException(422,'Invalid pagination.','validation_failed');
        return $n;
    }
    private static function requestId(HttpRequest $r):?string
    {
        $v=$r->attribute('request_id');return is_string($v)?$v:null;
    }
    private static function version(HttpRequest $r,string $id):int
    {
        $h=$r->header('If-Match');
        if($h===null)throw new HttpException(428,'If-Match required.','precondition_required');
        if(!preg_match('/^"([0-9a-f-]{36}):([1-9][0-9]{0,17})"$/iD',$h,$m)||strtolower($m[1])!==$id||!InfrastructureAsset::isUuid($m[1]))
            throw new HttpException(400,'Invalid If-Match.','invalid_precondition');
        return (int)$m[2];
    }
    private static function asset(array $row):HttpResponse
    {
        return HttpResponse::json(['data'=>$row],200,['ETag'=>$row['etag']]);
    }
    public function revisions(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.history.read');
        return HttpResponse::json($this->service->revisions($actor,self::id($r),self::page($r,'limit',30,100),self::page($r,'offset',0,10000)));
    }
    public function provenance(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.history.read');
        return HttpResponse::json(['data'=>$this->service->provenance($actor,self::id($r))]);
    }
    public function conflicts(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.history.read');
        $flag=$r->query['include_resolved']??'0';if(!in_array($flag,['0','1',0,1],true))throw new HttpException(422,'Invalid include_resolved.','validation_failed');
        return HttpResponse::json($this->service->conflicts($actor,self::id($r),(string)$flag==='1'));
    }
    public function observations(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.history.read');
        return HttpResponse::json($this->service->observations($actor,self::id($r),self::page($r,'limit',30,100)));
    }
    public function receive(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.reconcile.manage',true);
        return HttpResponse::json(['data'=>$this->service->observe($actor,$r->attribute('json'),self::requestId($r))],202);
    }
    public function override(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.reconcile.manage',true);$id=self::id($r);
        return self::asset($this->service->override($actor,$id,self::version($r,$id),$r->attribute('json'),self::requestId($r)));
    }
    public function clearOverride(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.reconcile.manage',true);$id=self::id($r);
        $field=$r->attribute('field');if(!is_string($field))throw new HttpException(404,'Field not found.','not_found');
        return self::asset($this->service->clearOverride($actor,$id,self::version($r,$id),$field,self::requestId($r)));
    }
    public function restore(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.history.restore',true);$id=self::id($r);
        $version=$r->attribute('json')['revision']??null;
        if(!is_int($version)||$version<1)throw new HttpException(422,'Invalid revision.','validation_failed');
        return self::asset($this->service->restoreRevision($actor,$id,self::version($r,$id),$version,self::requestId($r)));
    }
}
