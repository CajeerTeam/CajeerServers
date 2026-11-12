#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Collector\{CollectorInterface,CollectorRegistry,CollectionContext,CollectionPlan,CollectionResult,CollectionRuntimeService};
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorInterface,ConnectorManifest,ConnectorResult,ConnectorTransportException};
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Kernel\Application;
$n=0;
$ok=static function(bool $v,string $label)use(&$n):void {if(!$v)throw new RuntimeException('FAIL '.$label);$n++;};
$reject=static function(callable $f,string $code)use($ok):void {try{$f();}catch(HttpException $e){$ok($e->errorCode===$code,'expected '.$code);return;}throw new RuntimeException('Expected '.$code);};
$driver=new class implements ConnectorInterface {
    public int $calls=0;public bool $fail=false;public bool $bad=false;
    public function manifest():ConnectorManifest {return new ConnectorManifest('proxmox','api',['proxmox_api'],['inspect','sync'],['virtual_machine'],'https');}
    public function execute(ConnectorContext $context,string $operation,#[\SensitiveParameter] string $secret):ConnectorResult {
        $this->calls++;if($this->fail)throw new ConnectorTransportException('connector_auth_failed');
        if($secret!=='test-secret')throw new RuntimeException('private diagnostic');
        $row=['type'=>'virtual_machine','external_id'=>'qemu:42','fields'=>['name'=>'test-vm','status'=>'online']];
        return new ConnectorResult($this->bad?[$row,$row]:[$row],true);
    }
};
$connectors=new ConnectorRegistry($driver);$registry=CollectorRegistry::forConnectors($connectors);$runtime=new CollectionRuntimeService($registry);
$target=['provider'=>'proxmox','endpoint_host'=>'127.0.0.1','endpoint_port'=>8006,'credential_type'=>'proxmox_api','purpose'=>'api'];
$ok($runtime->catalog()[0]['name']==='proxmox.inventory','registry catalogs only registered driver');
foreach(['inspect','sync'] as $op) {
    $plan=new CollectionPlan('proxmox.inventory',$op);$snapshot=$runtime->collect($plan,$target,'proxmox_api','test-secret');
    $result=(new CollectionResult($plan,$snapshot,'2026-11-12T00:00:00Z'))->toArray();
    $ok($result['count']===1&&$result['complete']===true&&$result['observations'][0]['external_id']==='qemu:42','actual collection result');
    $ok($result['sink']===($op==='sync'?'inventory':'result')&&!str_contains(json_encode($result),'test-secret'),'sink and no secret');
}
$ok($driver->calls===2,'one real driver call per collection');
$reject(fn()=>new CollectionPlan('proxmox.inventory','health'),'collection_invalid');
$reject(fn()=>new CollectionPlan('../bad','inspect'),'collection_invalid');
$plan=new CollectionPlan('proxmox.inventory','inspect');
$reject(fn()=>$runtime->collect(new CollectionPlan('ssh.inventory','inspect'),$target,'proxmox_api','test-secret'),'collection_invalid');
$reject(fn()=>$runtime->collect($plan,array_replace($target,['provider'=>'ssh']),'proxmox_api','test-secret'),'collection_invalid');
$reject(fn()=>$runtime->collect($plan,$target,'api_token','test-secret'),'permission_denied');
$ok($driver->calls===2,'mismatch before remote call');
$driver->fail=true;$reject(fn()=>$runtime->collect($plan,$target,'proxmox_api','test-secret'),'connector_auth_failed');$driver->fail=false;
$driver->bad=true;$reject(fn()=>$runtime->collect($plan,$target,'proxmox_api','test-secret'),'connector_protocol_error');$driver->bad=false;
$reject(fn()=>new CollectionResult($plan,new ConnectorResult([['blob'=>str_repeat('x',CollectionResult::MAX_BYTES)]]),'2026-11-12T00:00:00Z'),'collection_result_too_large');
try{$registry->register(new \Cajeer\Servers\Collector\ConnectorCollector('proxmox.inventory','proxmox',$connectors));throw new RuntimeException('duplicate accepted');}catch(LogicException){$ok(true,'duplicate registration rejected');}
// Independent collector plugged into the same runtime; its outputs are validated too.
$registry->register(new class implements CollectorInterface {
    public function name():string{return 'custom.inventory';} public function provider():string{return 'proxmox';}
    public function collect(CollectionContext $context,#[\SensitiveParameter] string $secret):ConnectorResult{return new ConnectorResult([['type'=>'virtual_machine','external_id'=>'qemu:43','fields'=>['name'=>'custom-vm']]],false);}
});
$ok($runtime->collect(new CollectionPlan('custom.inventory','inspect'),$target,'proxmox_api','test-secret')->items[0]['external_id']==='qemu:43','independent registered collector executes');
putenv('APP_ENV=local');$app=(new Application(dirname(__DIR__)))->boot();
try {$r=$app->container()->get(CollectionRuntimeService::class);$ok(array_values(array_unique(array_column($r->catalog(),'provider')))===['proxmox','vmmanager','ssh','winrm','snmp','prometheus'],'DI registers all six executable drivers');}finally{$app->terminate();}
$sql=file_get_contents(dirname(__DIR__).'/migrations/pgsql/0029_collection_runtime.sql');
$ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split($sql))===1,'migration parses');
echo "Collector runtime smoke: $n PASS\n";
