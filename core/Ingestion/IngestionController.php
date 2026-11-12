<?php
declare(strict_types=1);
namespace Cajeer\Servers\Ingestion;
use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\{HttpRequest,HttpResponse};

final class IngestionController
{
    public function __construct(private readonly AuthenticationService $auth,private readonly IngestionService $service){}
    private function actor(HttpRequest $r,bool $write=false):string{$p=$this->auth->authenticate($r,sessionOnly:true);if($write)$this->auth->requireCsrf($r,$p);return $p['user']['id'];}
    private function id(HttpRequest $r,string $key='id'):string{return CredentialInput::uuid($r->attribute($key));}
    private function response(array $data,int $status=200):HttpResponse{return HttpResponse::json(['data'=>$data],$status,['Cache-Control'=>'private, no-store']);}
    private function number(HttpRequest $r,string $key,int $default):int{$v=$r->query[$key]??$default;if(!(is_string($v)||is_int($v))||!preg_match('/^[0-9]{1,5}$/D',(string)$v))throw new HttpException(422,'Invalid pagination.','validation_failed');return (int)$v;}
    public function sources(HttpRequest $r):HttpResponse{return $this->response($this->service->sources($this->actor($r),$this->id($r)));}
    public function create(HttpRequest $r):HttpResponse{$b=$r->attribute('json');return $this->response($this->service->create($this->actor($r,true),$this->id($r),$b['grant_id'],$b['name'],$b['kind']),201);}
    public function rotate(HttpRequest $r):HttpResponse{return $this->response($this->service->change($this->actor($r,true),$this->id($r),$this->id($r,'sourceId'),true));}
    public function revoke(HttpRequest $r):HttpResponse{$this->service->change($this->actor($r,true),$this->id($r),$this->id($r,'sourceId'),false);return new HttpResponse(204,'');}
    public function ingest(HttpRequest $r):HttpResponse{$this->auth->assertInstalled();return $this->response($this->service->ingest($this->id($r,'sourceId'),$r),202);}
    public function batches(HttpRequest $r):HttpResponse{return $this->response($this->service->batches($this->actor($r),$this->id($r),$this->number($r,'limit',50),$this->number($r,'offset',0)));}
    public function batch(HttpRequest $r):HttpResponse{return $this->response($this->service->batch($this->actor($r),$this->id($r),$this->id($r,'batchId')));}
    public function redrive(HttpRequest $r):HttpResponse{return $this->response($this->service->redrive($this->actor($r,true),$this->id($r),$this->id($r,'batchId')),202);}
    public function events(HttpRequest $r):HttpResponse{return $this->response($this->service->events($this->actor($r),$this->id($r),$this->number($r,'limit',50),$this->number($r,'offset',0)));}
}
