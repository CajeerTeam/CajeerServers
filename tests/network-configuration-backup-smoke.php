<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\NetworkConfigurationBackup as Backup;
use Cajeer\Servers\Connector\ConnectorTransportException;
use Cajeer\Servers\Credentials\{VaultCrypto,VaultMasterKey};
$ok=static function(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";};
$a="# 2026-11-14 12:00:00 by RouterOS 7.16.2\n/ip firewall filter\nadd chain=input action=accept\nadd chain=forward action=drop\n";
$b=str_replace('12:00:00','12:01:00',$a);$c=str_replace('action=accept','action=drop',$b);
$ok(Backup::validate($a)===$a,'raw export preserved');
$ok(Backup::canonical($a)===Backup::canonical($b),'timestamp ignored');
$d=Backup::diff($a,$c);$ok($d['added']===1&&$d['removed']===1&&$d['exact'],'line diff');
$ok(Backup::diff($a,$b)['lines']===[],'unchanged config');
$largeA=$a.str_repeat("one\n",600);$largeB=$a.str_repeat("two\n",600);$d=Backup::diff($largeA,$largeB);$ok(!$d['exact']&&$d['added']===600&&$d['removed']===600,'bounded large diff');
foreach(['',$a."#error exporting /ip/firewall\n",$a."\0",str_repeat('x',1048577)] as $invalid){try{Backup::validate($invalid);throw new RuntimeException('accepted invalid export');}catch(ConnectorTransportException){}}
$ok(true,'reject empty partial binary oversized');
$id='11111111-1111-4111-8111-111111111111';$ws='22222222-2222-4222-8222-222222222222';$key=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));$next=new VaultMasterKey('base64:'.base64_encode(random_bytes(32)));$crypto=new VaultCrypto($key);$cipher=$crypto->seal($a,$id,$ws,'network_configuration_backup');
$ok(!str_contains($cipher,'/ip firewall filter')&&$crypto->open($cipher,$id,$ws,'network_configuration_backup')===$a,'authenticated encryption');
$ok((new VaultCrypto($next))->open($crypto->rewrap($cipher,$id,$ws,'network_configuration_backup',$next),$id,$ws,'network_configuration_backup')===$a,'key rotation');
$root=dirname(__DIR__);$sql=file_get_contents($root.'/migrations/pgsql/0060_network_configuration_backup.sql');
$ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split($sql))===4,'migration statements');
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');
$app=(new \Cajeer\Servers\Kernel\Application($root))->boot();
try{
    $catalog=array_column($app->container()->get(\Cajeer\Servers\Collector\CollectionRuntimeService::class)->catalog(),null,'name');
    $ok(($catalog['routeros.configuration-backup']['operations']??null)===['inspect'],'executable collector registered');
    $target='11111111-1111-4111-8111-111111111111';$id='22222222-2222-4222-8222-222222222222';
    foreach(['','/'.$id.'/diff','/'.$id.'/export'] as $suffix){
        $path='/api/v1/connectors/targets/'.$target.'/configuration-backups'.$suffix;
        $headers=str_ends_with($suffix,'/export')?['Accept'=>'application/octet-stream']:[];
        $response=$app->handle(new \Cajeer\Servers\Runtime\HttpRequest('GET',$path,$headers,'',str_ends_with($suffix,'/diff')?['from'=>$target]:[]));
        $ok(in_array($response->status,[401,503],true),'protected backup API '.$suffix);
    }
}finally{$app->terminate();}
