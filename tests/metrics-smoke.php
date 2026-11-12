#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Metrics\{MetricBatch,HostMetrics};
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorTransportException,EndpointPolicy,SnmpTransport,RouterConnector,ProxmoxConnector};
use Cajeer\Servers\Collector\{CollectorRegistry,CollectionRuntimeService,CollectionPlan,CollectionResult};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;
$n=0;$ok=static function(bool $v,string $label)use(&$n):void{if(!$v)throw new RuntimeException('FAIL '.$label);$n++;};
$bad=static function(callable $fn)use($ok):void{try{$fn();}catch(ConnectorTransportException|HttpException $e){$ok(true,'expected rejection');return;}throw new RuntimeException('Expected rejection');};
$at=gmdate('Y-m-d\TH:i:s\Z');$x=MetricBatch::sample('net.bytes','18446744073709551615','device',['z'=>'last','a'=>'first'],'counter','bytes');
$r=MetricBatch::normalize([$x],$at);$ok($r[0]['value']==='18446744073709551615'&&array_keys($r[0]['labels'])===['a','z'],'exact Counter64 and labels');
foreach([['value'=>'NaN'],['value'=>'-1'],['value'=>'1e999'],['metric'=>'bad name'],['labels'=>['__name'=>'x']],['observed_at'=>'2020-01-01T00:00:00Z']] as $patch)$bad(fn()=>MetricBatch::normalize([array_replace($x,$patch)],$at));
$bad(fn()=>MetricBatch::normalize([$x,$x],$at));
$fixture=static function(string $url,string $auth):string{if(!str_starts_with($auth,'PVEAPIToken='))throw new RuntimeException('auth');return str_contains($url,'/nodes')?' {"data":[{"node":"pve","status":"online","cpu":0.25,"mem":1024}]}':'{"data":[{"node":"pve","type":"qemu","vmid":101,"status":"running","netin":"18446744073709551615","netout":100}]}';};
$registry=new ConnectorRegistry(new ProxmoxConnector($fixture));$runtime=new CollectionRuntimeService(CollectorRegistry::forConnectors($registry));
$target=['provider'=>'proxmox','endpoint_host'=>'127.0.0.1','endpoint_port'=>8006,'credential_type'=>'proxmox_api','purpose'=>'api'];
$secret='{"token_id":"svc@pve!test","token_secret":"fixture-secret"}';$p=new CollectionPlan('proxmox.metrics','inspect');$res=$runtime->collect($p,$target,'proxmox_api',$secret);$ok($res->items===[]&&count($res->metricSamples)===4,'actual metric driver and no observations');
$collection=(new CollectionResult($p,$res,$at))->toArray();$ok($collection['sink']==='metrics'&&$collection['count']===4&&$collection['metric_samples'][2]['value']==='18446744073709551615','collection serialization');
$bad(fn()=>$runtime->collect(new CollectionPlan('proxmox.metrics','sync'),$target,'proxmox_api',$secret));
$factory=static fn()=>new class {public function get(string $oid):string{return '12345';}public function next(string $oid):array{return match($oid){'.1.3.6.1.2.1.31.1.1.1.6'=>[$oid.'.2','18446744073709551615'],'.1.3.6.1.2.1.31.1.1.1.10'=>[$oid.'.2','10'],'.1.3.6.1.2.1.2.2.1.14'=>[$oid.'.2','2'],'.1.3.6.1.2.1.2.2.1.20'=>[$oid.'.2','3'],default=>[null,null]};}public function close():void{}};
$snmp=new RouterConnector(new SnmpTransport(new EndpointPolicy(['127.0.0.1/32']),true,$factory));$samples=$snmp->metrics(new ConnectorContext('snmp','127.0.0.1',161,'snmp_v2c'),'{"community":"fixture"}');$ok(count($samples)===5&&$samples[1]['value']==='18446744073709551615','real bounded SNMP collector with protocol fixture');
$raw=shell_exec('python3 '.escapeshellarg(dirname(__DIR__).'/core/Connector/agents/linux_metrics.py'));$rows=HostMetrics::normalize(json_decode((string)$raw,true,16,JSON_THROW_ON_ERROR),'ssh');$ok(count($rows)>=5&&$rows[0]['metric']==='system.cpu.utilization','actual Linux /proc collector');
$bad(fn()=>HostMetrics::normalize(['identity'=>'bad'],'ssh'));
$app=(new \Cajeer\Servers\Kernel\Application(dirname(__DIR__)))->boot();try{$catalog=$app->container()->get(CollectionRuntimeService::class)->catalog();$names=array_column($catalog,'name');foreach(['proxmox','vmmanager','ssh','winrm','snmp','prometheus'] as $provider)$ok(in_array($provider.'.metrics',$names,true),'registered '.$provider);$router=$app->container()->get(\Cajeer\Servers\Http\Router::class);$ok(count(array_filter($router->routes(),fn($r)=>str_contains($r['path'],'/metric-series')))===2,'two authenticated query routes');}finally{$app->terminate();}
$ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0033_metric_storage.sql')))===12,'migration SQL split');
echo "Metrics smoke: $n PASS\n";
