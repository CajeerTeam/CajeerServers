<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{LinuxDiscovery,EndpointPolicy,HostTransport,LinuxConnector,ConnectorTransportException};
use Cajeer\Servers\Collector\{CollectorRegistry,CollectionRuntimeService,CollectionPlan};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;
$n=0;$ok=static function(bool $v,string $message)use(&$n){if(!$v)throw new RuntimeException($message);$n++;};
// The integration harness passes actual output of the fixed remote probe to this exact validator.
if(in_array('--normalize',$argv,true)){
    $data=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    echo json_encode(LinuxDiscovery::normalize($data,$data['scope']),JSON_THROW_ON_ERROR);exit;
}
$identity=str_repeat('a',32);$row=['name'=>'libc6','version'=>'2.41-1','architecture'=>'amd64'];
$data=['identity'=>$identity,'scope'=>'software','sections'=>['packages'=>[$row]]];
$normalized=LinuxDiscovery::normalize($data,'software');
$ok($normalized['counts']['packages']===1&&!isset($normalized['identity']),'normalize excludes raw identity');
$reject=function(array $value,string $scope)use($ok){try{LinuxDiscovery::normalize($value,$scope);}catch(ConnectorTransportException $e){$ok($e->category==='connector_protocol_error','invalid response refused');return;}throw new RuntimeException('accepted invalid response');};
$reject(array_replace($data,['identity'=>'bad']),'software');
$reject($data,'system');
$reject(array_replace($data,['sections'=>['packages'=>[$row,$row]]]),'software');
$reject(array_replace($data,['sections'=>['packages'=>[$row+['secret'=>'x']]]]),'software');
$reject(array_replace($data,['sections'=>['packages'=>[array_replace($row,['version'=>"a\nb"])]]]),'software');
$reject(array_replace($data,['sections'=>['packages'=>[array_replace($row,['version'=>null])]]]),'software');
$reject(array_replace($data,['sections'=>['packages'=>array_fill(0,10001,$row)]]),'software');
$disk=['name'=>'sda','kernel_name'=>'sda','parent'=>null,'type'=>'disk','size_bytes'=>'18446744073709551615','filesystem'=>null,'read_only'=>'false'];
$d=['identity'=>$identity,'scope'=>'storage','sections'=>['devices'=>[$disk],'filesystems'=>[]]];
$ok(LinuxDiscovery::normalize($d,'storage')['sections']['devices'][0]['size_bytes']===$disk['size_bytes'],'large storage precision');
$reject(array_replace($d,['sections'=>['devices'=>[array_replace($disk,['size_bytes'=>'-1'])],'filesystems'=>[]]]),'storage');
$r=new CollectorRegistry(new ConnectorRegistry(new LinuxConnector(new HostTransport(new EndpointPolicy()))));
$registry=CollectorRegistry::forConnectors(new ConnectorRegistry(new LinuxConnector(new HostTransport(new EndpointPolicy()))));
$runtime=new CollectionRuntimeService($registry);$catalog=array_column($registry->catalog(),null,'name');
foreach(LinuxDiscovery::SCOPES as $scope){
    $name='ssh.'.$scope;$ok($catalog[$name]['operations']===['inspect']&&$catalog[$name]['resource_types']===[],'scope registered inspect only');
    try{$registry->require($name,'ssh','sync');throw new RuntimeException('sync allowed');}catch(HttpException $e){$ok($e->errorCode==='collection_invalid','sync rejected');}
    try{$runtime->validate(new CollectionPlan($name,'inspect'),['provider'=>'ssh']);throw new RuntimeException('no profile accepted');}catch(HttpException $e){$ok($e->errorCode==='collection_invalid','profile mandatory');}
}
$ok($catalog['ssh.inventory']['operations']===['inspect','sync'],'legacy sync retained');
echo "Linux discovery: $n PASS\n";
