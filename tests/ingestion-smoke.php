<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Collector\WebhookCollector;
use Cajeer\Servers\Connector\ConnectorEventPayload;
use Cajeer\Servers\Security\SignedPayload;
use Cajeer\Servers\Http\Exception\HttpException;
$n=0;$ok=static function(bool $ok,string $label)use(&$n){if(!$ok)throw new RuntimeException($label);$n++;};
$reject=static function(int $status,callable $fn)use($ok){try{$fn();}catch(HttpException $e){$ok($e->status===$status,'expected '.$status.' got '.$e->status);return;}throw new RuntimeException('Expected rejection');};
$c=new WebhookCollector();$id='11111111-1111-4111-8111-111111111111';$now=gmdate('Y-m-d\TH:i:s\Z');$secret=bin2hex(random_bytes(32));$ts=time();
$body=static fn(string $kind,array $rows,string $batch='batch-1')=>json_encode(['schema_version'=>1,'batch_id'=>$batch,'kind'=>$kind,'records'=>$rows],JSON_THROW_ON_ERROR);
$sample=['metric'=>'network.bytes','value'=>'18446744073709551615','kind'=>'counter','labels'=>(object)['interface'=>'eth0'],'observed_at'=>$now];
$raw=$body('metrics',[$sample]);$signature=SignedPayload::signature($id."\n".$raw,$secret,$ts);ConnectorEventPayload::verify($raw,$secret,$id,(string)$ts,$signature);$ok(true,'HMAC accepted');
$reject(401,fn()=>ConnectorEventPayload::verify($raw.' ',$secret,$id,(string)$ts,$signature));$reject(401,fn()=>ConnectorEventPayload::verify($raw,$secret,$id,(string)($ts-301),SignedPayload::signature($id."\n".$raw,$secret,$ts-301)));
$reject(401,fn()=>ConnectorEventPayload::verify($raw,$secret,'22222222-2222-4222-8222-222222222222',(string)$ts,$signature));
$m=$c->parse($raw,'metrics',$id);$ok($m['records'][0]['value']==='18446744073709551615','Counter64 exact');$ok($m['records'][0]['labels']['cajeer_source']===$id,'source namespace');$ok($m['digest']===hash('sha256',$raw),'raw digest');
$reject(422,fn()=>$c->parse($raw,'events',$id));$reject(422,fn()=>$c->parse(str_replace('"schema_version":1','"schema_version":1,"schema_version":1',$raw),'metrics',$id));
foreach([['value'=>'NaN'],['value'=>1e20],['value'=>'-1'],['labels'=>(object)['cajeer_source'=>'spoof']],['labels'=>['x']],['observed_at'=>null],['observed_at'=>'2020-01-01T00:00:00Z'],['workspace_id'=>$id],['metric'=>'bad name']] as $patch)$reject(422,fn()=>$c->parse($body('metrics',[array_replace($sample,$patch)]),'metrics',$id));
$reject(422,fn()=>$c->parse($body('metrics',[$sample,$sample]),'metrics',$id));$reject(422,fn()=>$c->parse($body('metrics',[]),'metrics',$id));$reject(413,fn()=>$c->parse(str_repeat('x',262145),'metrics',$id));
$event=['event_id'=>'e1','type'=>'resource.deleted','message'=>'External notice only','severity'=>'warning','observed_at'=>$now];$e=$c->parse($body('events',[$event]),'events',$id);$ok($e['records'][0]['type']==='resource.deleted','event parsed without dispatch');$reject(422,fn()=>$c->parse($body('events',[$event,$event]),'events',$id));$reject(422,fn()=>$c->parse($body('events',[array_replace($event,['severity'=>'bogus'])]),'events',$id));
$obs=['external_id'=>'external-host','type'=>'server','fields'=>(object)['name'=>'host','status'=>'online'],'observed_at'=>$now];$o=$c->parse($body('observations',[$obs]),'observations',$id);$ok($o['records'][0]['external_id']==='external-host','observation normalized');$reject(422,fn()=>$c->parse($body('observations',[array_replace($obs,['workspace_id'=>$id])]),'observations',$id));
$app=(new \Cajeer\Servers\Kernel\Application(dirname(__DIR__)))->boot();try{$ok($app->container()->get(\Cajeer\Servers\Ingestion\IngestionService::class) instanceof \Cajeer\Servers\Ingestion\IngestionService,'DI');$routes=$app->container()->get(\Cajeer\Servers\Http\Router::class)->routes();$ok(count(array_filter($routes,fn($r)=>str_contains($r['path'],'/ingestion')||str_contains($r['path'],'/data-events')))===9,'nine registered routes');}finally{$app->terminate();}
echo "Ingestion parser, HMAC, scope and DI: $n PASS\n";
