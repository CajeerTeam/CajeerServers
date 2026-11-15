#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorInterface,ConnectorManifest,ConnectorContext,ConnectorResult,ConnectorTransportException,EndpointPolicy,ConnectorHealthMonitor};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;
$n=0;
$ok=static function(bool $v,string $label)use(&$n):void {if(!$v)throw new RuntimeException($label);$n++;};
$reject=static function(callable $f,string $code)use($ok):void {try{$f();}catch(HttpException|ConnectorTransportException $e){$ok(($e instanceof HttpException?$e->errorCode:$e->category)===$code,'wrong error '.$code);return;}throw new RuntimeException('Expected '.$code);};
$driver=new class implements ConnectorInterface {
    public int $calls=0; public bool $changed=false; public array $items=[];
    public function manifest():ConnectorManifest {return new ConnectorManifest('custom','custom',['generic_secret'],$this->changed?['inspect']:['inspect','sync','health','validate_rotation'],['server'],'https');}
    public function execute(ConnectorContext $context,string $operation,string $secret):ConnectorResult {$this->calls++;return new ConnectorResult($this->items,true);}
};
$r=new ConnectorRegistry($driver);$ctx=new ConnectorContext('custom','host.internal',443,'generic_secret');
$reject(fn()=>$r->execute($ctx,'health','custom','secret'),'connector_protocol_error');
$reject(fn()=>$r->execute($ctx,'validate_rotation','custom','secret'),'connector_protocol_error');
$ok(!$r->execute($ctx,'inspect','custom','secret')->complete,'empty discovery is never complete');
$good=['type'=>'server','external_id'=>'x','fields'=>['name'=>'test']];$driver->items=[$good];
$ok($r->execute($ctx,'sync','custom','secret')->items[0]['external_id']==='x','valid result');
$driver->items=[$good,$good];$reject(fn()=>$r->execute($ctx,'sync','custom','secret'),'connector_protocol_error');
$driver->changed=true;$before=$driver->calls;$reject(fn()=>$r->execute($ctx,'inspect','custom','secret'),'provider_unavailable');
$ok($driver->calls===$before&&$r->manifests()['custom']->supports('sync'),'manifest cannot drift');
$reject(fn()=>(new EndpointPolicy(['10.0.0.0/8'],static fn()=>[]))->resolve('test.internal'),'connector_transport_failed');
$reject(fn()=>(new EndpointPolicy(['10.0.0.0/8'],static fn()=>['192.0.2.1']))->resolve('test.internal'),'connector_trust_failed');
// Localhost is reserved: an unavailable or hostile DNS resolver must never
// participate in loopback resolution, including in integration runners.
$dnsCalls=0;$dns=static function(string $host)use(&$dnsCalls):array{$dnsCalls++;return ['192.0.2.1'];};
$ok((new EndpointPolicy(['127.0.0.1/32'],$dns,true))->resolve('LOCALHOST')==='127.0.0.1','IPv4 localhost without DNS');
$ok((new EndpointPolicy(['::1/128'],$dns,true))->resolve('localhost')==='::1','IPv6-only localhost without DNS');
$ok((new EndpointPolicy(['127.0.0.1/32','::1/128'],$dns,true))->resolve('fixture.localhost')==='127.0.0.1','reserved localhost suffix and stable preference');
$reject(fn()=>(new EndpointPolicy(['10.0.0.0/8'],$dns,true))->resolve('localhost'),'connector_trust_failed');
$reject(fn()=>(new EndpointPolicy([],$dns))->resolve('localhost'),'connector_trust_failed');
$ok($dnsCalls===0,'no external resolver for localhost');
$ok(ConnectorHealthMonitor::outcome('connector_failed',2)['status']==='degraded','unclassified remote failure recorded');
echo "Connector stability smoke: $n PASS\n";
