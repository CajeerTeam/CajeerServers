<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;
use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\{HttpRequest,HttpResponse};
final class ConnectorActionController
{
    public function __construct(private readonly AuthenticationService $auth,private readonly ConnectorActionService $actions) {}
    private function p(HttpRequest $r,bool $write=false):array {$p=$this->auth->authenticate($r,sessionOnly:true);if($write)$this->auth->requireCsrf($r,$p);return $p;}
    private function id(HttpRequest $r,string $key='id'):string{return CredentialInput::uuid($r->attribute($key));}
    private function version(HttpRequest $r):int{return CredentialInput::version($this->id($r),$r->header('If-Match'));}
    private function out(array $data,int $status=200):HttpResponse{return HttpResponse::json(['data'=>$data],$status,['Cache-Control'=>'private, no-store']);}
    public function guestAccess(HttpRequest $r):HttpResponse{return $this->out($this->actions->guestAccess($this->p($r,true),$this->id($r),$this->version($r),$r->attribute('json')));}
    public function submit(HttpRequest $r):HttpResponse{return $this->out($this->actions->submit($this->p($r,true),$this->id($r),$this->version($r),$r->attribute('json'),$r->header('Idempotency-Key')??''),202);}
    public function list(HttpRequest $r):HttpResponse {$v=$r->query['offset']??'0';if(!is_scalar($v)||!preg_match('/^\d{1,5}$/D',(string)$v))throw new HttpException(422,'Invalid offset.','validation_failed');$external=$r->query['external_id']??null;if($external!==null&&(!is_string($external)||!preg_match('/^(qemu|lxc):[1-9][0-9]{0,8}$/D',$external)))throw new HttpException(422,'Invalid guest identity.','validation_failed');return $this->out($this->actions->list($this->p($r),$this->id($r),(int)$v,$external));}
    public function get(HttpRequest $r):HttpResponse{return $this->out($this->actions->get($this->p($r),$this->id($r),$this->id($r,'actionId')));}
    public function refresh(HttpRequest $r):HttpResponse{return $this->out($this->actions->refresh($this->p($r,true),$this->id($r),$this->id($r,'actionId'),$this->version($r)));}
    public function acknowledge(HttpRequest $r):HttpResponse {if(($r->attribute('json')['confirm']??null)!=='I verified the remote result')throw new HttpException(422,'Explicit confirmation is required.','validation_failed');return $this->out($this->actions->refresh($this->p($r,true),$this->id($r),$this->id($r,'actionId'),$this->version($r),true));}
}
