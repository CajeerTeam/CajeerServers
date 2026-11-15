#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';

use Cajeer\Servers\Update\Maintenance;
use Cajeer\Servers\Update\UpgradeService;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Installer\InstallService;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Cli\CommandRunner;
use Cajeer\Servers\Runtime\HttpRequest;

$source=dirname(__DIR__);
$root=sys_get_temp_dir().'/cajeer-upgrade-test-'.bin2hex(random_bytes(8));
mkdir($root,0700);
$count=0;
$check=static function(bool $ok,string $name)use(&$count):void { if(!$ok)throw new RuntimeException('FAIL '.$name); $count++; echo "PASS $name\n"; };
$reject=static function(callable $f,string $name)use($check):void { try{$f();}catch(Throwable){$check(true,$name);return;}throw new RuntimeException('Expected rejection: '.$name); };
$delete=static function(string $path)use(&$delete):void { if(is_dir($path)&&!is_link($path)){foreach(scandir($path) as $n)if($n!=='.'&&$n!=='..')$delete($path.'/'.$n);rmdir($path);}else unlink($path); };
$app=null;
try {
    foreach(['config','routes','storage/app','storage/logs','storage/cache','storage/tmp','storage/files','migrations/pgsql'] as $d) mkdir($root.'/'.$d,0700,true);
    foreach(['config','routes'] as $d)foreach(glob($source.'/'.$d.'/*.php') as $f)copy($f,$root.'/'.$d.'/'.basename($f));
    copy($source.'/VERSION',$root.'/VERSION'); copy($source.'/.env.example',$root.'/.env.example');
    copy($source.'/migrations/pgsql/0003_installation_state.sql',$root.'/migrations/pgsql/0003_installation_state.sql');
    $gate=new Maintenance($root);
    $check(!$gate->active(),'initially open');
    $lease=$gate->shared();
    $reject(fn()=>$gate->exclusive(fn()=>$gate->enable()),'active request prevents exclusive update');
    // Independent process cannot acquire exclusive lock while the first process owns a shared lease.
    $code='require $argv[1]; try {(new Cajeer\\Servers\\Update\\Maintenance($argv[2]))->exclusive(fn()=>null); exit(2);} catch(Throwable){exit(0);}';
    $process=proc_open([PHP_BINARY,'-r',$code,$source.'/core/autoload.php',$root],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot launch lock regression child.');
    foreach($pipes as $pipe)fclose($pipe);
    $check(proc_close($process)===0,'cross-process shared lock excludes upgrade');
    fclose($lease);
    $gate->exclusive(function()use($gate,$reject){$gate->enable();$reject(fn()=>$gate->shared(),'exclusive update blocks requests');});
    $check((new Maintenance($root))->active(),'maintenance persists across process objects');
    $reject(fn()=>$gate->shared(),'persistent marker blocks new shared lease');
    $check((fileperms($root.'/storage/app/maintenance.json')&0077)===0,'maintenance marker private');
    $app=(new Application($root))->boot();
    $response=$app->handle(new HttpRequest('POST','/api/v1/auth/login'));
    $check($response->status===503 && ($response->headers['Retry-After']??'')==='60','HTTP writes blocked with 503 Retry-After');
    $check($app->handle(new HttpRequest('GET','/api/v1/ready'))->status===503,'readiness remains unavailable');
    $check($app->handle(new HttpRequest('GET','/api/v1/live'))->status===200,'liveness still responds');
    $runner=new CommandRunner($app);
    $check($runner->run(['cajeer','queue:work','--once'])===1,'CLI worker blocked before DB access');
    $check($runner->run(['cajeer','upgrade:apply','--previous=/old'])===1,'offline confirmation mandatory');
    $check($runner->run(['cajeer','upgrade:apply','--offline','--previous=/old','--archive=/a','--key-file=/k','--offline'])===1,'duplicate option refused');
    $gate->exclusive(fn()=>$gate->save(['phase'=>'migrate']));
    $reject(fn()=>$gate->exclusive(fn()=>$gate->disable()),'incomplete journal cannot be disabled');
    unlink($root.'/storage/app/maintenance.json');
    $check($gate->active(),'missing maintenance marker cannot bypass incomplete upgrade');
    $reject(fn()=>$gate->shared(),'journal itself blocks traffic');
    $gate->exclusive(fn()=>$gate->save(['phase'=>'complete']));
    $gate->exclusive(fn()=>$gate->disable());
    $lease=$gate->shared();fclose($lease);
    $check(!$gate->active(),'completed journal permits resumption');
    file_put_contents($root.'/storage/app/upgrade.json','invalid');
    $check($app->handle(new HttpRequest('GET','/api/v1/health'))->status===503,'damaged upgrade journal fails closed');
    unlink($root.'/storage/app/upgrade.json');
    $check($app->handle(new HttpRequest('GET','/api/v1/live'))->status===200,'liveness after journal removal');
    $state=new InstallState($root);
    $token=$state->issueWebToken(); $state->issueWebToken();
    $reject(fn()=>(new InstallService($root))->install([], $token),'rotated token rejected inside installer lock');
    file_put_contents($root.'/storage/app/installed.json','{"installed_at":"now"}');
    $reject(fn()=>$state->record(),'invalid marker identity rejected');
    unlink($root.'/storage/app/installed.json');
    $state->finish('00000000-0000-0000-0000-000000000001','2026-11-15T00:00:00Z');
    $check($state->record()['version']==='0.9.3','marker records installed code version');
    $old=$state->record();$old['version']='0.9.2';file_put_contents($root.'/storage/app/installed.json',json_encode($old));
    $check($gate->requiresUpgrade(),'old marker detects code version drift');
    $check($app->handle(new HttpRequest('GET','/api/v1/health'))->status===503,'old marker cannot serve upgraded code');
    $state->finish($old['installation_id'],$old['installed_at']);
    $check($runner->run(['cajeer','upgrade:apply','--previous=/old','--archive=/a','--key-file=/k','--offline'])===1,'upgrade refused without maintenance');
    echo "Upgrade native smoke: $count PASS. PostgreSQL upgrade/recovery tested separately.\n";
} finally { $app?->terminate(); $delete($root); }
