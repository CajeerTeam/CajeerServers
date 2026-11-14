<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Connector\{ConnectorActionService,ProxmoxPowerPolicy,ConnectorContext,ProxmoxConnector,HttpsTransport,EndpointPolicy};
use Cajeer\Servers\Integration\{ConnectorRegistry,ConnectorExtensionLoader};
use Cajeer\Servers\Http\Exception\HttpException;
$n=0;$ok=static function(bool $v,string $s)use(&$n):void {if(!$v)throw new RuntimeException($s);$n++;};
$reject=static function(callable $f)use($ok):void {try{$f();}catch(Throwable){$ok(true,'rejected');return;}throw new RuntimeException('Expected rejection');};
$valid=['action'=>'vm.start','external_id'=>'qemu:101','confirm'=>'vm.start qemu:101'];
ConnectorActionService::validate($valid,'1234567890123456');$ok(true,'valid intent');
foreach ([['action'=>'vm.reset'],['external_id'=>'lxc:101'],['confirm'=>'yes'],['external_id'=>'qemu:101/../../'],['node'=>'pve'],['command'=>'shell']] as $patch) $reject(fn()=>ConnectorActionService::validate(array_replace($valid,$patch),'1234567890123456'));
$reject(fn()=>ConnectorActionService::validate($valid,'short'));
foreach(\Cajeer\Servers\Connector\VmmanagerPowerPolicy::ACTIONS as $action){ConnectorActionService::validate(['action'=>$action,'external_id'=>'host:21','confirm'=>$action.' host:21'],'1234567890123456');$ok(true,'VMmanager action '.$action);}
foreach([['action'=>'vmmanager.host.force'],['external_id'=>'qemu:21'],['external_id'=>'host:2147483648'],['force'=>true],['confirm'=>'yes']] as $patch){$intent=array_replace(['action'=>'vmmanager.host.start','external_id'=>'host:21','confirm'=>'vmmanager.host.start host:21'],$patch);$reject(fn()=>ConnectorActionService::validate($intent,'1234567890123456'));}
$profile=['cluster'=>null,'anchor_node'=>'pve','anchor_id'=>null,'primary_pin'=>str_repeat('a',64),'fallbacks'=>[]];
$ctx=new ConnectorContext('proxmox','pve.test',8006,'proxmox_api',$profile);
$secret='{"token_id":"svc@pve!test","token_secret":"fixture-secret"}';
$mode='ok';
$d=new ProxmoxConnector(static function($url)use(&$mode){$path=parse_url($url,PHP_URL_PATH);$data=match($path){
    '/api2/json/version'=>['version'=>'9.0.3'], '/api2/json/cluster/status'=>[['type'=>'node','name'=>'pve','local'=>1]],
    '/api2/json/nodes'=>[['node'=>'pve','status'=>$mode==='offline'?'offline':'online']],
    '/api2/json/cluster/resources'=>[['type'=>'qemu','node'=>'pve','vmid'=>101,'status'=>'stopped'],['type'=>'lxc','node'=>'pve','vmid'=>102,'status'=>'stopped']],
    '/api2/json/nodes/pve/qemu/101/config','/api2/json/nodes/pve/lxc/102/config'=>['digest'=>str_repeat('a',40)]+match($mode){'locked'=>['lock'=>'backup'],'template'=>['template'=>1],default=>[]},
    '/api2/json/nodes/pve/qemu/101/status/current','/api2/json/nodes/pve/lxc/102/status/current'=>['status'=>$mode==='running'?'running':'stopped','qmpstatus'=>$mode==='running'?'running':'stopped','ha'=>['managed'=>$mode==='ha'?1:0]],default=>throw new RuntimeException('unexpected path')};return json_encode(['data'=>$data]);});
$ok($d->prepareAction($ctx,'vm.start','qemu:101',$secret)['vmid']===101,'resolve live VM');
$ok($d->prepareAction($ctx,'lxc.start','lxc:102',$secret)['type']==='lxc','resolve live container');
$reject(fn()=>$d->prepareAction($ctx,'vm.start','qemu:102',$secret));
$reject(fn()=>$d->prepareAction($ctx,'vm.shutdown','lxc:101',$secret));
$reject(fn()=>$d->prepareAction(new ConnectorContext('proxmox','pve.test',8006,'proxmox_api'),'vm.start','qemu:101',$secret));
foreach(['locked','template','offline','ha','running'] as $mode)$reject(fn()=>$d->prepareAction($ctx,'vm.start','qemu:101',$secret));
$mode='running';$ok($d->prepareAction($ctx,'vm.reboot','qemu:101',$secret)['state']==='running','reboot running');
$mode='ok';$reject(fn()=>$d->prepareAction($ctx,'lxc.stop','lxc:102',$secret));
foreach(ProxmoxPowerPolicy::ACTIONS as $a){$external=str_starts_with($a,'vm.')?'qemu:101':'lxc:102';ConnectorActionService::validate(['action'=>$a,'external_id'=>$external,'confirm'=>$a.' '.$external],'1234567890123456');$ok(true,'allow '.$a);}
$registry=new ConnectorRegistry($d);$ok($registry->actions('proxmox')===\Cajeer\Servers\Connector\ProxmoxSnapshotPolicy::ALL_ACTIONS,'actions published');
$ok($registry->actions('ssh')===[],'no unsupported actions');
$transport=new HttpsTransport(new EndpointPolicy(['127.0.0.1/32']));
$reject(fn()=>$transport->powerPost('127.0.0.1',1,'/api2/json/nodes/pve/qemu/101/status/reset','test'));
$reject(fn()=>$transport->powerPost('127.0.0.1',1,'/api2/json/nodes/pve/lxc/101/status/start?skiplock=1','test'));
$registry=ConnectorExtensionLoader::load($registry,dirname(__DIR__).'/examples/extensions/demo-connector/entry.php',$transport);
$ok($registry->has('custom')&&$registry->actions('custom')===[],'real custom SDK installed');
$reject(fn()=>ConnectorExtensionLoader::load($registry,'/missing.php',$transport));
$reject(fn()=>ConnectorExtensionLoader::load($registry,'relative.php',$transport));
$reject(fn()=>ConnectorExtensionLoader::load($registry,dirname(__DIR__).'/examples/extensions/demo-connector/entry.php',$transport));
$ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0028_connector_actions.sql')))===5,'migration statements');
putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');$app=(new \Cajeer\Servers\Kernel\Application(dirname(__DIR__)))->boot();
try {foreach(['GET','POST'] as $method){$r=$app->handle(new \Cajeer\Servers\Runtime\HttpRequest($method,'/api/v1/connectors/targets/11111111-1111-4111-8111-111111111111/actions'));$ok(in_array($r->status,[401,503,422,415],true),'guest denied');}}finally{$app->terminate();}
echo "Connector actions and SDK smoke: $n PASS\n";
