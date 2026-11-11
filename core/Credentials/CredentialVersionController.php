<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use Cajeer\Servers\Security\Rbac;

/** Session-only metadata for staged secret rotation; never a plaintext retrieval endpoint. */
final class CredentialVersionController
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly Rbac $rbac,
        private readonly CredentialVersionService $versions,
    ) {}

    private function identity(HttpRequest $request,bool $write):string
    {
        $principal=$this->auth->authenticate($request,sessionOnly:true);
        if ($write) $this->auth->requireCsrf($request,$principal);
        $this->rbac->requirePermission($principal,$write?'vault.credentials.update':'vault.credentials.read');
        return (string)$principal['user']['id'];
    }

    private static function credential(HttpRequest $request):string
    {
        try{return CredentialInput::uuid($request->attribute('id'));}
        catch(HttpException){throw new HttpException(404,'Credential not found.','not_found');}
    }

    private static function revision(HttpRequest $request):int
    {
        $raw=$request->attribute('revision');
        if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,14}$/D',$raw))
            throw new HttpException(422,'Invalid credential secret version.','validation_failed');
        return (int)$raw;
    }

    private static function requestId(HttpRequest $request):?string
    {
        $id=$request->attribute('request_id');
        return is_string($id)?$id:null;
    }

    private static function response(array $data,int $status=200):HttpResponse
    {
        return HttpResponse::json(['data'=>$data],$status,[
            'Cache-Control'=>'no-store, private', 'Pragma'=>'no-cache',
            'ETag'=>(string)$data['etag'],
        ]);
    }

    public function index(HttpRequest $request):HttpResponse
    {
        $actor=$this->identity($request,false);
        return HttpResponse::json($this->versions->history($actor,self::credential($request)),200,
            ['Cache-Control'=>'no-store, private','Pragma'=>'no-cache']);
    }

    public function stage(HttpRequest $request):HttpResponse
    {
        $actor=$this->identity($request,true);
        $id=self::credential($request);
        $version=CredentialInput::version($id,$request->header('If-Match'));
        $json=$request->attribute('json');
        if (!is_array($json) || array_keys($json)!==['secret'])
            throw new HttpException(422,'Invalid credential rotation fields.','validation_failed');
        return self::response($this->versions->stage($actor,$id,$version,$json['secret'],self::requestId($request)),201);
    }

    public function activate(HttpRequest $request):HttpResponse
    {
        $actor=$this->identity($request,true);
        $id=self::credential($request);
        $version=CredentialInput::version($id,$request->header('If-Match'));
        $data=$request->attribute('json');
        if (!is_array($data) || array_keys($data)!==['confirmed'] || $data['confirmed']!==true)
            throw new HttpException(422,'Explicit confirmation required.','validation_failed');
        return self::response($this->versions->activate($actor,$id,$version,self::revision($request),true,self::requestId($request)));
    }

    public function cancel(HttpRequest $request):HttpResponse
    {
        $actor=$this->identity($request,true);
        $id=self::credential($request);
        $version=CredentialInput::version($id,$request->header('If-Match'));
        return self::response($this->versions->cancel($actor,$id,$version,self::revision($request),self::requestId($request)));
    }
}
