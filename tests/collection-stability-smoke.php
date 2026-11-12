<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Script\ScriptOutputParser;
use Cajeer\Servers\ImportExport\{BuiltinExporter,ImportManager};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Metrics\MetricBatch;
use Cajeer\Servers\Connector\ConnectorTransportException;
use Cajeer\Servers\Collector\CollectionReadiness;
use Cajeer\Servers\Kernel\Application;
$n=0;
$ok=static function(bool $yes,string $name)use(&$n):void{if(!$yes)throw new RuntimeException('FAIL '.$name);$n++;};
$reject=static function(callable $f)use($ok):void{try{$f();}catch(HttpException|ConnectorTransportException){$ok(true,'rejected');return;}throw new RuntimeException('Expected rejection');};
$parser=new ScriptOutputParser();$parse=fn($raw,$format)=>$parser->parse($raw,['parser'=>$format,'output_schema'=>'metrics'])['normalized'];
$big='18446744073709551615';$now=gmdate('Y-m-d\TH:i:s\Z');
foreach(['json'=>'{"metric":"n","value":'.$big.'}','csv'=>"metric,value\nn,$big",'key_value'=>"metric=n\nvalue=$big",'prometheus'=>"n $big"] as $fmt=>$raw){
    $v=$parse($raw,$fmt);$ok($v['metric_samples'][0]['value']===$big,'exact '.$fmt);
}
$decimal='0.1234567890123456789012345';$ok($parse("metric,value\nn,$decimal",'csv')['metric_samples'][0]['value']===$decimal,'precise decimal');
$ok($parse('{"metric":"n","value":"'.$big.'","kind":"counter"}','json')['metric_samples'][0]['kind']==='counter','typed script counter');
$reject(fn()=>$parse('{"metric":"n","value":1e20}','json'));
$reject(fn()=>$parse('{"metric":"n","value":"-1","kind":"counter"}','json'));
$reject(fn()=>MetricBatch::normalize([MetricBatch::sample('n',INF)],$now));
$reject(fn()=>MetricBatch::normalize([MetricBatch::sample('n',1.2e20)],$now));
$origin='11111111-1111-4111-8111-111111111111';$destination='22222222-2222-4222-8222-222222222222';
$record=['series_id'=>$origin,'asset_id'=>$origin,'target_version'=>1,'ingestion_source_id'=>$origin,'metric'=>'net.bytes','value'=>$big,'kind'=>'counter','unit'=>'bytes','resource_external_id'=>'eth0','labels'=>(object)['cajeer_source'=>$origin,'device'=>'eth0'],'observed_at'=>$now];
$export=new BuiltinExporter('json');$json=$export->encode('metrics',[$record]);
$body=ImportManager::envelope('json','metrics','roundtrip',$json);$r=ImportManager::preview($body,'metrics',$destination)['records'][0];
$ok($r['value']===$big&&$r['kind']==='counter','exact archive roundtrip');
$ok($r['labels']['cajeer_source']===$destination&&!isset($r['asset_id'],$r['series_id'],$r['ingestion_source_id']),'fresh source provenance');
$reject(fn()=>ImportManager::envelope('json','events','wrong',$json));
$reject(fn()=>ImportManager::envelope('json','metrics','mixed',$export->encode('metrics',[$record,array_replace($record,['series_id'=>$destination,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-10)])])));
$reject(fn()=>ImportManager::envelope('json','metrics','wrong-source',$export->encode('metrics',[array_replace($record,['ingestion_source_id'=>$destination])])));
$reject(fn()=>ImportManager::envelope('json','metrics','forged',$export->encode('metrics',[$record+['workspace_id'=>$destination]])));
$event=['source_id'=>$origin,'event_id'=>'e1','type'=>'system.changed','severity'=>'info','message'=>'=1+2','observed_at'=>$now];
$evt=ImportManager::preview(ImportManager::envelope('json','events','event-roundtrip',$export->encode('events',[$event])),'events',$destination)['records'][0];
$ok($evt['message']==='=1+2'&&!isset($evt['source_id']),'event JSON roundtrip');
$obs=['batch_id'=>$origin,'source_id'=>$origin,'received_at'=>$now,'external_id'=>'manual-host','type'=>'server','fields'=>(object)['name'=>'host','status'=>'online'],'observed_at'=>$now];
$o=ImportManager::preview(ImportManager::envelope('json','observations','obs-roundtrip',$export->encode('observations',[$obs])),'observations',$destination)['records'][0];
$ok($o['external_id']==='manual-host'&&!isset($o['batch_id'],$o['source_id']),'observation JSON roundtrip');
$metric=new BuiltinExporter('openmetrics');$g=array_replace($record,['kind'=>'gauge','value'=>'2','series_id'=>$destination]);
$ok(substr_count($metric->encode('metrics',[$record,$g]),'# TYPE')===2,'valid grouped families');
$reject(fn()=>$metric->encode('metrics',[$record,$g,$record]));
$app=(new Application(dirname(__DIR__)))->boot();try{
    $report=(new CollectionReadiness($app))->report();$checks=array_column($report['checks'],null,'name');
    $ok($checks['collection_registry']['ok'],'real DI registry');
    $ok(isset($checks['collection_migrations'],$checks['collection_export_integrity'],$checks['collection_ingestion_attempts']),'readiness probes');
    if(!extension_loaded('pdo_pgsql'))$ok(!$report['ok']&&!$checks['collection_migrations']['ok'],'missing database fails readiness');
    $service=$app->container()->get(\Cajeer\Servers\Script\ScriptExecution::class);
    try{$service->retain(0);throw new RuntimeException('Expected invalid limit');}catch(InvalidArgumentException){$ok(true,'bounded retention');}
}finally{$app->terminate();}
echo "Collection stability: $n PASS\n";
