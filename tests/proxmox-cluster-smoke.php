#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Collector\{CollectorRegistry,CollectionPlan,CollectionRuntimeService};
use Cajeer\Servers\Connector\{ConnectorContext,ConnectorTransportException,ProxmoxClusterDiscovery,ProxmoxClusterStore,ProxmoxConnector};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Integration\ConnectorRegistry;
$n=0;$ok=static function(bool $v,string $m)use(&$n){if(!$v)throw new RuntimeException('FAIL '.$m);$n++;};
$reject=static function(callable $f,string $code)use($ok){try{$f();}catch(HttpException $e){$ok($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);return;}catch(ConnectorTransportException $e){$ok($e->category===$code,'expected '.$code);return;}throw new RuntimeException('Expected '.$code);};
$profile=['cluster'=>'prod','anchor_node'=>'pve-a','anchor_id'=>1,'primary_pin'=>str_repeat('a',64),'fallbacks'=>[]];
$target=['provider'=>'proxmox','endpoint_host'=>'pve-a.test','endpoint_port'=>8006,'credential_type'=>'proxmox_api','purpose'=>'api','proxmox_profile'=>$profile];
$secret='{"token_id":"svc@pve!read","token_secret":"fixture-secret"}';
$mode='ok';$calls=[];
$driver=new ProxmoxConnector(static function($url,$auth,$ca)use(&$mode,&$calls):string{
    $calls[]=parse_url($url,PHP_URL_PATH);$path=end($calls);
    if($auth!=='PVEAPIToken=svc@pve!read=fixture-secret')throw new RuntimeException('auth');
    if($path==='/api2/json/nodes/pve-a/status'){
        if($mode==='denied')throw ConnectorTransportException::forStatus(403);
        if($mode==='auth')throw ConnectorTransportException::forStatus(401);
        if($mode==='unavailable')throw ConnectorTransportException::forStatus(503);
        if($mode==='rate')throw ConnectorTransportException::forStatus(429);
        if($mode==='trust')throw new ConnectorTransportException('connector_trust_failed');
    }
    $cluster=['type'=>'cluster','name'=>'prod','quorate'=>$mode==='noquorum'?0:1,'nodes'=>3,'version'=>12,'expected_votes'=>3,'total_votes'=>2,'password'=>'must-not-leak'];
    $members=[['type'=>'node','name'=>'pve-a','nodeid'=>1,'local'=>1,'online'=>1,'ip'=>'192.0.2.1'],['type'=>'node','name'=>'pve-b','nodeid'=>$mode==='duplicate'?1:2,'local'=>0,'online'=>0,'ip'=>'192.0.2.2'],['type'=>'node','name'=>'pve-c','nodeid'=>3,'local'=>0,'online'=>1]];
    if($mode==='standalone')$members=[['type'=>'node','name'=>'pve-a','nodeid'=>0,'local'=>1,'online'=>1]];
    return json_encode(['data'=>match($path){
        '/api2/json/version'=>['version'=>'9.0.3'],
        '/api2/json/cluster/status'=>$mode==='standalone'?$members:[$cluster,...$members],
        '/api2/json/nodes'=>$mode==='standalone'?[['node'=>'pve-a','status'=>'online']]:[['node'=>'pve-a','status'=>'online'],['node'=>'pve-b','status'=>'offline'],...($mode==='foreign'?[['node'=>'foreign','status'=>'online']]:[])],
        '/api2/json/nodes/pve-a/status'=>['pveversion'=>'pve-manager/9.0.3/fixture','cpu'=>0.25,'cpuinfo'=>['cpus'=>32,'cores'=>16,'sockets'=>2,'model'=>'Fixture CPU'],'memory'=>['total'=>$mode==='malformed'?-1:137438953472,'used'=>4294967296],'swap'=>['total'=>0,'used'=>0],'rootfs'=>['total'=>100000,'used'=>20000],'uptime'=>1234,'arbitrary_secret'=>'must-not-leak'],
        '/api2/json/cluster/resources'=>[['type'=>'qemu','vmid'=>101,'node'=>'pve-a','status'=>'running']],
        default=>throw new RuntimeException('unexpected '.$path)
    }],JSON_THROW_ON_ERROR);
});
$registry=CollectorRegistry::forConnectors(new ConnectorRegistry($driver));$runtime=new CollectionRuntimeService($registry);
$plan=new CollectionPlan('proxmox.cluster','sync');$run=fn()=>$runtime->collect($plan,$target,'proxmox_api',$secret);
$ok(in_array('proxmox.cluster',array_column($runtime->catalog(),'name'),true),'registered executable collector');
$r=$run();$s=$r->connection['cluster_discovery'];$nodes=array_column($s['nodes'],null,'name');
$ok(!$r->complete&&count($r->items)===4,'cluster and three nodes, never full inventory');
$ok($r->items[0]['external_id']==='cluster:prod'&&$r->items[1]['parent_external_id']==='cluster:prod','stable cluster parent');
$ok($s['cluster']['quorate']&&$s['cluster']['config_version']===12&&$s['cluster']['total_votes']===2,'quorum and reported votes');
$ok($s['member_count']===3&&$s['visible_node_count']===2&&!$s['details_complete'],'partial ACL visibility');
$ok($nodes['pve-a']['details']['cpu']['logical_cpus']===32&&$nodes['pve-a']['details']['memory_bytes']['total']==='137438953472','hardware projection');
$ok($nodes['pve-b']['status']==='offline'&&$nodes['pve-b']['details_state']==='offline'&&$nodes['pve-b']['details']===null,'offline node preserved');
$ok($nodes['pve-c']['details_state']==='not_visible'&&$nodes['pve-c']['details']===null,'member not listed');
$ok(count($calls)===4&&!in_array('/api2/json/nodes/pve-b/status',$calls,true),'only visible online node queried');
$ok(!str_contains(json_encode($s),'must-not-leak')&&!str_contains(json_encode($r),'fixture-secret'),'no raw payload or credentials');
$prior=ProxmoxClusterStore::merge($s,null,'2026-11-13T10:00:00Z');
$mode='denied';$d=$run()->connection['cluster_discovery'];$merged=ProxmoxClusterStore::merge($d,$prior,'2026-11-13T10:01:00Z');
$ok($d['nodes'][0]['details_state']==='permission_denied'&&$d['nodes'][0]['details']===null,'403 partial details');
$ok($merged['nodes'][0]['last_details']['cpu']['logical_cpus']===32&&$merged['nodes'][0]['last_details_observed_at']==='2026-11-13T10:00:00Z','last known details retain timestamp');
$d['nodes'][0]['node_id']=9;$ok(ProxmoxClusterStore::merge($d,$prior,'2026-11-13T10:01:00Z')['nodes'][0]['last_details']===null,'node identity changed: no old detail');
$mode='noquorum';$r=$run();$ok(!$r->connection['cluster_discovery']['cluster']['quorate']&&$r->items[0]['fields']['status']==='unknown'&&!$r->complete,'loss of quorum is not deletion');
$mode='unavailable';$ok($run()->connection['cluster_discovery']['nodes'][0]['details_state']==='unavailable','node API 503 partial');
$mode='rate';$ok($run()->connection['cluster_discovery']['nodes'][0]['details_state']==='rate_limited','rate limit partial');
foreach(['auth'=>'connector_auth_failed','trust'=>'connector_trust_failed','malformed'=>'connector_protocol_error','duplicate'=>'connector_protocol_error','foreign'=>'connector_protocol_error'] as $m=>$code){$mode=$m;$reject($run,$code);}
$mode='standalone';$stand=$target;$stand['proxmox_profile']=['cluster'=>null,'anchor_node'=>'pve-a','anchor_id'=>null,'primary_pin'=>str_repeat('a',64),'fallbacks'=>[]];
$r=$runtime->collect(new CollectionPlan('proxmox.cluster','inspect'),$stand,'proxmox_api',$secret);$ok($r->connection['cluster_discovery']['standalone']&&count($r->items)===1&&!isset($r->items[0]['parent_external_id']),'standalone has no invented cluster');
$legacy=$target;unset($legacy['proxmox_profile']);$reject(fn()=>$runtime->collect($plan,$legacy,'proxmox_api',$secret),'collection_invalid');
$mode='ok';$r=$driver->execute(new ConnectorContext('proxmox','pve-a.test',8006,'proxmox_api'),'sync',$secret);$ok(!$r->complete,'legacy ACL-filtered inventory is partial too');
$ok(count((new \Cajeer\Servers\Database\SqlStatementParser())->split(file_get_contents(dirname(__DIR__).'/migrations/pgsql/0038_proxmox_cluster_discovery.sql')))===2,'migration parses');
putenv('APP_ENV=local');$app=(new \Cajeer\Servers\Kernel\Application(dirname(__DIR__)))->boot();
try{$routes=$app->container()->get(\Cajeer\Servers\Http\Router::class)->routes();$ok(count(array_filter($routes,fn($r)=>$r['path']==='/api/v1/connectors/targets/{id}/proxmox-cluster'&&in_array('GET',$r['methods'],true)))===1,'real protected route');$response=$app->handle(new \Cajeer\Servers\Runtime\HttpRequest('GET','/api/v1/connectors/targets/11111111-1111-4111-8111-111111111111/proxmox-cluster'));$ok(in_array($response->status,[401,503],true),'anonymous read denied');}finally{$app->terminate();}
echo "Proxmox cluster smoke: $n PASS\n";
