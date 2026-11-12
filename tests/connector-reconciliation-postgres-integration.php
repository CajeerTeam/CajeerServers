#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Real PostgreSQL migration/identity/snapshot regression. Disposable schema only. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Connector\{ConnectorBindingService,ConnectorResult,ProxmoxConnector,ReconciliationService};
use Cajeer\Servers\Database\{ConnectionFactory,DatabaseManager,MigrationRunner};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Integration\ConnectorRegistry;
use Cajeer\Servers\Inventory\{HistoryService,InventoryRepository,InventoryService};
if(getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')){
    fwrite(STDERR,"REFUSED: disposable cajeer_test_* PostgreSQL required.\n");exit(2);
}
if(!extension_loaded('pdo_pgsql')){fwrite(STDERR,"FAIL: pdo_pgsql required.\n");exit(1);}
$root=dirname(__DIR__);$schema='cajeer_reconcile_'.bin2hex(random_bytes(8));$dir=sys_get_temp_dir().'/'.$schema;
mkdir($dir,0700);$pdo=null;$created=false;$failed=false;$n=0;
$ok=static function(bool $v,string $label)use(&$n):void{if(!$v)throw new RuntimeException('FAIL: '.$label);$n++;};
$reject=static function(int $code,callable $f,string $label)use($ok):void{try{$f();}catch(HttpException $e){$ok($e->status===$code,$label);return;}throw new RuntimeException('Expected rejection: '.$label);};
try{
    $db=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),['driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1','port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432','database'=>getenv('CAJEER_PG_TEST_DATABASE'),'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer','password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'','sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer']);
    $pdo=$db->connection();$ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PG17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');$pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;$pdo->exec('SET search_path TO "'.$schema.'", public');
    foreach(glob($root.'/migrations/pgsql/*.sql') as $file)if(strcmp(substr(basename($file),0,4),'0024')<0)copy($file,$dir.'/'.basename($file));
    $runner=new MigrationRunner($db,$dir);$runner->run();
    $actor=(string)$pdo->query("INSERT INTO users(email,name,password_hash) VALUES('reconcile@example.invalid','Reconcile','test-only') RETURNING id::text")->fetchColumn();
    $ws=(string)$pdo->query("SELECT id::text FROM workspaces WHERE slug='default'")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(:ws,:id,'owner')")->execute(['ws'=>$ws,'id'=>$actor]);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$inventory=new InventoryService($db,$repo,$audit);$history=new HistoryService($db,$repo,$inventory,$audit);
    $registry=new ConnectorRegistry(new ProxmoxConnector());$bindings=new ConnectorBindingService($db,$repo,$audit,$registry);
    // Simulate the old schema directly before current code requires the health view.
    $q=$pdo->prepare("INSERT INTO connector_targets(workspace_id,provider,name,endpoint_host,endpoint_port,created_by) VALUES(:ws,'proxmox','source','pve.test',8006,:actor) RETURNING id::text");$q->execute(['ws'=>$ws,'actor'=>$actor]);$targetId=(string)$q->fetchColumn();
    $target=['id'=>$targetId,'workspace_id'=>$ws,'provider'=>'proxmox','endpoint_host'=>'pve.test','endpoint_port'=>8006,'version'=>1];
    $target+=['credential_id'=>'11111111-1111-4111-8111-111111111111','credential_version'=>1,'purpose'=>'api'];
    $node=['type'=>'hypervisor_node','external_id'=>'node:a','fields'=>['name'=>'node-a','status'=>'online']];
    $vm=['type'=>'virtual_machine','external_id'=>'qemu:42','parent_external_id'=>'node:a','fields'=>['name'=>'legacy-vm','status'=>'online']];
    $event=['workspace_id'=>$ws,'external_id'=>'proxmox:'.$target['id'].':qemu:42','event_key'=>'legacy.vm','type'=>'virtual_machine','fields'=>$vm['fields']];
    // Simulate an existing 0.4.2 asset before the new migration.
    $legacy=$history->observe($actor,$event,connector:true)['asset_id'];
    copy($root.'/migrations/pgsql/0024_connector_reconciliation.sql',$dir.'/0024_connector_reconciliation.sql');
    $ok(count($runner->run()['applied'])===1 && $runner->run()['applied']===[],'0024 migration and replay');
    copy($root.'/migrations/pgsql/0025_connector_host_hardware.sql',$dir.'/0025_connector_host_hardware.sql');
    foreach(glob($root.'/migrations/pgsql/*.sql') as $file)if(strcmp(substr(basename($file),0,4),'0025')>=0)copy($file,$dir.'/'.basename($file));$runner->run();
    $service=new ReconciliationService($repo,$history,$audit);
    $rows=fn()=>array_column($service->resources($pdo,$actor,$target,100,0),null,'external_id');
    $ok($rows()['qemu:42']['asset_id']===$legacy && $rows()['qemu:42']['presence']==='unknown','legacy UUID preserved');
    $reject(422,fn()=>$history->observe($actor,array_replace($event,['event_key'=>'forged'])),'public observation cannot forge connector identity');
    $manual=$inventory->create($actor,['workspace_id'=>$ws,'type'=>'virtual_machine','name'=>'manual-match']);
    $ambiguous=['type'=>'virtual_machine','external_id'=>'qemu:43','parent_external_id'=>'node:a','fields'=>['name'=>'manual-match','status'=>'online']];
    $manifest=(new ProxmoxConnector())->manifest();
    $snapshot=fn(array $items,bool $complete=true)=>(new ConnectorResult($items,$complete))->validated($manifest);
    // Use later-than-legacy timestamps for legacy observations; backdate absence explicitly below to simulate elapsed grace.
    $now=fn()=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    $apply=function(ConnectorResult $s,?int $gen=null,?string $when=null,?array $t=null)use($db,$pdo,$repo,$service,$actor,$target,$now):array{
        $t??=$target;$gen??=$service->generation($pdo);$when??=$now();
        return $db->transaction(function(PDO $pdo)use($repo,$service,$actor,$t,$s,$gen,$when):array{$repo->lockWorkspace($pdo,$t['workspace_id']);return $service->apply($pdo,$actor,$t,$s,$gen,$when,null);});
    };
    $before=(int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn();
    $plan=$service->preview($pdo,$actor,$target,$snapshot([$node,$vm,$ambiguous]));
    $ok($plan['summary']['create']===1&&$plan['summary']['conflict']===1&&$plan['summary']['unchanged']===1,'real preview');
    $ok((int)$pdo->query('SELECT count(*) FROM inventory_assets')->fetchColumn()===$before && count($rows())===1,'preview no writes');
    $out=$apply($snapshot([$node,$vm,$ambiguous]));
    $ok($out['snapshot']['blocked']===1&&$out['outcomes']['created']===1&&$rows()['qemu:42']['asset_id']===$legacy,'atomic sync and identity');
    $ok($rows()['qemu:43']['conflict_code']==='ambiguous_name'&&$rows()['qemu:43']['asset_id']===null,'name conflict no duplicate');
    $gen=$rows()['qemu:43']['last_generation'];
    $resolve=function(string $ext,int $generation,?string $asset)use($db,$repo,$service,$actor,$target):array{return $db->transaction(function(PDO $pdo)use($repo,$service,$actor,$target,$ext,$generation,$asset):array{$repo->lockWorkspace($pdo,$target['workspace_id']);return $service->resolve($pdo,$actor,$target,$ext,$generation,$asset,null);});};
    $reject(409,fn()=>$resolve('qemu:43',$gen-1,$manual['id']),'stale conflict cannot resolve');
    $resolve('qemu:43',$gen,$manual['id']);$out=$apply($snapshot([$node,$vm,$ambiguous]));
    $ok($rows()['qemu:43']['asset_id']===$manual['id']&&$out['outcomes']['conflicted']>=1,'manual link and field conflict');
    $ok($repo->find($pdo,$actor,$manual['id'])['status']==='unknown','manual fields preserved');
    $renamed=$vm;$renamed['fields']['name']='renamed-vm';$out=$apply($snapshot([$node,$renamed,$ambiguous]));
    $ok($repo->find($pdo,$actor,$legacy)['name']==='renamed-vm'&&$rows()['qemu:42']['asset_id']===$legacy,'rename retains UUID');
    $repeat=$apply($snapshot([$node,$renamed,$ambiguous]));$ok($repeat['outcomes']['created']===0,'repeat no duplicates');
    $reject(409,fn()=>$apply($snapshot([$node]),$out['snapshot']['generation']),'old generation rejected');
    $reject(409,fn()=>$apply($snapshot([])),'empty snapshot rejected');
    $apply($snapshot([$node],false));$ok($rows()['qemu:42']['missed_complete']===0,'partial no absence');
    $apply($snapshot([$node]));$ok($rows()['qemu:42']['missed_complete']===1&&$rows()['qemu:42']['presence']==='seen','first absence grace');
    $pdo->prepare("UPDATE connector_discovered_resources SET absent_since=clock_timestamp()-interval '25 hours' WHERE target_id=:id AND external_id='qemu:42'")->execute(['id'=>$target['id']]);
    $apply($snapshot([$node]));$ok($rows()['qemu:42']['presence']==='stale','two full snapshots and 24h');
    $pdo->prepare("UPDATE connector_discovered_resources SET absent_since=clock_timestamp()-interval '73 hours' WHERE target_id=:id AND external_id='qemu:42'")->execute(['id'=>$target['id']]);
    $apply($snapshot([$node]));$ok($rows()['qemu:42']['presence']==='missing'&&$repo->find($pdo,$actor,$legacy)!==null,'missing does not delete asset');
    $apply($snapshot([$node],false));$ok($rows()['qemu:42']['presence']==='missing','partial preserves absence state');
    $apply($snapshot([$node,$renamed]));$ok($rows()['qemu:42']['presence']==='seen'&&$rows()['qemu:42']['missed_complete']===0,'reappearance resets absence');
    $apply($snapshot([$node]));$changed=$target;$changed['credential_version']=2;
    $apply($snapshot([$node],false),t:$changed);$ok($rows()['qemu:42']['missed_complete']===0,'scope revision resets absence');
    $reject(409,fn()=>$bindings->update($actor,$target['id'],$target['version'],['endpoint_host'=>'different.test']),'endpoint identity protected');
    $second=$ambiguous;$second['external_id']='qemu:44';$apply($snapshot([$node,$second],false),t:$changed);
    $resolve('qemu:44',$rows()['qemu:44']['last_generation'],null);$apply($snapshot([$node,$second],false),t:$changed);
    $ok($rows()['qemu:44']['asset_id']!==null&&$rows()['qemu:44']['asset_id']!==$manual['id'],'explicit distinct creation');
    $before=count($rows());$gen=$service->generation($pdo);$extra=$vm;$extra['external_id']='qemu:99';$extra['fields']['name']='rollback-vm';
    try{$db->transaction(function(PDO $pdo)use($repo,$service,$actor,$target,$snapshot,$node,$extra,$gen,$now):void{$repo->lockWorkspace($pdo,$target['workspace_id']);$service->apply($pdo,$actor,$target,$snapshot([$node,$extra]),$gen,$now(),null);throw new RuntimeException('forced rollback');});}catch(RuntimeException $e){if($e->getMessage()!=='forced rollback')throw $e;}
    $ok(count($rows())===$before&&!isset($rows()['qemu:99']),'outer failure rolls back mapping and asset');
    $ok($runner->validate()['valid'],'migration checksums');
    echo "Connector reconciliation PostgreSQL: $n PASS\n";
}catch(Throwable $e){$failed=true;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");}
finally{if($created&&$pdo instanceof PDO){$pdo->exec('SET search_path TO public');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}foreach(glob($dir.'/*.sql') as $f)unlink($f);rmdir($dir);}
exit($failed?1:0);
