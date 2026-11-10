<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\Rbac;

final class CatalogController
{
    public function __construct(private readonly AuthenticationService $auth,private readonly Rbac $rbac,private readonly CatalogService $catalog) {}

    private function actor(HttpRequest $r,bool $write):string
    {
        $user=$this->auth->authenticate($r,sessionOnly:true);
        if($write)$this->auth->requireCsrf($r,$user);
        $this->rbac->requirePermission($user,$write?'inventory.catalog.manage':'inventory.catalog.read');
        return (string)$user['user']['id'];
    }
    private static function q(HttpRequest $r,string $name):string
    {
        return CatalogService::uuid($r->query[$name]??null,$name);
    }
    private static function id(HttpRequest $r,string $name='id'):string
    {
        return CatalogService::uuid($r->attribute($name),$name);
    }
    private static function page(HttpRequest $r,string $name,int $default):int
    {
        $v=$r->query[$name]??$default;
        if((!is_string($v)&&!is_int($v))||preg_match('/^(0|[1-9][0-9]{0,4})$/D',(string)$v)!==1)
            throw new HttpException(422,'Invalid pagination.','validation_failed');
        $n=(int)$v;
        if(($name==='limit'&&($n<1||$n>100))||($name==='offset'&&$n>10000))
            throw new HttpException(422,'Invalid pagination.','validation_failed');
        return $n;
    }
    private static function version(HttpRequest $r,string $id):int
    {
        $header=$r->header('If-Match');
        if($header===null)throw new HttpException(428,'If-Match required.','precondition_required');
        if(!preg_match('/^"([a-f0-9-]{36}):([1-9][0-9]{0,17})"$/iD',$header,$m)
            ||!InfrastructureAsset::isUuid($m[1])||strtolower($m[1])!==$id)
            throw new HttpException(400,'Invalid If-Match.','invalid_precondition');
        return (int)$m[2];
    }
    private static function rid(HttpRequest $r):?string
    {
        $v=$r->attribute('request_id');return is_string($v)?$v:null;
    }
    private static function respond(array $entry,int $status=200):HttpResponse
    {
        return HttpResponse::json(['data'=>$entry],$status,['ETag'=>(string)$entry['etag']]);
    }
    public function index(HttpRequest $r,string $kind):HttpResponse
    {
        return HttpResponse::json($this->catalog->list($this->actor($r,false),self::q($r,'workspace_id'),$kind,self::page($r,'limit',100),self::page($r,'offset',0)));
    }
    public function show(HttpRequest $r,string $kind):HttpResponse
    {
        return self::respond($this->catalog->read($this->actor($r,false),self::q($r,'workspace_id'),$kind,self::id($r)));
    }
    public function create(HttpRequest $r,string $kind):HttpResponse
    {
        return self::respond($this->catalog->create($this->actor($r,true),$kind,$r->attribute('json'),self::rid($r)),201);
    }
    public function update(HttpRequest $r,string $kind):HttpResponse
    {
        $actor=$this->actor($r,true);$id=self::id($r);
        return self::respond($this->catalog->update($actor,$kind,self::q($r,'workspace_id'),$id,self::version($r,$id),$r->attribute('json'),self::rid($r)));
    }
    public function delete(HttpRequest $r,string $kind):HttpResponse
    {
        $actor=$this->actor($r,true);$id=self::id($r);
        $this->catalog->delete($actor,$kind,self::q($r,'workspace_id'),$id,self::version($r,$id),self::rid($r));
        return new HttpResponse(204,'');
    }
    public function assets(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,false);
        // This endpoint contains asset names and types: catalog metadata permission alone is insufficient.
        $this->rbac->requirePermission($this->auth->authenticate($r,sessionOnly:true),'inventory.assets.read');
        return HttpResponse::json($this->catalog->groupAssets($actor,self::q($r,'workspace_id'),self::id($r),self::page($r,'limit',100),self::page($r,'offset',0)));
    }
    public function member(HttpRequest $r,bool $attach):HttpResponse
    {
        $actor=$this->actor($r,true);$group=self::id($r);$asset=self::id($r,'assetId');
        return self::respond($this->catalog->groupMembership($actor,self::q($r,'workspace_id'),$group,$asset,self::version($r,$group),$attach,self::rid($r)));
    }
}
