<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorTransportException,EndpointPolicy,RouterConnector,SnmpTransport,SnmpSession};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;
if (!extension_loaded('snmp')||!isset($argv[1])||!ctype_digit($argv[1])||(int)$argv[1]<1024||(int)$argv[1]>65535) exit(2);
$port=(int)$argv[1];$policy=new EndpointPolicy(['127.0.0.1/32']);
$registry=new ConnectorRegistry(new RouterConnector(new SnmpTransport($policy,true)));
$v2=new ConnectorContext('snmp','127.0.0.1',$port,'snmp_v2c');$v3=new ConnectorContext('snmp','127.0.0.1',$port,'snmp_v3');
$c2=json_encode(['community'=>'cajeer-test-community']);$c3=json_encode(['username'=>'cajeer-reader','auth_protocol'=>'sha256','auth_passphrase'=>'cajeer-test-auth','privacy_protocol'=>'aes128','privacy_passphrase'=>'cajeer-test-priv']);
$n=0;$ok=static function(bool $b,string $label)use(&$n):void{if(!$b)throw new RuntimeException($label);$n++;};
$reject=static function(callable $f)use($ok):void{try{$f();}catch(HttpException){$ok(true,'expected failure');return;}throw new RuntimeException('Expected SNMP failure');};
if (($argv[2]??'')==='--offline') {$reject(fn()=>$registry->execute($v2,'health','snmp',$c2));echo "SNMP offline PASS\n";exit;}
foreach ([[$v2,$c2],[$v3,$c3]] as [$ctx,$secret]) {
    $result=$registry->execute($ctx,'sync','snmp',$secret);$item=$result->items[0];
    $runtime=new \Cajeer\Servers\Collector\CollectionRuntimeService(\Cajeer\Servers\Collector\CollectorRegistry::forConnectors($registry));
    $collected=$runtime->collect(new \Cajeer\Servers\Collector\CollectionPlan('snmp.inventory','inspect'),['provider'=>'snmp','endpoint_host'=>'127.0.0.1','endpoint_port'=>$port,'credential_type'=>$ctx->credentialType,'purpose'=>'snmp'],$ctx->credentialType,$secret);
    $ok($collected->items[0]['external_id']==='device','named SNMP collector');
    $ok($item['fields']['name']==='cajeer-snmp-fixture','sysName');
    $ok(count($item['fields']['attributes']['network_device']['interfaces'])>0,'real IF-MIB walk');
    $ok(in_array('127.0.0.1',$item['fields']['ip_addresses']??[],true),'real IP-MIB walk');
    $ok(!$result->complete&&$item['external_id']==='device','stable partial observation');
    foreach(['health','validate_rotation'] as $op)$ok($registry->execute($ctx,$op,'snmp',$secret)->items[0]['fields']['name']==='cajeer-snmp-fixture',$op);
}
$session=new SnmpSession('127.0.0.1',$port,'snmp_v3',json_decode($c3,true,16,JSON_THROW_ON_ERROR));
try{$profile=['engine_id'=>bin2hex($session->get('.1.3.6.1.6.3.10.2.1.1.0')),'object_id'=>'.'.ltrim($session->get('.1.3.6.1.2.1.1.2.0'),'.')];}finally{$session->close();}
$trusted=new ConnectorContext('snmp','127.0.0.1',$port,'snmp_v3',null,null,$profile);
$strict=new ConnectorRegistry(new RouterConnector(new SnmpTransport($policy,true,null,true)));
foreach(['health','inspect','sync','validate_rotation'] as $op)$ok($strict->execute($trusted,$op,'snmp',$c3)->connection['profile_verified'],'native profile '.$op);
$runtime=new \Cajeer\Servers\Collector\CollectionRuntimeService(\Cajeer\Servers\Collector\CollectorRegistry::forConnectors($strict));
$target=['provider'=>'snmp','endpoint_host'=>'127.0.0.1','endpoint_port'=>$port,'credential_type'=>'snmp_v3','purpose'=>'snmp','os_network_profile'=>$profile];
foreach(['device','interfaces','topology'] as $scope){
    $result=$runtime->collect(new \Cajeer\Servers\Collector\CollectionPlan('snmp.'.$scope,'inspect'),$target,'snmp_v3',$c3);
    $snapshot=$result->connection['snmp_discovery'];$ok($snapshot['scope']===$scope&&!$snapshot['complete']&&$result->connection['profile_verified'],'native advanced '.$scope);
    if($scope==='interfaces')$ok(count($snapshot['sections']['interfaces'])>0&&in_array('127.0.0.1',array_column($snapshot['sections']['addresses'],'address'),true),'native advanced IF/IP-MIB');
}
$reject(fn()=>$strict->execute($v3,'health','snmp',$c3));
$reject(fn()=>$strict->execute(new ConnectorContext('snmp','127.0.0.1',$port,'snmp_v3',null,null,array_replace($profile,['engine_id'=>'800000000000'])),'health','snmp',$c3));
$reject(fn()=>$strict->execute(new ConnectorContext('snmp','127.0.0.1',$port,'snmp_v2c',null,null,$profile),'health','snmp',$c2));
$reject(fn()=>$registry->execute($v2,'health','snmp','{"community":"bad-community"}'));
$reject(fn()=>$registry->execute($v3,'health','snmp',str_replace('cajeer-test-auth','bad-passphrase',$c3)));
$reject(fn()=>(new ConnectorRegistry(new RouterConnector(new SnmpTransport($policy))))->execute($v2,'health','snmp',$c2));
echo "Native SNMPv2c / SNMPv3 authPriv: $n PASS\n";
