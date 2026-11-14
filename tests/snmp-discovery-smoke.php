#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorTransportException,EndpointPolicy,RouterConnector,SnmpTransport};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Collector\{CollectorRegistry,CollectionRuntimeService,CollectionPlan};
use Cajeer\Servers\Http\Exception\HttpException;
$n=0;
function ok(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);$n++;}
function reject(callable $f,string $category):void{try{$f();}catch(HttpException|ConnectorTransportException $e){ok(($e instanceof HttpException?$e->errorCode:$e->category)===$category,$category);return;}throw new RuntimeException('Expected failure '.$category);}
final class AdvancedFixture {
    public bool $closed=false;public bool $cycle=false;public bool $restart=false;public bool $identityChange=false;public bool $fallback=false;public bool $badCount=false;public bool $timeout=false;public bool $oversize=false;
    public int $batchCalls=0;public int $singleCalls=0;private int $uptimeReads=0;private int $identityReads=0;private array $data=[];private array $keys=[];
    public function __construct(int $count=70){
        $this->data=['.1.3.6.1.2.1.1.1.0'=>'test switch','.1.3.6.1.2.1.1.2.0'=>'.1.3.6.1.4.1.8072.3.2.10','.1.3.6.1.2.1.1.3.0'=>'1234','.1.3.6.1.2.1.1.5.0'=>'switch01','.1.3.6.1.2.1.2.1.0'=>(string)$count];
        for($i=1;$i<=$count;$i++){
            $idx=$i*2;foreach([2=>'port'.$i,3=>'6',4=>'1500',5=>'4294967295',6=>"\x00\x11\x22\x33\x44".chr($i%256),7=>'1',8=>'1'] as $c=>$v)$this->data['.1.3.6.1.2.1.2.2.1.'.$c.'.'.$idx]=$v;
            foreach([1=>'eth'.$i,15=>'10000',18=>'alias '.$i,19=>'0'] as $c=>$v)$this->data['.1.3.6.1.2.1.31.1.1.1.'.$c.'.'.$idx]=$v;
        }
        foreach([2=>'chassis',4=>'0',5=>'3',7=>'switch',8=>'rev1',9=>'fw1',10=>'os1',11=>'SN123',12=>'Vendor',13=>'Model'] as $c=>$v)$this->data['.1.3.6.1.2.1.47.1.1.1.1.'.$c.'.1']=$v;
        $this->data+=['.1.3.6.1.2.1.47.1.1.1.1.4.2'=>'1','.1.3.6.1.2.1.47.1.1.1.1.5.2'=>'10','.1.3.6.1.2.1.47.1.1.1.1.7.2'=>'port-module',
        '.1.3.6.1.2.1.4.20.1.2.192.0.2.1'=>'2','.1.3.6.1.2.1.4.34.1.3.2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1'=>'4',
        '.1.3.6.1.2.1.17.1.4.1.2.1'=>'2','.1.3.6.1.2.1.17.1.4.1.2.9'=>'4','.1.3.6.1.2.1.17.7.1.4.5.1.1.1'=>'10','.1.3.6.1.2.1.17.7.1.4.3.1.1.10'=>'prod',
        '.1.3.6.1.2.1.17.7.1.4.2.1.4.0.10'=>"\x80\x80",'.1.3.6.1.2.1.17.7.1.4.2.1.5.0.10'=>"\x80\x00",'.1.3.6.1.2.1.17.7.1.4.2.1.4.2.20'=>"\x40", // unresolved port and partial membership
        '.1.0.8802.1.1.2.1.3.1.0'=>'4','.1.0.8802.1.1.2.1.3.2.0'=>"\x00\x11\x22\x33\x44\x55",'.1.0.8802.1.1.2.1.3.3.0'=>'switch01',
        '.1.0.8802.1.1.2.1.3.7.1.2.99'=>'5','.1.0.8802.1.1.2.1.3.7.1.3.99'=>'eth1','.1.0.8802.1.1.2.1.3.7.1.2.4'=>'7','.1.0.8802.1.1.2.1.3.7.1.3.4'=>'unknown',
        '.1.0.8802.1.1.2.1.4.1.1.4.0.99.1'=>'4','.1.0.8802.1.1.2.1.4.1.1.5.0.99.1'=>"\x00\x01\x02\x03\x04\x05",'.1.0.8802.1.1.2.1.4.1.1.6.0.99.1'=>'5','.1.0.8802.1.1.2.1.4.1.1.7.0.99.1'=>'remote1','.1.0.8802.1.1.2.1.4.1.1.9.0.99.1'=>'switch02'];
        uksort($this->data,self::compare(...));$this->keys=array_keys($this->data);
    }
    private static function compare(string $a,string $b):int{$a=explode('.',$a);$b=explode('.',$b);for($i=0;$i<min(count($a),count($b));$i++)if((int)$a[$i]!== (int)$b[$i])return (int)$a[$i]<=>(int)$b[$i];return count($a)<=>count($b);}
    public function get(string $oid):string{
        if($oid==='.1.3.6.1.6.3.10.2.1.1.0')return $this->identityChange&&++$this->identityReads>1?hex2bin('800000000002'):hex2bin('800000000001');
        if($oid==='.1.3.6.1.2.1.1.3.0'&&$this->restart&&++$this->uptimeReads>1)return '1';
        if($oid==='.1.3.6.1.2.1.2.1.0'&&$this->badCount)return '71';
        return $this->data[$oid]??throw new RuntimeException('unexpected GET');
    }
    private function lookup(string $oid):array{if($this->timeout)throw new ConnectorTransportException('connector_transport_failed');if($this->cycle)return [$oid,'1'];$lo=0;$hi=count($this->keys);while($lo<$hi){$mid=intdiv($lo+$hi,2);if(self::compare($this->keys[$mid],$oid)<=0)$lo=$mid+1;else $hi=$mid;}if(!isset($this->keys[$lo]))return [null,null];$k=$this->keys[$lo];return [$k,$this->oversize?str_repeat('x',513):$this->data[$k]];}
    public function next(string $oid):array{$this->singleCalls++;return $this->lookup($oid);}
    public function nextBatch(array $oids):?array{$this->batchCalls++;return $this->fallback?null:array_map($this->lookup(...),$oids);}
    public function close():void{$this->closed=true;}
}
$profile=['engine_id'=>'800000000001','object_id'=>'.1.3.6.1.4.1.8072.3.2.10'];
$secret=json_encode(['username'=>'reader','auth_protocol'=>'sha256','auth_passphrase'=>'auth-passphrase','privacy_protocol'=>'aes128','privacy_passphrase'=>'priv-passphrase']);
$ctx=new ConnectorContext('snmp','127.0.0.1',1161,'snmp_v3',null,null,$profile);
$fixture=new AdvancedFixture();$transport=new SnmpTransport(new EndpointPolicy(['127.0.0.1/32']),true,static function($ip,$port,$type,$credential)use(&$fixture){ok($ip==='127.0.0.1'&&$port===1161&&$type==='snmp_v3','pinned authPriv');return $fixture;});
$registry=new ConnectorRegistry(new RouterConnector($transport));$collectors=CollectorRegistry::forConnectors($registry);$runtime=new CollectionRuntimeService($collectors);
$target=['provider'=>'snmp','endpoint_host'=>'127.0.0.1','endpoint_port'=>1161,'credential_type'=>'snmp_v3','purpose'=>'snmp','os_network_profile'=>$profile];
$collect=static function($scope)use($runtime,$target,$secret){$result=$runtime->collect(new CollectionPlan('snmp.'.$scope,'inspect'),$target,'snmp_v3',$secret);ok($result->items===[]&&!$result->complete&&$result->connection['profile_verified'],'read-only observation');return $result->connection['snmp_discovery'];};
$device=$collect('device');ok($device['sections']['hardware'][0]['serial']==='SN123'&&$device['sections']['hardware'][1]['parent_index']==='1','ENTITY tree');ok($fixture->closed,'closed');ok($fixture->batchCalls>0,'multi-varbind');
$fixture=new AdvancedFixture();$if=$collect('interfaces');ok(count($if['sections']['interfaces'])===70,'more than 64 interfaces');ok($if['sections']['interfaces'][0]['speed_bps']==='10000000000','exact speed');ok($if['sections']['interfaces'][0]['mac']==='00:11:22:33:44:01','binary MAC');ok(count($if['sections']['addresses'])===2&&$if['sections']['addresses'][1]['address']==='2001:db8::1','IPv6');ok($fixture->batchCalls>0&&$fixture->singleCalls<20,'batch reduces requests');
$fixture=new AdvancedFixture();$top=$collect('topology');$s=$top['sections'];ok($s['bridge_ports'][0]['if_index']==='2'&&$s['bridge_ports'][0]['pvid']==='10','bridge port mapping');ok($s['vlan_membership'][0]['untagged']==='yes'&&$s['vlan_membership'][1]['bridge_port']==='9'&&$s['vlan_membership'][1]['if_index']==='4'&&$s['vlan_membership'][1]['untagged']==='no'&&$s['vlan_membership'][1]['tagged']==='yes','Q-BRIDGE bitmap');ok($s['vlan_membership'][2]['if_index']===null&&$s['vlan_membership'][2]['untagged']===null,'unresolved port');ok($s['lldp_local'][0]['chassis_id']==='00:11:22:33:44:55','local chassis');ok($s['neighbors'][0]['if_index']==='2'&&$s['neighbors'][0]['chassis_id']==='00:01:02:03:04:05','LLDP exact local mapping');ok($s['lldp_ports'][0]['local_port']==='4'&&$s['lldp_ports'][0]['if_index']===null,'not by ifIndex');ok(!$top['complete']&&in_array('not_visible_or_empty',array_column($s['visibility'],'state'),true)===true,'conservative scope');
$fixture=new AdvancedFixture();$fixture->fallback=true;$fallback=$collect('topology');ok($fallback===$top&&$fixture->singleCalls>100,'single fallback identical');
foreach(['oversize'=>'connector_protocol_error','cycle'=>'connector_protocol_error','restart'=>'connector_protocol_error','badCount'=>'connector_protocol_error','identityChange'=>'connector_trust_failed','timeout'=>'connector_transport_failed'] as $flag=>$code){$fixture=new AdvancedFixture();$fixture->$flag=true;reject(fn()=>$collect('interfaces'),$code);ok($fixture->closed,'closed on failure');}
foreach(SnmpDiscoveryScopes() as $scope){reject(fn()=>$collectors->require('snmp.'.$scope,'snmp','sync'),'collection_invalid');}
function SnmpDiscoveryScopes():array{return \Cajeer\Servers\Connector\SnmpDiscovery::SCOPES;}
reject(fn()=>$registry->snmpDiscovery(new ConnectorContext('snmp','127.0.0.1',1161,'snmp_v3'),'inspect','snmp','device',$secret),'connector_trust_failed');
reject(fn()=>$registry->snmpDiscovery(new ConnectorContext('snmp','127.0.0.1',1161,'snmp_v2c'),'inspect','snmp','device','{"community":"legacy"}'),'connector_trust_failed');
$fixture=new AdvancedFixture(512);$big=$collect('interfaces');ok(count($big['sections']['interfaces'])===512,'512 interfaces');
$fixture=new AdvancedFixture(2049);reject(fn()=>$collect('interfaces'),'connector_protocol_error');
ok(!str_contains(json_encode($top),'auth-passphrase'),'no credentials');
$sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0056_snmp_discovery.sql');ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split($sql))===1,'migration parsed');
putenv("APP_ENV=local");$app=(new \Cajeer\Servers\Kernel\Application(dirname(__DIR__)))->boot();try{
    $routes=$app->container()->get(\Cajeer\Servers\Http\Router::class)->routes();
    ok(count(array_filter($routes,fn($r)=>$r['path']==='/api/v1/connectors/targets/{id}/snmp-discovery'&&in_array('GET',$r['methods'],true)))===1,'protected read endpoint');
    $r=$app->handle(new \Cajeer\Servers\Runtime\HttpRequest('GET','/api/v1/connectors/targets/11111111-1111-4111-8111-111111111111/snmp-discovery'));
    ok(in_array($r->status,[401,503],true),'unauthenticated read denied');
}finally{$app->terminate();}
echo "Advanced SNMP discovery: $n PASS\n";
