#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Isolated PostgreSQL 17+ IPAM exercise. Creates and deletes a private schema. Never use production DB. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Inventory\InventoryRepository;
use Cajeer\Servers\Inventory\InventoryService;
use Cajeer\Servers\Inventory\IpamService;
use Cajeer\Servers\Workspace\WorkspaceService;
use Cajeer\Servers\Http\Exception\HttpException;

if (getenv('CAJEER_PG_TEST')!=='1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'')) {
    fwrite(STDERR,"REFUSED: CAJEER_PG_TEST=1 and a cajeer_test_* database required.\n");exit(2);
}
if (!extension_loaded('pdo_pgsql')) {fwrite(STDERR,"FAIL: ext-pdo_pgsql required.\n");exit(1);}
$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/cajeer-ipam-pg-'.bin2hex(random_bytes(5));
$schema='cajeer_ipam_'.bin2hex(random_bytes(8));$created=false;$pdo=null;$failed=false;$checks=0;
mkdir($dir.'/migrations',0700,true);
$ok=static function(bool $v,string $name)use(&$checks):void {if(!$v)throw new RuntimeException('FAIL '.$name);$checks++;};
$reject=static function(int $status,callable $fn,string $name)use($ok):void {
    try{$fn();}catch(HttpException $e){$ok($e->status===$status,$name);return;}
    throw new RuntimeException('Expected HTTP '.$status.': '.$name);
};
try {
    $db=new DatabaseManager(new ConfigRepository($root.'/config'),new ConnectionFactory(),[
        'driver'=>'pgsql','host'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1','port'=>getenv('CAJEER_PG_TEST_PORT')?:'5432',
        'database'=>getenv('CAJEER_PG_TEST_DATABASE'),'username'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer',
        'password'=>getenv('CAJEER_PG_TEST_PASSWORD')?:'','sslmode'=>getenv('CAJEER_PG_TEST_SSLMODE')?:'prefer',
    ]);
    $pdo=$db->connection();
    $ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+');
    $pdo->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public');
    $pdo->exec('CREATE SCHEMA "'.$schema.'"');$created=true;
    $pdo->exec('SET search_path TO "'.$schema.'", public');
    foreach(glob($root.'/migrations/pgsql/*.sql') as $file)copy($file,$dir.'/migrations/'.basename($file));
    $migrator=new MigrationRunner($db,$dir.'/migrations');
    $result=$migrator->run();$ok(count($result['applied'])>=11,'all migrations apply');
    $ok($migrator->run()['applied']===[],'repeat migration idempotent');
    $ok($migrator->validate()['valid'],'migration checksums valid');
    $seed=$pdo->prepare('INSERT INTO users(email,name,password_hash) VALUES (:email,:name,:hash) RETURNING id::text');
    $user=static function(string $name)use($seed):string {
        $seed->execute(['email'=>$name.'-'.bin2hex(random_bytes(4)).'@example.invalid','name'=>$name,'hash'=>password_hash('integration_not_real_secret',PASSWORD_ARGON2ID)]);
        return (string)$seed->fetchColumn();
    };
    $owner=$user('owner');$viewer=$user('viewer');$foreign=$user('foreign');
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :u,id FROM roles WHERE slug='administrator'")->execute(['u'=>$owner]);
    $default=(string)$pdo->query("SELECT w.id::text FROM workspaces w JOIN organizations o ON o.id=w.organization_id WHERE o.slug='default' AND w.slug='default'")->fetchColumn();
    $pdo->prepare("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES (:w,:u,'owner')")->execute(['w'=>$default,'u'=>$owner]);
    $audit=new AuditLogger($db);$repo=new InventoryRepository();$inventory=new InventoryService($db,$repo,$audit);$ipam=new IpamService($db,$repo,$audit);$ws=new WorkspaceService($db,$audit);
    $ws->setMember($owner,$default,$viewer,'viewer');
    $org=(string)$pdo->query("SELECT id::text FROM organizations WHERE slug='default'")->fetchColumn();
    $other=$ws->createWorkspace($owner,$org,'ipam-private','IPAM Private');
    $ws->setMember($owner,$other['id'],$foreign,'viewer');
    $server=$inventory->create($owner,['workspace_id'=>$default,'type'=>'server','name'=>'net-host','ip_addresses'=>['192.0.2.200']]);
    $foreignAsset=$inventory->create($owner,['workspace_id'=>$other['id'],'type'=>'server','name'=>'remote-host']);
    $net=$ipam->create($owner,'network',$default,['name'=>'testnet','cidr'=>'192.0.2.0/24','vlan_id'=>210]);
    $ok($net['cidr']==='192.0.2.0/24' && $net['version']===1,'persist network');
    $net6=$ipam->create($owner,'network',$default,['name'=>'v6net','cidr'=>'2001:db8::/64']);
    $ok($net6['cidr']==='2001:db8::/64','IPv6 network');
    $reject(409,fn()=>$ipam->create($owner,'network',$default,['name'=>'overlap','cidr'=>'192.0.2.128/25']),'overlapping CIDR denied');
    $reject(403,fn()=>$ipam->create($viewer,'network',$default,['name'=>'nope','cidr'=>'198.51.100.0/24']),'viewer cannot create');
    $reject(404,fn()=>$ipam->list($viewer,'network',$other['id']),'cross-workspace enumeration denied');
    $privateNet=$ipam->create($owner,'network',$other['id'],['name'=>'same prefix','cidr'=>'192.0.2.0/24']);
    $ok($privateNet['cidr']===$net['cidr'],'network space isolated per workspace');
    $port=$ipam->create($owner,'interface',$default,['asset_id'=>$server['id'],'name'=>'eth0','mac_address'=>'AA:BB:CC:DD:EE:FF']);
    $ok($port['mac_address']==='aa:bb:cc:dd:ee:ff','MAC persists normalized');
    $reject(409,fn()=>$ipam->create($owner,'interface',$default,['asset_id'=>$server['id'],'name'=>'eth0']),'duplicate interface name denied');
    $reject(422,fn()=>$ipam->create($owner,'interface',$default,['asset_id'=>$foreignAsset['id'],'name'=>'cross']),'foreign asset rejected');
    $ip=$ipam->create($owner,'address',$default,['network_id'=>$net['id'],'address'=>'192.0.2.10','hostname'=>'node.example.net']);
    $ok($ip['status']==='reserved' && $ip['interface_id']===null && $ip['version']===1,'IP reservation');
    $v6=$ipam->create($owner,'address',$default,['network_id'=>$net6['id'],'address'=>'2001:db8::8']);
    $ok($v6['status']==='reserved','IPv6 address allocation');
    // Direct SQL updates must not strand reservations outside their network.
    try {
        $pdo->prepare('UPDATE inventory_networks SET cidr=CAST(:cidr AS cidr) WHERE id=:id')
            ->execute(['cidr'=>'192.0.3.0/24','id'=>$net['id']]);
        throw new RuntimeException('Database accepted moving a CIDR with reservations.');
    } catch(PDOException $e) {$ok(($e->errorInfo[0]??'')==='23514','PostgreSQL CIDR update guard');}
    $reject(409,fn()=>$ipam->create($owner,'address',$default,['network_id'=>$net['id'],'address'=>'192.0.2.10']),'duplicate address rejected');
    $reject(422,fn()=>$ipam->create($owner,'address',$default,['network_id'=>$net['id'],'address'=>'192.0.3.5']),'out-of-network rejected');
    $reject(422,fn()=>$ipam->assign($owner,$default,$ip['id'],1,$foreignAsset['id']),'non-interface assignment denied');
    $assigned=$ipam->assign($owner,$default,$ip['id'],1,$port['id']);
    $ok($assigned['status']==='assigned' && $assigned['version']===2,'assignment persisted');
    $reject(412,fn()=>$ipam->assign($owner,$default,$ip['id'],1,null),'stale version rejected');
    $reject(409,fn()=>$ipam->delete($owner,'interface',$default,$port['id'],1),'assigned interface deletion rejected');
    $reject(409,fn()=>$ipam->delete($owner,'network',$default,$net['id'],1),'network with assigned address deletion rejected');
    $unassigned=$ipam->assign($owner,$default,$ip['id'],2,null);
    $ok($unassigned['status']==='reserved' && $unassigned['version']===3,'unassign persists');
    $updated=$ipam->update($owner,'network',$default,$net['id'],1,['description'=>'new desc']);
    $ok($updated['version']===2 && $updated['description']==='new desc','network optimistic update');
    $reject(412,fn()=>$ipam->update($owner,'network',$default,$net['id'],1,['name'=>'stale']),'stale ETag rejected');
    $ok(count($ipam->list($owner,'network',$default)['data'])===2,'list networks in workspace');
    $ok(count($ipam->list($viewer,'address',$default)['data'])===2,'viewer may read IPAM');
    $ok(count($ipam->list($owner,'network',$other['id'])['data'])===1,'workspace isolation');
    // Database guard also rejects bypassing application-level validation.
    try {
        $pdo->prepare("INSERT INTO inventory_ip_addresses(workspace_id,network_id,address) VALUES (:ws,:net,CAST(:ip AS inet))")
            ->execute(['ws'=>$default,'net'=>$net['id'],'ip'=>'192.0.3.45']);
        throw new RuntimeException('Database accepted out-of-network address.');
    } catch(PDOException $e) {$ok(($e->errorInfo[0]??'')==='23514','PostgreSQL address containment trigger');}
    $ipam->delete($owner,'address',$default,$ip['id'],3);
    $ipam->delete($owner,'interface',$default,$port['id'],1);
    $ipam->delete($owner,'network',$default,$net['id'],2);
    $ok($ipam->list($owner,'network',$default)['pagination']['total']===1,'release dependent resources');
    $ok($inventory->read($owner,$server['id'])['ip_addresses']===['192.0.2.200'],'legacy Inventory addresses unchanged');
    $ok((int)$pdo->query("SELECT count(*) FROM audit_log WHERE action LIKE 'inventory.ipam.%'")->fetchColumn()>=10,'transactional audit events');
    echo "PASS: $checks PostgreSQL IPAM integration assertions.\n";
} catch(Throwable $e) {
    $failed=true;fwrite(STDERR,'FAIL: IPAM PostgreSQL integration: '.$e::class.': '.$e->getMessage()."\n");
} finally {
    if($created && $pdo instanceof PDO) {
        try{$pdo->exec('SET search_path TO public');$pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');}
        catch(Throwable){fwrite(STDERR,"WARNING: IPAM schema cleanup failed.\n");$failed=true;}
    }
    foreach(glob($dir.'/migrations/*.sql')?:[] as $file)@unlink($file);
    @rmdir($dir.'/migrations');@rmdir($dir);
}
exit($failed?1:0);
