#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Storage\{LocalStoragePath,StorageDiskInterface};
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Release\DeploymentVerifier;

$base=sys_get_temp_dir().'/cajeer-storage-isolation-'.bin2hex(random_bytes(8));
$root=$base.'/release';$source=dirname(__DIR__);$app=null;$count=0;
$check=static function(bool $v,string $msg)use(&$count):void{if(!$v)throw new RuntimeException('FAIL '.$msg);$count++;echo "PASS $msg\n";};
$reject=static function(callable $f,string $msg)use($check):void{try{$f();}catch(RuntimeException){$check(true,$msg);return;}throw new RuntimeException('Expected rejection: '.$msg);};
$delete=static function(string $p)use(&$delete):void{if(is_dir($p)&&!is_link($p)){foreach(scandir($p) as $n)if($n!=='.'&&$n!=='..')$delete($p.'/'.$n);rmdir($p);}else unlink($p);};
mkdir($base,0700);
try{
    foreach(['config','routes','storage/app','storage/cache','storage/logs','storage/tmp','storage/files','public','core'] as $dir)mkdir($root.'/'.$dir,0700,true);
    foreach(['config','routes'] as $dir)foreach(glob($source.'/'.$dir.'/*.php') as $file)copy($file,$root.'/'.$dir.'/'.basename($file));
    copy($source.'/VERSION',$root.'/VERSION');
    $check(LocalStoragePath::resolve($root)===$root.'/storage/files','default isolated data path accepted');
    $check(LocalStoragePath::resolve($root,$base.'/new-data/subdir')===$base.'/new-data/subdir','uncreated external root resolves existing parent');
    $check(LocalStoragePath::resolve($root,$root.'/storage/files/subdir')===$root.'/storage/files/subdir','dedicated in-tree data child accepted');
    $check(LocalStoragePath::resolve($root,$base.'/release-other')===$base.'/release-other','path prefix is not a directory boundary');
    foreach([$root,$base,'/',$root.'/public',$root.'/public/future',$root.'/core',$root.'/config/future',$root.'/storage/app',$root.'/storage/files/../app','relative'] as $path)$reject(fn()=>LocalStoragePath::resolve($root,$path),'unsafe configured root rejected: '.basename($path));
    symlink($root.'/public',$base.'/public-alias');
    $reject(fn()=>LocalStoragePath::resolve($root,$base.'/public-alias/new'),'uncreated path via public symlink ancestor rejected');
    $reject(fn()=>LocalStoragePath::resolve($root,$base.'/public-alias'),'direct storage symlink refused');
    file_put_contents($base.'/file','x');$reject(fn()=>LocalStoragePath::resolve($root,$base.'/file/child'),'file ancestor rejected');
    putenv('APP_ENV=local');putenv('APP_URL=http://127.0.0.1:8080');putenv('STORAGE_DISK=local');putenv('STORAGE_LOCAL_ROOT='.$root.'/public/future');
    $app=(new Application($root))->boot();
    $reject(fn()=>$app->container()->get(StorageDiskInterface::class),'production storage container refuses public configuration');
    $rows=(new DeploymentVerifier($app))->verify()['checks'];
    $row=array_values(array_filter($rows,static fn($r)=>$r['name']==='configured_storage_isolation'))[0];
    $check(!$row['ok'],'release check detects configured public storage');
    $check(!file_exists($root.'/public/future'),'unsafe directory was never created');
    $app->terminate();$app=null;
    putenv('STORAGE_LOCAL_ROOT='.$base.'/safe-data');
    $app=(new Application($root))->boot();$disk=$app->container()->get(StorageDiskInterface::class);
    $disk->put('probe/file.txt','private');$check($disk->get('probe/file.txt')==='private','safe external storage real write/read');
    $disk->delete('probe/file.txt');$check(!$disk->exists('probe/file.txt'),'safe external storage real delete');
    echo "Storage isolation smoke: $count PASS\n";
}finally{$app?->terminate();$delete($base);}
