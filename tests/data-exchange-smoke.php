<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\ImportExport\{ImportManager,ExporterRegistry,ExportManifest,BuiltinExporter};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Collector\WebhookCollector;
$n=0;$ok=static function(bool $v,string $s)use(&$n){if(!$v)throw new RuntimeException($s);$n++;};
$reject=static function(int $status,callable $f)use($ok){try{$f();}catch(HttpException $e){$ok($e->status===$status,'expected '.$status.' got '.$e->status);return;}throw new RuntimeException('Expected rejection');};
$source='11111111-1111-4111-8111-111111111111';$now=gmdate('Y-m-d\TH:i:s\Z');$csv="metric,value,kind,observed_at,labels\nnetwork.bytes,18446744073709551615,counter,$now,\"{\"\"iface\"\":\"\"eth0\"\"}\"";
$body=ImportManager::envelope('csv','metrics','m1',$csv);$rows=ImportManager::preview($body,'metrics',$source)['records'];$ok($rows[0]['value']==='18446744073709551615','CSV exact counter');$ok($rows[0]['labels']['iface']==='eth0'&&$rows[0]['labels']['cajeer_source']===$source,'source identity');
$json=json_encode([['event_id'=>'e1','type'=>'system.changed','message'=>'=1+2','observed_at'=>$now]],JSON_THROW_ON_ERROR);$body=ImportManager::envelope('json','events','e1',$json);$e=ImportManager::preview($body,'events',$source)['records'];$ok($e[0]['message']==='=1+2','event import');
$reject(422,fn()=>ImportManager::envelope('csv','events','e1',"event_id,type\ne1,foo,extra"));$reject(422,fn()=>ImportManager::envelope('json','events','e1','[{"event_id":"x","event_id":"y"}]'));
$reject(422,fn()=>ImportManager::envelope('json','events','e1','[]'));$reject(422,fn()=>ImportManager::envelope('json','metrics','bad key',$json));$reject(413,fn()=>ImportManager::envelope('csv','metrics','m1',str_repeat('a',262145)));
$invalid=ImportManager::envelope('json','events','e2',json_encode([['event_id'=>'e1','type'=>'system.changed','message'=>'ok','observed_at'=>$now],['event_id'=>'e2','type'=>'bad type','message'=>'bad','observed_at'=>$now]]));try{ImportManager::preview($invalid,'events',$source);throw new RuntimeException('missing row error');}catch(HttpException $x){$ok($x->errors[0]['field']==='records.2','row error report');}
$duplicate=ImportManager::envelope('json','events','e3',json_encode([$e[0],$e[0]]));$reject(422,fn()=>ImportManager::preview($duplicate,'events',$source));
$badMetrics=ImportManager::envelope('json','metrics','bad-metrics',json_encode([['metric'=>'good','value'=>'1','labels'=>(object)['x'=>'y'],'observed_at'=>$now],['metric'=>'bad metric','value'=>'2','observed_at'=>$now]]));
try{ImportManager::preview($badMetrics,'metrics',$source);throw new RuntimeException('missing metric error');}catch(HttpException $x){$ok(count($x->errors)===1&&$x->errors[0]['field']==='records.2','row diagnostics do not mutate valid labels');}
$normalized=(new \Cajeer\Servers\Script\ScriptOutputParser())->parse(json_encode([['metric'=>'script.cpu','value'=>12.5,'observed_at'=>$now]]),['parser'=>'json','output_schema'=>'metrics'])['normalized'];
$body=ImportManager::envelope('json','metrics','script-result',json_encode($normalized));$ok(ImportManager::preview($body,'metrics',$source)['records'][0]['value']==='12.5','normalized script result import');
$reject(422,fn()=>ImportManager::envelope('json','events','script-result',json_encode($normalized)));
$reg=new ExporterRegistry();$r=$rows[0]+['series_id'=>$source,'asset_id'=>$source,'target_version'=>1,'ingestion_source_id'=>$source];$r['labels']=(object)$r['labels'];$j=$reg->require('json','metrics')->encode('metrics',[$r]);$ok(json_decode($j,true)['records'][0]['value']==='18446744073709551615','JSON exact decimal');
$c=$reg->require('csv','events')->encode('events',[$e[0]+['source_id'=>$source]]);$ok(str_contains($c,"\"'=1+2\""),'CSV formula protection');
$c=$reg->require('csv','metrics')->encode('metrics',[$r]);$ok(str_contains($c,'18446744073709551615'),'CSV exact decimal');
$o=$reg->require('openmetrics','metrics')->encode('metrics',[$r]);$ok(str_contains($o,'# TYPE cajeer_infrastructure_counter counter')&&str_contains($o,'cajeer_infrastructure_counter_total{')&&str_ends_with($o,"# EOF\n"),'OpenMetrics counter and EOF');
$ok(str_contains($o,'18446744073709551615'),'OpenMetrics exact counter');$reject(422,fn()=>$reg->require('openmetrics','events'));$reject(422,fn()=>$reg->require('unknown','metrics'));
$reg2=new ExporterRegistry(dirname(__DIR__).'/examples/extensions/data-exporter/entry.php');$nd=$reg2->require('ndjson','events')->encode('events',$e);$ok(json_decode(trim($nd),true)['event_id']==='e1','real SDK extension');$ok(count($reg2->formats())===4,'extension discovery');
$reject(422,fn()=>$reg->require('json','events')->encode('events',(function(){for($i=0;$i<10;$i++)yield ['message'=>str_repeat('x',500000)];})()));
$m=ExportManifest::validate(['kind'=>'metrics','format'=>'json','from'=>gmdate('Y-m-d\TH:i:s\Z',time()-3600),'to'=>$now,'series_id'=>$source]);$ok($m['series_id']===$source&&str_ends_with($m['to'],'.000000Z'),'canonical request');
$reject(422,fn()=>ExportManifest::validate(['kind'=>'events','format'=>'json','from'=>$now,'to'=>$now]));$reject(422,fn()=>ExportManifest::validate(['kind'=>'metrics','format'=>'json','from'=>'2026-02-30T00:00:00Z','to'=>$now]));
$app=(new \Cajeer\Servers\Kernel\Application(dirname(__DIR__)))->boot();try{$ok($app->container()->get(\Cajeer\Servers\ImportExport\ExportManager::class) instanceof \Cajeer\Servers\ImportExport\ExportManager,'DI');$routes=$app->container()->get(\Cajeer\Servers\Http\Router::class)->routes();$ok(count(array_filter($routes,fn($r)=>str_contains($r['path'],'/data-exports')||str_contains($r['path'],'/ingestion-sources/')&&str_contains($r['path'],'/imports/preview')||str_ends_with($r['path'],'/imports')&&str_contains($r['path'],'/ingestion-sources/')))===7,'seven routes');}finally{$app->terminate();}
echo "Data exchange parsers, exporters, SDK and DI: $n PASS\n";
