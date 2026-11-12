<?php
declare(strict_types=1);
namespace Cajeer\Servers\Connector;
use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\{HttpRequest,HttpResponse};

final class ConnectorEventController
{
    public function __construct(private readonly AuthenticationService $auth,private readonly ConnectorEventService $events) {}
    private function actor(HttpRequest $r,bool $write=false):string
    { $p=$this->auth->authenticate($r,sessionOnly:true);if ($write) $this->auth->requireCsrf($r,$p);return $p['user']['id']; }
    private function id(HttpRequest $r,string $key='id'):string {return CredentialInput::uuid($r->attribute($key));}
    private function response(array $data,int $status=200):HttpResponse {return HttpResponse::json(['data'=>$data],$status,['Cache-Control'=>'private, no-store']);}
    public function create(HttpRequest $r):HttpResponse {return $this->response($this->events->create($this->actor($r,true),$this->id($r),$r->attribute('json')['grant_id']),201);}
    public function sources(HttpRequest $r):HttpResponse {return $this->response($this->events->sources($this->actor($r),$this->id($r)));}
    public function rotate(HttpRequest $r):HttpResponse {return $this->response($this->events->change($this->actor($r,true),$this->id($r),$this->id($r,'sourceId'),true));}
    public function revoke(HttpRequest $r):HttpResponse {$this->events->change($this->actor($r,true),$this->id($r),$this->id($r,'sourceId'),false);return new HttpResponse(204,'');}
    public function ingest(HttpRequest $r):HttpResponse {$this->auth->assertInstalled();return $this->response($this->events->ingest($this->id($r,'sourceId'),$r),202);}
    private function number(HttpRequest $r,string $name,int $default):int
    { $v=$r->query[$name]??$default;if (!(is_int($v)||is_string($v)) || !preg_match('/^[0-9]{1,5}$/D',(string)$v)) throw new HttpException(422,'Invalid pagination.','validation_failed');return (int)$v; }
    public function events(HttpRequest $r):HttpResponse {return $this->response($this->events->events($this->actor($r),$this->id($r),$this->number($r,'limit',50),$this->number($r,'offset',0)));}
    public function redrive(HttpRequest $r):HttpResponse {return $this->response($this->events->redrive($this->actor($r,true),$this->id($r),$this->id($r,'eventId'),$r->attribute('json')['grant_id']),202);}
}
