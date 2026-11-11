<?php
declare(strict_types=1);

namespace Cajeer\Servers\Credentials;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;

/** HTTP can only authorize a single internal use; it NEVER retrieves plaintext. */
final class SecretAccessController
{
    public function __construct(private readonly AuthenticationService $auth,
                                private readonly SecretAccessService $access) {}

    public function authorize(HttpRequest $request): HttpResponse
    {
        $principal=$this->auth->authenticate($request,sessionOnly:true);
        $this->auth->requireCsrf($request,$principal);
        $data=$request->attribute('json');
        if (!is_array($data) || array_diff(array_keys($data),['asset_id','purpose','password','totp'])!==[]) {
            throw new HttpException(422,'Invalid authorization fields.','validation_failed');
        }
        $result=$this->access->authorize($principal,(string)($data['asset_id']??''),
            (string)$request->attribute('id'),(string)($data['purpose']??''),
            (string)($data['password']??''),(string)($data['totp']??''),
            is_string($request->attribute('request_id'))?$request->attribute('request_id'):null);
        return HttpResponse::json(['data'=>$result],200,['Cache-Control'=>'no-store, private','Pragma'=>'no-cache']);
    }
}
