#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Real PostgreSQL 17+ integration, uses a throwaway schema inside a cajeer_test_* DB. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\InventoryService;
use Cajeer\Servers\Inventory\IpamService;
use Cajeer\Servers\Inventory\RelationshipService;
use Cajeer\Servers\Inventory\TopologyService;
use Cajeer\Servers\Workspace\WorkspaceService;

if(getenv('CAJEER_PG_TEST')!=='1'||!preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')){
    fwrite(STDERR,"REFUSED: CAJEER_PG_TEST=1, CAJEER_PG_TEST_DATABASE=cajeer_test_* required.\n");exit(2);
}
if(!extension_loaded('pdo_pgsql')){fwrite(STDERR,"FAIL: ext-pdo_pgsql required.\n");exit(1);}
$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/cajeer-topology-'.bin2hex(random_bytes(6));
$schema='cajeer_topo_'.bin2hex(random_bytes(8));$pdo=null;$created=false;$fail=false;$n=0;
mkdir($dir.'/migrations',0700,true);
$ok=static function(bool $cond,string $why)use(&$n):void{if(!$cond)throw new RuntimeException('FAIL: '.$why);$n++;};
$reject=static function(int $status,callable $run,string $why)use($ok):void{
    try{$run();}catch(HttpException $e){$ok($e->status===$status,$why);return;}
    throw new RuntimeException('Expected HTTP '.$status.': '.$why);
};
$db=null;
try{
    $db=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),[
        'driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1',
        'port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432','database'=>getenv('CAJEER_PG_TEST_DATABASE'),
        'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer','password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'',
        'sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer',
    ]);
    $pdo=$db->connection();
    $ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;
    $pdo->exec('SET search_path TO "'.$schema.'",public');
    foreach(glob($root.'/migrations/pgsql/*.sql')?:[] as $file)copy($file,$dir.'/migrations/'.basename($file));
    $runner=new MigrationRunner($db,$dir.'/migrations');
    $ok(count($runner->run()['applied'])>=12,'all migrations including graph constraints applied');
    $ok($runner->run()['applied']===[],'migration replay idempotent');
    $ok($runner->validate()['valid'],'migration checksums valid');
    $stmt=$pdo->prepare('INSERT INTO users(email,name,password_hash) VALUES (:mail,:name,:hash) RETURNING id::text');
    $user=static function(string $label)use($stmt):string{
        $stmt->execute(['mail'=>$label.'-'.bin2hex(random_bytes(5)).'@example.invalid','name'=>$label,
            'hash'=>password_hash('integration-only-secret',PASSWORD_ARGON2ID)]);
        return (string)$stmt->fetchColumn();
    };
    $owner=$user('owner');$viewer=$user('viewer');$stranger=$user('stranger');
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :id,id FROM roles WHERE slug='administrator'")
        ->execute(['id'=>$owner]);
    $default=(string)$pdo->query("SELECT w.id::text FROM workspaces w JOIN organizations o ON o.id=w.organization_id WHERE o.slug='default' AND w.slug='default'")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES (:ws,:u,'owner')")
        ->execute(['ws'=>$default,'u'=>$owner]);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();
    $inventory=new InventoryService($db,$repo,$audit);$ipam=new IpamService($db,$repo,$audit);
    $workspace=new WorkspaceService($db,$audit);$rels=new RelationshipService($db,$repo,$audit);
    $topology=new TopologyService($db,$repo);
    $workspace->setMember($owner,$default,$viewer,'viewer');
    $org=(string)$pdo->query("SELECT id::text FROM organizations WHERE slug='default'")->fetchColumn();
    $private=$workspace->createWorkspace($owner,$org,'topology-private','Private');
    $cluster=$inventory->create($owner,['workspace_id'=>$default,'type'=>'cluster','name'=>'dc-cluster']);
    $node=$inventory->create($owner,['workspace_id'=>$default,'type'=>'hypervisor_node','name'=>'hv01']);
    $server=$inventory->create($owner,['workspace_id'=>$default,'type'=>'server','name'=>'host01']);
    $vm=$inventory->create($owner,['workspace_id'=>$default,'type'=>'virtual_machine','name'=>'vm01']);
    $router=$inventory->create($owner,['workspace_id'=>$default,'type'=>'router','name'=>'gw01']);
    $foreign=$inventory->create($owner,['workspace_id'=>$private['id'],'type'=>'server','name'=>'foreign01']);
    $edge=$rels->create($owner,['workspace_id'=>$default,'source_asset_id'=>$cluster['id'],'target_asset_id'=>$node['id'],'kind'=>'cluster_contains']);
    $ok($edge['version']===1 && $edge['kind']==='cluster_contains','persist cluster membership');
    $host=$rels->create($owner,['workspace_id'=>$default,'source_asset_id'=>$node['id'],'target_asset_id'=>$vm['id'],'kind'=>'hosts']);
    $ok($host['version']===1,'persist host relation');
    $reject(409,fn()=>$rels->create($owner,['workspace_id'=>$default,'source_asset_id'=>$server['id'],'target_asset_id'=>$vm['id'],'kind'=>'hosts']),'double structural parent denied');
    $reject(409,fn()=>$rels->create($owner,['workspace_id'=>$default,'source_asset_id'=>$vm['id'],'target_asset_id'=>$router['id'],'kind'=>'hosts']),'invalid type pairing denied');
    $reject(409,fn()=>$rels->create($owner,['workspace_id'=>$default,'source_asset_id'=>$cluster['id'],'target_asset_id'=>$foreign['id'],'kind'=>'depends_on']),'cross-workspace FK denied');
    $reject(403,fn()=>$rels->create($viewer,['workspace_id'=>$default,'source_asset_id'=>$server['id'],'target_asset_id'=>$router['id'],'kind'=>'depends_on']),'viewer cannot mutate');
    $reject(404,fn()=>$rels->list($stranger,$default),'stranger cannot enumerate');
    $ok(count($rels->list($viewer,$default)['data'])===2,'viewer reads workspace graph');
    $ab=$rels->create($owner,['workspace_id'=>$default,'source_asset_id'=>$server['id'],'target_asset_id'=>$router['id'],'kind'=>'depends_on']);
    $reject(409,fn()=>$rels->create($owner,['workspace_id'=>$default,'source_asset_id'=>$router['id'],'target_asset_id'=>$server['id'],'kind'=>'depends_on']),'dependency cycle denied');
    $bc=$rels->create($owner,['workspace_id'=>$default,'source_asset_id'=>$router['id'],'target_asset_id'=>$vm['id'],'kind'=>'depends_on']);
    $reject(409,fn()=>$rels->create($owner,['workspace_id'=>$default,'source_asset_id'=>$vm['id'],'target_asset_id'=>$server['id'],'kind'=>'depends_on']),'transitive cycle denied');
    // Security invariants must hold when callers bypass HTTP and issue SQL directly.
    $direct=static function(string $sql,array $params,string $state,string $label)use($pdo,$ok):void {
        try {$pdo->prepare($sql)->execute($params);}
        catch(PDOException $e) {$ok(($e->errorInfo[0]??$e->getCode())===$state,$label);return;}
        throw new RuntimeException('Direct SQL unexpectedly succeeded: '.$label);
    };
    $direct('INSERT INTO inventory_relationships(workspace_id,source_asset_id,target_asset_id,kind) VALUES (:ws,:source,:target,\'depends_on\')',
        ['ws'=>$default,'source'=>$vm['id'],'target'=>$server['id']],'23514','database blocks dependency cycles');
    $direct('UPDATE inventory_assets SET deleted_at=now() WHERE id=:id',
        ['id'=>$vm['id']],'23514','database blocks soft-delete of referenced asset');
    $direct('UPDATE inventory_assets SET workspace_id=:ws WHERE id=:id',
        ['ws'=>$private['id'],'id'=>$vm['id']],'23514','database blocks direct cross-workspace transfer');
    $linked=$rels->create($owner,['workspace_id'=>$default,'source_asset_id'=>$router['id'],'target_asset_id'=>$cluster['id'],'kind'=>'connected_to']);
    $reject(409,fn()=>$rels->create($owner,['workspace_id'=>$default,'source_asset_id'=>$cluster['id'],'target_asset_id'=>$router['id'],'kind'=>'connected_to']),'reverse undirected duplicate denied');
    $reject(412,fn()=>$rels->delete($owner,$default,$linked['id'],999),'stale version denied');
    $relUpdated=$rels->updateKind($owner,$default,$linked['id'],1,'depends_on');
    $ok($relUpdated['version']===2,'version bump and kind persisted');
    $reject(412,fn()=>$rels->updateKind($owner,$default,$linked['id'],1,'connected_to'),'stale ETag rejected');
    $reject(409,fn()=>$inventory->delete($owner,$vm['id'],1),'soft delete referenced asset blocked');
    $reject(409,fn()=>$workspace->transferAsset($owner,$default,$vm['id'],$private['id']),'cross-workspace transfer of linked asset blocked');
    $network=$ipam->create($owner,'network',$default,['name'=>'LAN','cidr'=>'192.0.2.0/24']);
    $port=$ipam->create($owner,'interface',$default,['asset_id'=>$server['id'],'name'=>'eth0']);
    $addr=$ipam->create($owner,'address',$default,['network_id'=>$network['id'],'address'=>'192.0.2.2']);
    $ipam->assign($owner,$default,$addr['id'],1,$port['id']);
    $graph=$topology->graph($viewer,$default,100);
    $ok(count($graph['nodes'])>=9,'graph includes assets and IPAM nodes');
    $ok(count($graph['edges'])>=8,'graph has persisted and derived edges');
    $ok(in_array('within_network',array_column($graph['edges'],'kind'),true),'network containment is graph edge');
    $ok(in_array('address_on_interface',array_column($graph['edges'],'kind'),true),'IP on interface is graph edge');
    $reject(404,fn()=>$topology->graph($stranger,$default),'topology cannot leak cross-workspace');
    $rels->delete($owner,$default,$host['id'],1);
    $ok($rels->list($owner,$default,100,0,'hosts')['pagination']['total']===0,'deleted relation removed');
    $ok((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action LIKE 'inventory.relationship.%'")->fetchColumn()>=7,'relation mutations audited');
    echo "PASS: $n PostgreSQL topology integration checks.\n";
}catch(Throwable $e){$fail=true;fwrite(STDERR,'FAIL: topology integration '.$e::class.': '.$e->getMessage()."\n");}
finally{
    if($created && $pdo instanceof PDO){
        try{$pdo->exec('SET search_path TO public');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
        catch(Throwable){$fail=true;fwrite(STDERR,"WARNING: topology schema cleanup failed.\n");}
    }
    foreach(glob($dir.'/migrations/*.sql')?:[] as $f)@unlink($f);
    @rmdir($dir.'/migrations');@rmdir($dir);
}
exit($fail?1:0);
