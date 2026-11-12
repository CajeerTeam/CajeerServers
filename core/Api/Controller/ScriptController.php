<?php
declare(strict_types=1);
namespace Cajeer\Servers\Api\Controller;
use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Credentials\CredentialInput;
use Cajeer\Servers\Runtime\{HttpRequest,HttpResponse};
use Cajeer\Servers\Script\{ScriptRegistry,ScriptVersion,ScriptExecution};

final class ScriptController
{
    public function __construct(private readonly AuthenticationService $auth,private readonly ScriptRegistry $registry,private readonly ScriptExecution $executions) {}
    public function dispatch(HttpRequest $r,string $method):HttpResponse
    {
        $p=$this->auth->authenticate($r,sessionOnly:true);if($r->method!=='GET'&&$r->method!=='HEAD')$this->auth->requireCsrf($r,$p);
        $w=CredentialInput::uuid($r->attribute('workspace'));$id=$r->attribute('id')===null?null:CredentialInput::uuid($r->attribute('id'));$v=$r->attribute('version')===null?null:CredentialInput::uuid($r->attribute('version'));$in=$r->attribute('json',[]);
        $offset=$r->query['offset']??'0';if(!is_scalar($offset)||!preg_match('/^[0-9]{1,5}$/D',(string)$offset))ScriptVersion::invalid();$offset=(int)$offset;
        $data=match($method){
            'list'=>$this->registry->list($p,$w,$offset),'templates'=>$this->registry->templates($p,$w),'create'=>$this->registry->create($p,$w,$in),
            'detail'=>$this->registry->detail($p,$w,$id),'update','archive'=>$this->registry->update($p,$w,$id,CredentialInput::version($id,$r->header('If-Match')),$in,$method==='archive'),
            'versions'=>$this->registry->versions($p,$w,$id,$offset),'version'=>$this->registry->getVersion($p,$w,$id,$v),'addVersion'=>$this->registry->addVersion($p,$w,$id,$in),
            'keys'=>$this->registry->keys($p,$w,$offset),'addKey'=>$this->registry->addKey($p,$w,$in),'revokeKey'=>$this->registry->revokeKey($p,$w,$id,$in),
            'sign'=>$this->registry->sign($p,$w,$id,$v,$in),'approve','revoke'=>$this->registry->review($p,$w,$id,$v,$in,$method==='approve'),
            'validate'=>$this->registry->validate($p,$w,$id,$v,$in),
            'enqueue'=>$this->executions->enqueue($p,$w,$id,$v,$in,$r->header('Idempotency-Key')??''),
            'executions'=>$this->executions->history($p,$w,$id,$v,$offset),
            'execution','cancelExecution'=>$this->executions->detail($p,$w,$id,$v,CredentialInput::uuid($r->attribute('execution')),$method==='cancelExecution'),
        };
        return HttpResponse::json(['data'=>$data],$method==='enqueue'?202:(in_array($method,['create','addVersion','addKey'],true)?201:200),['Cache-Control'=>'private, no-store']);
    }
}
