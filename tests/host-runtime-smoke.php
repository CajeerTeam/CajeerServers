#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorResult,EndpointPolicy,HostObservation,HostTransport,LinuxConnector,WindowsConnector,ConnectorTransportException};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Inventory\ObservationInput;
$n=0;
$ok=static function(bool $v,string $label)use(&$n):void {if(!$v)throw new RuntimeException('FAIL: '.$label);$n++;};
$reject=static function(callable $f)use($ok):void {try{$f();}catch(Throwable){$ok(true,'reject');return;}throw new RuntimeException('expected rejection');};
$transport=new HostTransport(new EndpointPolicy(['127.0.0.1/32']),'/usr/bin/python3');
$linux=new LinuxConnector($transport);$win=new WindowsConnector($transport);
$registry=new ConnectorRegistry($linux,$win);
$data=['identity'=>'aabbccddeeff00112233445566778899','name'=>'linux-01','os_family'=>'debian','os_name'=>'Debian GNU/Linux','os_version'=>'12','cpu_count'=>4,'memory_bytes'=>8589934592,
    'disks'=>[['name'=>'sda','size_bytes'=>1000000000]],'interfaces'=>[['name'=>'eth0','addresses'=>['2001:db8::1','192.0.2.1']]]];
$r=HostObservation::result($data,'ssh','sync')->validated($linux->manifest());
$ok($r->complete&&count($r->items)===1,'complete host snapshot');
$item=$r->items[0];$ok($item['type']==='server'&&$item['fields']['attributes']['host_hardware']['cpu_count']===4,'hardware normalized');
$ok($item['fields']['attributes']['host_hardware']['memory_bytes']===8589934592&&count($item['fields']['ip_addresses'])===2,'RAM and addresses');
$data['name']='renamed';$data['interfaces'][0]['addresses']=array_reverse($data['interfaces'][0]['addresses']);
$other=HostObservation::result($data,'ssh','sync')->validated($linux->manifest())->items[0];
$ok($other['external_id']===$item['external_id']&&$other['fields']['ip_addresses']===$item['fields']['ip_addresses'],'stable identity and IP ordering');
$ok(!str_contains($item['external_id'],$data['identity']),'machine ID not stored raw');
$ok(!HostObservation::result($data,'ssh','health')->complete,'health not authoritative');
$w=array_replace($data,['identity'=>'AABBCCDD-EEFF-0011-2233-445566778899','os_family'=>'windows']);
$ok(HostObservation::result($w,'winrm','sync')->validated($win->manifest())->items[0]['fields']['os_family']==='windows','Windows result');
foreach(['identity'=>'00000000000000000000000000000000','cpu_count'=>0,'memory_bytes'=>'123','os_family'=>'windows','name'=>"bad\nname",'disks'=>[['name'=>'sda','size_bytes'=>-1]],'interfaces'=>[['name'=>'x','addresses'=>['not-ip']]]] as $field=>$bad) $reject(fn()=>HostObservation::result(array_replace($data,[$field=>$bad]),'ssh','sync'));
$reject(fn()=>HostObservation::result($data+['password'=>'secret'],'ssh','sync'));
$event=['workspace_id'=>'11111111-1111-4111-8111-111111111111','external_id'=>'manual','event_key'=>'e1','type'=>'server','fields'=>$item['fields']];
$reject(fn()=>ObservationInput::normalize($event));
$ok(ObservationInput::normalize($event,true)['fields']['attributes']===$item['fields']['attributes'],'internal hardware accepted');
$ok($linux->manifest()->accepts('ssh_private_key','ssh')&&$win->manifest()->supports('validate_rotation'),'real capabilities');
$reject(fn()=>$registry->execute(new ConnectorContext('ssh','127.0.0.1',22,'ssh_password'),'sync','ssh','{"username":"test","password":"secret"}'));
$reject(fn()=>$registry->execute(new ConnectorContext('winrm','127.0.0.1',5986,'winrm'),'sync','winrm','{"username":"test","password":"secret","auth":"ntlm"}'));
$reject(fn()=>$registry->execute(new ConnectorContext('winrm','127.0.0.1',5986,'winrm'),'vm.start','winrm','ignored'));
echo "Host runtime smoke: $n PASS\n";
