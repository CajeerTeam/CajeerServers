<?php
declare(strict_types=1);
namespace Cajeer\Servers\ImportExport;
use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Ingestion\IngestionService;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Runtime\{HttpRequest,HttpResponse};
final class DataExchangeController
{
    public function __construct(private readonly AuthenticationService $auth,private readonly IngestionService $imports,private readonly ExportManager $exports){}
    private function actor(HttpRequest $r,bool $write=false):string{$p=$this->auth->authenticate($r,sessionOnly:true);if($write)$this->auth->requireCsrf($r,$p);return $p['user']['id'];}
    private function id(HttpRequest $r,string $key='id'):string{return CredentialInput::uuid($r->attribute($key));}
    private function result(array $data,int $status=200):HttpResponse{return HttpResponse::json(['data'=>$data],$status,['Cache-Control'=>'private, no-store']);}
    private function integer(HttpRequest $r,string $key,int $default):int{$v=$r->query[$key]??$default;if(!(is_string($v)||is_int($v))||!preg_match('/^\d{1,5}$/D',(string)$v))throw new HttpException(422,'Invalid pagination.','validation_failed');return (int)$v;}
    public function preview(HttpRequest $r):HttpResponse{return $this->import($r,false);}
    public function apply(HttpRequest $r):HttpResponse{return $this->import($r,true);}
    private function import(HttpRequest $r,bool $apply):HttpResponse{$b=$r->attribute('json');return $this->result($this->imports->manual($this->actor($r,true),$this->id($r),$this->id($r,'sourceId'),$b['format'],$b['batch_id'],$b['content'],$apply?$b['confirmation']:null),$apply?202:200);}
    public function create(HttpRequest $r):HttpResponse{return $this->result($this->exports->create($this->actor($r,true),$this->id($r),$r->attribute('json'),$r->header('Idempotency-Key','')),202);}
    public function list(HttpRequest $r):HttpResponse{return $this->result($this->exports->list($this->actor($r),$this->id($r),$this->integer($r,'limit',50),$this->integer($r,'offset',0)));}
    public function show(HttpRequest $r):HttpResponse{return $this->result($this->exports->show($this->actor($r),$this->id($r),$this->id($r,'exportId')));}
    public function cancel(HttpRequest $r):HttpResponse{return $this->result($this->exports->cancel($this->actor($r,true),$this->id($r),$this->id($r,'exportId')));}
    public function download(HttpRequest $r):HttpResponse
    {
        $id=$this->id($r,'exportId');$a=$this->exports->download($this->actor($r),$this->id($r),$id);
        $mime=match($a['format']){'json'=>'application/json','csv'=>'text/csv','openmetrics'=>'application/openmetrics-text; version=1.0.0',default=>'application/octet-stream'};
        return new HttpResponse(200,$a['body'],['Content-Type'=>$mime,'Content-Disposition'=>'attachment; filename="cajeer-'.$id.'.'.$a['format'].'"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','Content-Security-Policy'=>"default-src 'none'; sandbox",'X-Artifact-SHA256'=>$a['sha256']]);
    }
}
