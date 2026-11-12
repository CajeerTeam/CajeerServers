<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorTransportException,EndpointPolicy,RouterConnector,SnmpTransport};
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
    $ok($item['fields']['name']==='cajeer-snmp-fixture','sysName');
    $ok(count($item['fields']['attributes']['network_device']['interfaces'])>0,'real IF-MIB walk');
    $ok(in_array('127.0.0.1',$item['fields']['ip_addresses']??[],true),'real IP-MIB walk');
    $ok(!$result->complete&&$item['external_id']==='device','stable partial observation');
    foreach(['health','validate_rotation'] as $op)$ok($registry->execute($ctx,$op,'snmp',$secret)->items[0]['fields']['name']==='cajeer-snmp-fixture',$op);
}
$reject(fn()=>$registry->execute($v2,'health','snmp','{"community":"bad-community"}'));
$reject(fn()=>$registry->execute($v3,'health','snmp',str_replace('cajeer-test-auth','bad-passphrase',$c3)));
$reject(fn()=>(new ConnectorRegistry(new RouterConnector(new SnmpTransport($policy))))->execute($v2,'health','snmp',$c2));
echo "Native SNMPv2c / SNMPv3 authPriv: $n PASS\n";
