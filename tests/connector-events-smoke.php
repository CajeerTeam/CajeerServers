#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorEventPayload,ConnectorEventService};
use Cajeer\Servers\Credentials\{VaultCrypto,VaultMasterKey};
use Cajeer\Servers\Database\SqlStatementParser;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\SignedPayload;
$n=0;$ok=static function(bool $v,string $label)use(&$n):void{if(!$v)throw new RuntimeException('FAIL: '.$label);$n++;};
$reject=static function(int $status,callable $fn)use($ok):void{try{$fn();}catch(HttpException $e){$ok($e->status===$status,'expected '.$status);return;}throw new RuntimeException('Expected rejection');};
$id='11111111-1111-4111-8111-111111111111';$ws='22222222-2222-4222-8222-222222222222';$key=bin2hex(random_bytes(32));$time=1794448800;
$body='{"event_id":"evt-1","type":"resource.updated","resource_id":"qemu:101"}';
$sig=SignedPayload::signature($id."\n".$body,$key,$time);
ConnectorEventPayload::verify($body,$key,$id,(string)$time,$sig,$time);$ok(true,'valid raw HMAC');
$payload=ConnectorEventPayload::parse($body);$ok($payload['event_id']==='evt-1'&&$payload['resource_id']==='qemu:101'&&$payload['digest']===hash('sha256',$body),'normalized fields');
foreach([[$body.' ',$key,$id,(string)$time,$sig,$time],[$body,$key,$ws,(string)$time,$sig,$time],[$body,str_repeat('a',64),$id,(string)$time,$sig,$time],[$body,$key,$id,(string)$time,$sig,$time+301],[$body,$key,$id,(string)$time,$sig,$time-301],[$body,$key,$id,'0',$sig,$time],[$body,$key,$id,(string)$time,'sha256=bad',$time]] as $args)$reject(401,fn()=>ConnectorEventPayload::verify(...$args));
foreach(ConnectorEventPayload::TYPES as $type){$p=ConnectorEventPayload::parse(json_encode(['event_id'=>'evt-2','type'=>$type,'resource_id'=>'host:1']));$ok($p['type']===$type,'supported type');}
foreach(['[]','{}','{"event_id":"e","type":"resource.updated"}','{"event_id":"e","type":"vm.start","resource_id":"1"}','{"event_id":"e","type":"inventory.refresh","workspace_id":"other"}','{"event_id":"e","type":"inventory.refresh","credential":"secret"}','{"event_id":"bad space","type":"inventory.refresh"}','{"event_id":"e","type":"resource.updated","resource_id":[]}','{"event_id":"e","type":"resource.updated","resource_id":"bad\nname"}'] as $bad)$reject(422,fn()=>ConnectorEventPayload::parse($bad));
$reject(400,fn()=>ConnectorEventPayload::parse('{'));$reject(413,fn()=>ConnectorEventPayload::parse(str_repeat('x',16385)));
$root=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));$next=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));$crypto=new VaultCrypto($root);
$cipher=$crypto->seal($key,$id,$ws,'connector_webhook');$ok(!str_contains($cipher,$key)&&$crypto->open($cipher,$id,$ws,'connector_webhook')===$key,'encrypted signing key');
$rewrapped=$crypto->rewrap($cipher,$id,$ws,'connector_webhook',$next);$ok((new VaultCrypto($next))->open($rewrapped,$id,$ws,'connector_webhook')===$key,'key rotation');
$ok(count((new SqlStatementParser())->split(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0027_connector_events.sql')))===6,'migration statements');
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');$app=(new Application(dirname(__DIR__)))->boot();
try {$ok($app->container()->get(ConnectorEventService::class) instanceof ConnectorEventService,'DI');$base='/api/v1/connectors/targets/'.$id;
foreach([['GET','/webhook-sources'],['POST','/webhook-sources'],['POST','/webhook-sources/'.$id.'/rotate'],['DELETE','/webhook-sources/'.$id],['GET','/events'],['POST','/events/'.$id.'/redrive']] as [$method,$path]){$r=$app->handle(new HttpRequest($method,$base.$path));$ok(in_array($r->status,[400,401,415,422,503],true),'unauthenticated route denied');}
$r=$app->handle(new HttpRequest('POST','/api/v1/connectors/webhooks/'.$id.'/events'));$ok(in_array($r->status,[401,415,503],true),'public route registered');
}finally{$app->terminate();}
echo "Connector events smoke: $n PASS\n";
