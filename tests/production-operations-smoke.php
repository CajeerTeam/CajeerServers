#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Backup\{BackupKey,BackupService,BackupStatus,ManagedBackup,RecoveryDrill,PostgresStream};
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Observability\ProductionOperations;
use Cajeer\Servers\Update\Maintenance;
use Cajeer\Servers\Runtime\HttpRequest;

$src=dirname(__DIR__);$base=sys_get_temp_dir().'/cajeer-ops-'.bin2hex(random_bytes(8));mkdir($base,0700);
$root=$base.'/release';$local=$base.'/archives';$remote=$base.'/copies';$storage=$base.'/files';
foreach([$root,$local,$remote,$storage] as $dir)mkdir($dir,0700);
foreach(['config','routes','storage/app','storage/logs','storage/cache','storage/tmp','public'] as $dir)mkdir($root.'/'.$dir,0700,true);
foreach(['config','routes'] as $dir)foreach(glob($src.'/'.$dir.'/*.php') as $file)copy($file,$root.'/'.$dir.'/'.basename($file));
copy($src.'/VERSION',$root.'/VERSION');
$count=0;$app=null;$child=null;
$check=static function(bool $v,string $msg)use(&$count):void{if(!$v)throw new RuntimeException('FAIL '.$msg);$count++;echo "PASS $msg\n";};
$reject=static function(callable $f,string $msg)use($check):void{try{$f();}catch(Throwable){$check(true,$msg);return;}throw new RuntimeException('Expected rejection: '.$msg);};
$delete=static function(string $p)use(&$delete):void{if(is_dir($p)&&!is_link($p)){foreach(scandir($p) as $n)if($n!=='.'&&$n!=='..')$delete($p.'/'.$n);rmdir($p);}else unlink($p);};
try {
    $gate=new Maintenance($root);
    $code='fgets(STDIN);require $argv[1];$m=new Cajeer\\Servers\\Update\\Maintenance($argv[2]);$m->drainExclusive(function(){echo "drained\n";fflush(STDOUT);usleep(100000);},5);';
    $child=proc_open([PHP_BINARY,'-r',$code,$src.'/core/autoload.php',$root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($child))throw new RuntimeException('Cannot start drain process');
    $lease=$gate->shared();fwrite($pipes[0],"go\n");fclose($pipes[0]);
    // Probe the admission gate deterministically, while the first request still holds its lease.
    $denied=false;for($i=0;$i<100;$i++){try{$other=$gate->shared();fclose($other);}catch(Throwable){$denied=true;break;}usleep(20000);}
    $check($denied,'drain stops new admissions while existing lease is active');
    fclose($lease);$check(trim(stream_get_contents($pipes[1]))==='drained','drain waits for existing request then runs');
    fclose($pipes[1]);fclose($pipes[2]);$check(proc_close($child)===0,'drain process succeeded');$child=null;
    $lease=$gate->shared();fclose($lease);$check(true,'normal admission restored automatically');
    $lease=$gate->shared();$reject(fn()=>$gate->drainExclusive(fn()=>null,0),'drain timeout fails without mutation');fclose($lease);
    $lease=$gate->shared();fclose($lease);$check(true,'failed drain leaves no persistent blockade');
    $status=new BackupStatus($root.'/storage/app/backup-status.json');
    $check($status->read()['status']==='never','uninitialized backup state not success');
    $row=['status'=>'success','started_at'=>100,'last_success_at'=>110,'last_offsite_at'=>109,'bytes'=>123];$status->write($row);
    $check($status->read()===$row && (fileperms($root.'/storage/app/backup-status.json')&0077)===0,'durable private backup status');
    $row['status']='failed';$status->write($row);$check($status->read()['last_success_at']===110,'failed attempt preserves last success');
    file_put_contents($root.'/storage/app/backup-status.json','broken');$reject(fn()=>$status->read(),'corrupt status not reported as success');$status->write($row);
    $key=$base.'/backup.key';BackupKey::generate($key);
    $dump=$base.'/pg-dump-fixture';file_put_contents($dump,'#!'.PHP_BINARY."\n<?php fwrite(STDOUT,'PGDMP'.str_repeat('X',200000));\n");chmod($dump,0700);
    foreach(['APP_ENV'=>'local','APP_URL'=>'http://127.0.0.1:8080','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'VAULT_MASTER_KEY'=>'','VAULT_MASTER_KEY_FILE'=>'','DB_CONNECTION'=>'pgsql','DB_DATABASE'=>'cajeer_ops_fixture','STORAGE_DISK'=>'local','STORAGE_LOCAL_ROOT'=>$storage,'PG_DUMP_BINARY'=>$dump] as $k=>$v)putenv($k.'='.$v);
    file_put_contents($storage.'/file','private fixture');
    $app=(new Application($root))->boot();$service=new BackupService($app);$managed=new ManagedBackup($app);$identity='00000000-0000-0000-0000-000000000001';
    $prefix='cajeer-'.$identity.'-';$names=[];
    foreach(['20261111','20261112','20261113'] as $date){$name=$prefix.$date.'T000000Z-0123456789abcdef.cjdr';$names[]=$name;$service->create($local.'/'.$name,$key,false,null,null,$identity);}
    $current=$local.'/'.$names[2];$report=$service->verify($current,$key);
    $check($report['header']['consistent_storage']===true&&$report['header']['installation_id']===$identity,'authenticated managed archive metadata');
    $managed->copyVerified($service,$current,$remote.'/'.$names[2],$key,$identity,'cajeer_ops_fixture');
    $check(hash_file('sha256',$current)===hash_file('sha256',$remote.'/'.$names[2]),'offsite ciphertext copied and authenticated');
    $reject(fn()=>$managed->copyVerified($service,$current,$remote.'/'.$names[2],$key,$identity,'cajeer_ops_fixture'),'offsite overwrite refused');
    $reject(fn()=>$managed->copyVerified($service,$current,$remote.'/wrong.cjdr',$key,'wrong','cajeer_ops_fixture'),'wrong identity copy refused');
    $check(!file_exists($remote.'/wrong.cjdr'),'wrong identity not published');
    $bad=$local.'/'.$prefix.'20261114T000000Z-0123456789abcdef.cjdr';file_put_contents($bad,'corrupt');
    file_put_contents($local.'/manual.cjdr','operator owned');
    $prune=new ReflectionMethod(ManagedBackup::class,'prune');
    $check($prune->invoke($managed,$service,$local,$prefix,$names[2],$key,$identity,'cajeer_ops_fixture',2)===1,'retention prunes only excess verified archive');
    $check(!file_exists($local.'/'.$names[0])&&is_file($local.'/'.$names[1])&&is_file($current)&&is_file($bad)&&is_file($local.'/manual.cjdr'),'retains current and another valid archive, preserves corrupt/manual files');
    $reject(fn()=>$managed->run($local,$remote,$key,2,1),'same-filesystem offsite rejected');
    $check($status->read()['status']==='failed'&&$status->read()['last_success_at']===110,'invalid offsite configuration records failure');
    link($key,$base.'/key-hardlink');$reject(fn()=>BackupKey::load($key),'hardlinked backup key refused');unlink($base.'/key-hardlink');
    $reject(fn()=>$managed->run($local,null,$key,1,1),'retention below two rejected');
    $gate->exclusive(fn()=>$gate->enable());
    $reject(fn()=>$managed->run($local,null,$key,2,1),'managed backup refuses active maintenance');
    $check($status->read()['status']==='failed'&&$status->read()['last_success_at']===110,'managed failure recorded without losing prior success');
    $check($app->handle(new HttpRequest('GET','/api/v1/ready'))->status===503,'maintenance still blocks readiness');
    $gate->exclusive(fn()=>$gate->disable());
    $reject(fn()=>(new RecoveryDrill($app))->run($current,$key,'production','production',$base.'/drill-files',$base.'/drill-secrets'),'drill target namespace enforced');
    $reject(fn()=>$service->restore($current,$key,'cajeer_ops_fixture','cajeer_ops_fixture',$base.'/drill-files',$base.'/drill-secrets',true),'restore configured source database refused before connection');
    $reject(fn()=>$service->restore($current,$key,'new_db','new_db',$storage.'/nested',$base.'/drill-secrets',true),'restore target inside live storage refused');
    $method=new ReflectionMethod(PostgresStream::class,'connectionArgs');$stream=new PostgresStream($app->config());
    $reject(fn()=>$method->invoke($stream,$stream->settings(),'host=evil dbname=prod'),'libpq conninfo target injection refused');
    $ops=$app->container()->get(ProductionOperations::class)->snapshot(['available'=>false],['available'=>false]);
    $check($ops['queues']['default']===null&&$ops['scheduler']===null&&$ops['disk']['free_bytes']>0,'unavailable dependency not fabricated as zero; real disk measured');
    $metrics=ProductionOperations::metrics($ops);
    $check(str_contains($metrics,'cajeer_ops_queue_up{queue="default"} 0')&&str_contains($metrics,'cajeer_ops_backup_failed 1')&&!str_contains($metrics,$base),'operational metrics expose health without paths');
    echo "Production operations smoke: $count PASS\n";
} finally { if(is_resource($child)){proc_terminate($child,9);proc_close($child);} $app?->terminate();$delete($base); }
