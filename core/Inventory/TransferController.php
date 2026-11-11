<?php
declare(strict_types=1);
namespace Cajeer\Servers\Inventory;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\Rbac;

/** Session-bound, permission-checked transfer and credential-reference REST operations. */
final class TransferController
{
    public function __construct(private readonly AuthenticationService $auth,private readonly Rbac $rbac,
        private readonly TransferService $transfer,private readonly CredentialBindingService $credentials){}

    private function actor(HttpRequest $r,string $permission,bool $write=false):string
    {
        $principal=$this->auth->authenticate($r,sessionOnly:true);
        if($write)$this->auth->requireCsrf($r,$principal);
        $this->rbac->requirePermission($principal,$permission);
        return (string)$principal['user']['id'];
    }
    private static function workspace(HttpRequest $r):string
    {
        $ws=$r->query['workspace_id']??null;
        if(!is_string($ws)||!InfrastructureAsset::isUuid($ws))throw new HttpException(422,'workspace_id required.','validation_failed');
        return strtolower($ws);
    }
    private static function id(HttpRequest $r,string $name='id'):string
    {
        $id=$r->attribute($name);
        if(!is_string($id)||!InfrastructureAsset::isUuid($id))throw new HttpException(404,'Resource not found.','not_found');
        return strtolower($id);
    }
    private static function rid(HttpRequest $r):?string
    {
        $rid=$r->attribute('request_id');return is_string($rid)?$rid:null;
    }
    public function preview(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.transfer.read',true);
        $body=$r->attribute('json');
        return HttpResponse::json(['data'=>$this->transfer->preview($actor,$body['workspace_id'],$body['format'],$body['content'])]);
    }
    public function submit(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.transfer.manage',true);
        $key=$r->header('Idempotency-Key');
        if($key===null)throw new HttpException(428,'Idempotency-Key required.','precondition_required');
        $body=$r->attribute('json');
        $batch=$this->transfer->submit($actor,$body['workspace_id'],$body['format'],$body['content'],$key,self::rid($r));
        return HttpResponse::json(['data'=>$batch],202);
    }
    public function batch(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.transfer.manage');
        return HttpResponse::json(['data'=>$this->transfer->batch($actor,self::workspace($r),self::id($r))]);
    }
    public function dispatch(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.transfer.manage',true);
        return HttpResponse::json(['data'=>$this->transfer->dispatch($actor,self::workspace($r),self::id($r))],202);
    }
    public function export(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.transfer.read');
        $format=$r->query['format']??'csv';
        if(!is_string($format)||!in_array($format,['csv','json'],true))throw new HttpException(422,'Invalid format.','validation_failed');
        $content=$this->transfer->export($actor,self::workspace($r),$format);
        return new HttpResponse(200,$content,[
            'Content-Type'=>$format==='csv'?'text/csv; charset=utf-8':'application/json; charset=utf-8',
            'Content-Disposition'=>'attachment; filename="cajeer-inventory.'.$format.'"',
            'Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff',
        ]);
    }
    public function scopes(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.credentials.read');
        return HttpResponse::json(['data'=>$this->credentials->listScopes($actor,self::workspace($r))],200,['Cache-Control'=>'private, no-store']);
    }
    public function scope(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.credentials.scope',true);
        $b=$r->attribute('json');
        return HttpResponse::json(['data'=>$this->credentials->scope($actor,$b['workspace_id'],$b['credential_id'],self::rid($r))],201);
    }
    public function bindings(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.credentials.read');
        return HttpResponse::json(['data'=>$this->credentials->list($actor,self::id($r))]);
    }
    public function attach(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.credentials.manage',true);$body=$r->attribute('json');
        return HttpResponse::json(['data'=>$this->credentials->attach($actor,self::id($r),$body['credential_id'],$body['purpose'],self::rid($r))],201);
    }
    public function detach(HttpRequest $r):HttpResponse
    {
        $actor=$this->actor($r,'inventory.credentials.manage',true);
        $this->credentials->detach($actor,self::id($r),self::id($r,'bindingId'),self::rid($r));
        return new HttpResponse(204,'');
    }
}
