#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorHealthMonitor,EndpointPolicy,RouterConnector,SnmpTransport,ConnectorTransportException};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Inventory\ObservationInput;
use Cajeer\Servers\Database\SqlStatementParser;
$n=0;$ok=static function(bool $v,string $label)use(&$n):void {if(!$v)throw new RuntimeException('FAIL: '.$label);$n++;};
$reject=static function(callable $f,string $label)use($ok):void {try{$f();}catch(Throwable){$ok(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);};
// Fixture speaks the same GET/GETNEXT session contract. No credentials or arbitrary OIDs enter the result.
final class FixtureSnmp {
    public bool $closed=false;public int $calls=0;public bool $cycle=false;public bool $badStatus=false;public bool $oversize=false;
    public function get(string $oid):string {$this->calls++;return match($oid){'.1.3.6.1.2.1.1.5.0'=>'router-lab','.1.3.6.1.2.1.1.2.0'=>'.1.3.6.1.4.1.8072.3.2.10','.1.3.6.1.2.1.2.1.0'=>'2','.1.3.6.1.2.1.1.1.0'=>"Test device\nOS",default=>throw new RuntimeException('Unexpected OID')};}
    public function next(string $oid):array {
        $this->calls++;if($this->cycle)return [$oid,'1'];
        $data=['.1.3.6.1.2.1.2.2.1.2.2'=>'eth0','.1.3.6.1.2.1.2.2.1.2.7'=>'eth7','.1.3.6.1.2.1.2.2.1.8.2'=>'1','.1.3.6.1.2.1.2.2.1.8.7'=>$this->badStatus?'unknown':'2','.1.3.6.1.2.1.4.20.1.2.192.0.2.10'=>'2','.1.3.6.1.2.1.4.34.1.3.2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1'=>'7','.1.3.6.1.2.1.5.1.0'=>'0'];
        if($this->oversize)for($i=8;$i<73;$i++)$data['.1.3.6.1.2.1.2.2.1.2.'.$i]='x'.$i;
        uksort($data,fn($a,$b)=>array_map('intval',explode('.',$a))<=>array_map('intval',explode('.',$b)));
        // Numeric lexicographic OID comparison, not PHP array-length comparison.
        $cmp=static function(string $a,string $b):int{$a=explode('.',$a);$b=explode('.',$b);for($i=0;$i<min(count($a),count($b));$i++)if((int)$a[$i]!== (int)$b[$i])return (int)$a[$i]<=>(int)$b[$i];return count($a)<=>count($b);};
        uksort($data,$cmp);foreach($data as $k=>$v)if($cmp($k,$oid)>0)return [$k,$v];throw new RuntimeException('Fixture end');
    }
    public function close():void {$this->closed=true;}
}
$session=new FixtureSnmp();$factory=static function(string $ip,int $port,string $type,array $credential)use(&$session,$ok):object {$ok($ip==='127.0.0.1'&&$port===1161,'pinned endpoint');return $session;};
$policy=new EndpointPolicy(['127.0.0.1/32']);$transport=new SnmpTransport($policy,false,$factory);$driver=new RouterConnector($transport);$registry=new ConnectorRegistry($driver);
$ctx=new ConnectorContext('snmp','127.0.0.1',1161,'snmp_v3');$secret=json_encode(['username'=>'reader','auth_protocol'=>'sha256','auth_passphrase'=>'test-auth-pass','privacy_protocol'=>'aes128','privacy_passphrase'=>'test-priv-pass']);
$result=$registry->execute($ctx,'sync','snmp',$secret);$item=$result->items[0];$attrs=$item['fields']['attributes']['network_device'];
$ok(!$result->complete&&$item['external_id']==='device'&&$item['type']==='router','partial stable device identity');
$ok($attrs['interfaces'][0]['index']===2&&$attrs['interfaces'][1]['oper_status']===2,'IF-MIB status');
$ok($item['fields']['ip_addresses']===['192.0.2.10','2001:db8::1'],'IPv4 and IPv6 collected');
$ok($attrs['description']==='Test device OS'&&$session->closed,'normalized and closed');
$ok(!str_contains(json_encode($item),'test-auth-pass'),'secret absent');
$session=new FixtureSnmp();$registry->execute($ctx,'health','snmp',$secret);$ok($session->calls===2&&$session->closed,'health uses only two GETs');
$session=new FixtureSnmp();$registry->execute($ctx,'validate_rotation','snmp',$secret);$ok($session->calls===2,'rotation uses read-only probe');
$reject(fn()=>$registry->execute(new ConnectorContext('snmp','127.0.0.1',1161,'snmp_v2c'),'health','snmp','{"community":"test"}'),'legacy disabled');
$legacy=new ConnectorRegistry(new RouterConnector(new SnmpTransport($policy,true,$factory)));$session=new FixtureSnmp();$ok(count($legacy->execute(new ConnectorContext('snmp','127.0.0.1',1161,'snmp_v2c'),'sync','snmp','{"community":"test"}')->items)===1,'legacy explicit opt-in');
$reject(fn()=>$registry->execute($ctx,'sync','snmp',json_encode(['username'=>'reader','auth_protocol'=>'sha256','auth_passphrase'=>'test-auth-pass'])),'authNoPriv disabled');
$reject(fn()=>$registry->execute($ctx,'sync','snmp',str_replace('aes128','aes256',$secret)),'unsupported AES256 rejected');
$reject(fn()=>$registry->execute(new ConnectorContext('snmp','192.0.2.1',1161,'snmp_v3'),'health','snmp',$secret),'network denied');
foreach(['cycle','badStatus','oversize'] as $flag){$session=new FixtureSnmp();$session->$flag=true;$reject(fn()=>$registry->execute($ctx,'sync','snmp',$secret),$flag);$ok($session->closed,'failed session closed');}
$event=['workspace_id'=>'11111111-1111-4111-8111-111111111111','external_id'=>'manual','event_key'=>'e1','type'=>'router','fields'=>$item['fields']];
$reject(fn()=>ObservationInput::normalize($event),'public observations cannot forge network metadata');
$ok(ObservationInput::normalize($event,true)['fields']['attributes']===$item['fields']['attributes'],'internal network attributes');
$ok(ConnectorHealthMonitor::outcome(null,9)===['status'=>'healthy','failures'=>0,'cooldown'=>0],'recover after success');
$ok(ConnectorHealthMonitor::outcome('connector_transport_failed',0)['status']==='degraded','initial failure');
$ok(ConnectorHealthMonitor::outcome('connector_transport_failed',2)===['status'=>'unreachable','failures'=>3,'cooldown'=>300],'third failure opens breaker');
$ok(ConnectorHealthMonitor::outcome('connector_auth_failed',0)['status']==='auth_failed','auth status');
$ok(ConnectorHealthMonitor::outcome('connector_auth_failed',0)['cooldown']===300,'auth failure immediate cooldown');
$ok(ConnectorHealthMonitor::outcome('connector_rate_limited',0)['cooldown']===60,'rate limit cooldown');
$reject(fn()=>ConnectorHealthMonitor::outcome('service_grant_denied',0),'local authorization is not remote health');
$ok(count((new SqlStatementParser())->split(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0026_connector_health.sql')))===5,'migration parses');
echo "SNMP + health smoke: $n PASS\n";
