<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorTransportException,EndpointPolicy,HostTransport,OsNetworkProfile,SnmpTransport,RouterConnector};
use Cajeer\Servers\Http\Exception\HttpException;
$n=0;$ok=static function(bool $v,string $name)use(&$n):void{if(!$v)throw new RuntimeException('FAIL: '.$name);$n++;};
$reject=static function(callable $f,string $category)use($ok):void{try{$f();}catch(ConnectorTransportException $e){$ok($e->category===$category,$category);return;}catch(HttpException $e){$ok($e->errorCode===$category,$category);return;}throw new RuntimeException('Expected '.$category);};
$id='aabbccddeeff00112233445566778899';$pin=str_repeat('a',64);
$ssh=['primary_pin'=>$pin,'identity_hash'=>hash('sha256','ssh:'.$id),'key_type'=>'ssh-ed25519'];
$win=['primary_pin'=>$pin,'identity_hash'=>hash('sha256','winrm:'.$id)];
$snmp=['engine_id'=>'80001f8804aabbcc','object_id'=>'.1.3.6.1.4.1.8072.3.2.10'];
$ok(OsNetworkProfile::normalize('ssh',array_replace($ssh,['primary_pin'=>strtoupper($pin)]))===$ssh,'canonical pin');
OsNetworkProfile::verifyHost('winrm',$win,'AABBCCDD-EEFF-0011-2233-445566778899');$ok(true,'GUID normalization');
OsNetworkProfile::verifyHost('ssh',$ssh,$id);$ok(true,'machine identity');
$reject(fn()=>OsNetworkProfile::verifyHost('ssh',$ssh,'11111111111111111111111111111111'),'connector_trust_failed');
$reject(fn()=>OsNetworkProfile::verifyHost('ssh',$ssh,[]),'connector_trust_failed');
$reject(fn()=>OsNetworkProfile::verifyHost('ssh',$ssh,str_repeat('0',32)),'connector_trust_failed');
foreach([['ssh',null],['ssh',$ssh+['password'=>'x']],['ssh',array_replace($ssh,['key_type'=>'ssh-dss'])],['ssh',$win],['winrm',$ssh],['proxmox',$win],['snmp',array_replace($snmp,['engine_id'=>'123'])],['snmp',array_replace($snmp,['object_id'=>'not-oid'])]] as [$provider,$profile])$reject(fn()=>OsNetworkProfile::normalize($provider,$profile),'validation_failed');
$policy=new EndpointPolicy(['127.0.0.1/32']);
$transport=new HostTransport($policy,'/usr/bin/python3',null,null,true);
$reject(fn()=>$transport->collect(new ConnectorContext('ssh','127.0.0.1',22,'ssh_password'),[]),'connector_trust_failed');
$reject(fn()=>$transport->collect(new ConnectorContext('winrm','127.0.0.1',5986,'winrm'),[]),'connector_trust_failed');
final class ProfileSnmpFixture {
    public bool $closed=false;public int $engineReads=0;public bool $change=false;public bool $wrongObject=false;
    public function get(string $oid):string {return match($oid){'.1.3.6.1.6.3.10.2.1.1.0'=>hex2bin(++$this->engineReads>1&&$this->change?'80001f8804ffffff':'80001f8804aabbcc'),'.1.3.6.1.2.1.1.2.0'=>$this->wrongObject?'.1.3.6.1.4.1.1':'.1.3.6.1.4.1.8072.3.2.10','.1.3.6.1.2.1.1.5.0'=>'fixture','.1.3.6.1.2.1.1.3.0'=>'1234',default=>throw new RuntimeException('unexpected OID')};}
    public function next(string $oid):array{return [null,null];}
    public function close():void{$this->closed=true;}
}
$session=new ProfileSnmpFixture();$connections=0;
$transport=new SnmpTransport($policy,true,static function()use(&$session,&$connections){$connections++;return $session;},true);
$ctx=new ConnectorContext('snmp','127.0.0.1',161,'snmp_v3',null,null,$snmp);
$cred=['privacy_protocol'=>'aes128'];
$ok($transport->collect($ctx,$cred,true)['name']==='fixture'&&$session->closed&&$session->engineReads===2,'SNMP identity checked twice');
$session=new ProfileSnmpFixture();$ok(count($transport->metrics($ctx,$cred))===1&&$session->closed&&$session->engineReads===2,'SNMP metrics identity');
$session=new ProfileSnmpFixture();$session->change=true;$reject(fn()=>$transport->collect($ctx,$cred,true),'connector_trust_failed');$ok($session->closed,'changed session closed');
$session=new ProfileSnmpFixture();$session->wrongObject=true;$reject(fn()=>$transport->metrics($ctx,$cred),'connector_trust_failed');$ok($session->closed,'wrong device session closed');
$before=$connections;$reject(fn()=>$transport->collect(new ConnectorContext('snmp','127.0.0.1',161,'snmp_v2c',null,null,$snmp),[],true),'connector_trust_failed');
$reject(fn()=>$transport->collect(new ConnectorContext('snmp','127.0.0.1',161,'snmp_v3'),$cred,true),'connector_trust_failed');$ok($connections===$before,'no session for denied legacy');
$ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0053_os_network_profiles.sql')))===2,'migration syntax split');
echo "OS / Network profile smoke: $n PASS\n";
