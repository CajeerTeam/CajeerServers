<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\Rbac;

/** Credential metadata REST. Plaintext is accepted only for create/replace, never returned. */
final class CredentialController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly Rbac $rbac,
        private readonly CredentialStore $store,
    ) {}

    private function actor(HttpRequest $request,string $permission,bool $write=false):string
    {
        $principal=$this->auth->authenticate($request,sessionOnly:true);
        if ($write) $this->auth->requireCsrf($request,$principal);
        $this->rbac->requirePermission($principal,$permission);
        return (string)$principal['user']['id'];
    }

    private static function id(HttpRequest $request):string
    {
        try { return CredentialInput::uuid($request->attribute('id')); }
        catch (HttpException) { throw new HttpException(404,'Credential not found.','not_found'); }
    }

    private static function integer(HttpRequest $request,string $key,int $default):int
    {
        $v=$request->query[$key]??$default;
        if (!is_int($v) && (!is_string($v) || preg_match('/^[0-9]{1,5}$/D',$v)!==1))
            throw new HttpException(422,'Invalid pagination.','validation_failed');
        return (int)$v;
    }

    private static function requestId(HttpRequest $r):?string
    {
        $id=$r->attribute('request_id');
        return is_string($id)?$id:null;
    }

    private static function response(array $row,int $code=200):HttpResponse
    {
        return HttpResponse::json(['data'=>$row],$code, [
            'ETag'=>(string)$row['etag'],
            'Cache-Control'=>'no-store, private',
            'Pragma'=>'no-cache',
        ]);
    }

    public function index(HttpRequest $request):HttpResponse
    {
        $actor=$this->actor($request,'vault.credentials.read');
        $workspace=CredentialInput::uuid($request->query['workspace_id']??null);
        $page=$this->store->list($actor,$workspace,self::integer($request,'limit',50),self::integer($request,'offset',0));
        return HttpResponse::json($page,200,['Cache-Control'=>'no-store, private','Pragma'=>'no-cache']);
    }

    public function create(HttpRequest $request):HttpResponse
    {
        $actor=$this->actor($request,'vault.credentials.create',true);
        $result=$this->store->create($actor,$request->attribute('json'),self::requestId($request));
        return self::response($result,201);
    }

    public function show(HttpRequest $request):HttpResponse
    {
        $actor=$this->actor($request,'vault.credentials.read');
        return self::response($this->store->show($actor,self::id($request)));
    }

    public function update(HttpRequest $request):HttpResponse
    {
        $actor=$this->actor($request,'vault.credentials.update',true);
        $id=self::id($request);
        $version=CredentialInput::version($id,$request->header('If-Match'));
        return self::response($this->store->update($actor,$id,$version,$request->attribute('json'),self::requestId($request)));
    }

    public function replaceSecret(HttpRequest $request):HttpResponse
    {
        $actor=$this->actor($request,'vault.credentials.update',true);
        $id=self::id($request);
        $version=CredentialInput::version($id,$request->header('If-Match'));
        $body=$request->attribute('json');
        return self::response($this->store->replaceSecret($actor,$id,$version,$body['secret'],self::requestId($request)));
    }

    public function delete(HttpRequest $request):HttpResponse
    {
        $actor=$this->actor($request,'vault.credentials.delete',true);
        $id=self::id($request);
        $version=CredentialInput::version($id,$request->header('If-Match'));
        return self::response($this->store->archive($actor,$id,$version,self::requestId($request)));
    }
}
