#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Real PostgreSQL 17/18, Redis and encrypted pg_dump/pg_restore on disposable databases. */
require dirname(__DIR__).'/core/autoload.php';
use Cajeer\Servers\Backup\BackupKey;
use Cajeer\Servers\Installer\InstallService;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Credentials\CredentialsVault;
use Cajeer\Servers\Update\UpgradeService;

if (getenv('CAJEER_PG_TEST')!=='1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D',getenv('CAJEER_PG_TEST_DATABASE')?:'') || !extension_loaded('pdo_pgsql')) {
    fwrite(STDERR,"Refused: explicit disposable PostgreSQL test configuration and pdo_pgsql required.\n"); exit(2);
}
$source=dirname(__DIR__); $dir=sys_get_temp_dir().'/cajeer-upgrade-pg-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
$old=$dir.'/old'; $new=$dir.'/new';
$fromDb='cajeer_test_upgrade_'.bin2hex(random_bytes(6)); $restoreDb='cajeer_test_recovered_'.bin2hex(random_bytes(6));
$host=getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1'; $port=getenv('CAJEER_PG_TEST_PORT')?:'5432';
$user=getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer'; $pass=getenv('CAJEER_PG_TEST_PASSWORD')?:''; $ssl=getenv('CAJEER_PG_TEST_SSLMODE')?:'disable';
$dsn=static fn(string $db):string=>"pgsql:host=$host;port=$port;dbname=$db;sslmode=$ssl";
$admin=null; $pdo=null; $restored=null; $app=null; $created=[]; $checks=0; $failed=false;
$assert=static function(bool $ok,string $name)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$checks++;echo "PASS $name\n";};
$reject=static function(callable $call,string $name)use($assert):void{try{$call();}catch(Throwable){$assert(true,$name);return;}throw new RuntimeException('Expected rejection: '.$name);};
$delete=static function(string $p)use(&$delete):void{if(is_dir($p)&&!is_link($p)){foreach(scandir($p) as $n)if($n!=='.'&&$n!=='..')$delete($p.'/'.$n);rmdir($p);}else unlink($p);};
try {
    $admin=new PDO($dsn('postgres'),$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach([$fromDb,$restoreDb] as $db){$admin->exec('CREATE DATABASE "'.$db.'"');$created[]=$db;}
    foreach([$old,$new] as $root){
        foreach(['config','routes','storage/app','storage/cache','storage/logs','storage/tmp','storage/files','migrations/pgsql'] as $d)mkdir($root.'/'.$d,0700,true);
        foreach(['config','routes','migrations/pgsql'] as $d)foreach(glob($source.'/'.$d.'/*') as $f)if(is_file($f))copy($f,$root.'/'.$d.'/'.basename($f));
        copy($source.'/.env.example',$root.'/.env.example');
    }
    file_put_contents($old.'/VERSION',"0.9.1\n"); copy($source.'/VERSION',$new.'/VERSION');
    // Rehearse the 0.9.1 schema: all sixty historical SQL files are unchanged.
    $installer=new InstallService($old);
    $input=['APP_ENV'=>'local','APP_URL'=>'http://127.0.0.1:8080','APP_KEY'=>'','DB_CONNECTION'=>'pgsql','DB_HOST'=>$host,'DB_PORT'=>$port,'DB_DATABASE'=>$fromDb,'DB_USERNAME'=>$user,'DB_PASSWORD'=>$pass,'DB_SSLMODE'=>$ssl,'DB_SSLROOTCERT'=>'','admin_email'=>'upgrade@example.invalid','admin_name'=>'Upgrade Test','admin_password'=>'Upgrade test private administrator password'];
    $result=$installer->install($input);
    $assert($result['installed']&&count($result['migrations'])===60,'clean install all sixty migrations');
    $oldId=$installer->state()->record()['installation_id'];
    copy($old.'/.env',$new.'/.env');chmod($new.'/.env',0600);
    copy($old.'/storage/app/installed.json',$new.'/storage/app/installed.json');chmod($new.'/storage/app/installed.json',0600);
    $pdo=new PDO($dsn($fromDb),$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $hash=$pdo->query("SELECT password_hash FROM users WHERE email='upgrade@example.invalid'")->fetchColumn();
    $assert(is_string($hash)&&password_verify($input['admin_password'],$hash),'administrator credentials persisted');
    $vault='base64:'.base64_encode(random_bytes(32));
    $realRedisPort=getenv('CAJEER_REDIS_TEST_PORT')?:'6379'; $realRedisHost=getenv('CAJEER_REDIS_TEST_HOST')?:'127.0.0.1';
    foreach(['DB_CONNECTION'=>'pgsql','DB_HOST'=>$host,'DB_PORT'=>$port,'DB_DATABASE'=>$fromDb,'DB_USERNAME'=>$user,'DB_PASSWORD'=>$pass,'DB_SSLMODE'=>$ssl,'DB_SSLROOTCERT'=>'','APP_ENV'=>'local','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:8080','STORAGE_DISK'=>'local','STORAGE_LOCAL_ROOT'=>$new.'/storage/files','VAULT_MASTER_KEY'=>$vault,'VAULT_MASTER_KEY_FILE'=>'','REDIS_HOST'=>$realRedisHost,'REDIS_PORT'=>'1','REDIS_TLS'=>'false'] as $k=>$v)putenv($k.'='.$v);
    file_put_contents($new.'/storage/files/probe.bin',random_bytes(4096)); $fileHash=hash_file('sha256',$new.'/storage/files/probe.bin');
    $key=$dir.'/backup.key';BackupKey::generate($key); $archive=$dir.'/pre-upgrade.cjdr';
    $app=(new Application($new))->boot(); $app->container()->get(CredentialsVault::class)->initialize();
    $upgrade=new UpgradeService($app);
    $reject(fn()=>$upgrade->run($old,$archive,$key),'upgrade requires maintenance'); $upgrade->enable();
    $marker=$new.'/storage/app/installed.json';$markerBytes=file_get_contents($marker);
    $state=json_decode($markerBytes,true);$state['installation_id']='00000000-0000-0000-0000-000000000001';file_put_contents($marker,json_encode($state));
    $reject(fn()=>$upgrade->run($old,$archive,$key),'wrong installation identity rejected before backup');file_put_contents($marker,$markerBytes);
    $oldSql=$old.'/migrations/pgsql/0060_network_configuration_backup.sql';$sql=file_get_contents($oldSql);file_put_contents($oldSql,$sql."\n-- altered\n");
    $reject(fn()=>$upgrade->run($old,$archive,$key),'source migration checksum drift rejected');file_put_contents($oldSql,$sql);
    $assert(!file_exists($archive)&&$upgrade->status()['upgrade']===null,'no side effects before verified source');
    $reject(fn()=>$upgrade->run($old,$archive,$dir.'/missing.key'),'backup failure stops upgrade');
    $assert($upgrade->status()['upgrade']['phase']==='backup'&&!file_exists($archive),'backup failure leaves resumable journal');
    $reject(fn()=>$upgrade->disable(),'incomplete upgrade cannot reopen');
    // Deliberately unavailable Redis: archive and migrations finish, but readiness fails.
    $reject(fn()=>$upgrade->run($old,$archive,$key),'readiness failure keeps upgrade offline');
    $journal=$upgrade->status()['upgrade'];
    $assert($journal['phase']==='verify'&&is_file($archive),'verified backup and migration phase persisted');
    $assert($journal['backup_sha256']===hash_file('sha256',$archive),'backup hash pinned');
    $assert((new InstallState($new))->record()['version']==='0.9.1','failed verification does not advance marker');
    $app->terminate();$app=null;putenv('REDIS_PORT='.$realRedisPort);
    $app=(new Application($new))->boot();$upgrade=new UpgradeService($app);$result=$upgrade->run($old,$archive,$key);
    $assert($result['upgrade']['phase']==='complete'&&$result['maintenance'],'resume completed without reopening');
    $assert($result['upgrade']['backup_sha256']===$journal['backup_sha256'],'resume reused exact archive');
    $assert((new InstallState($new))->record()['version']==='0.9.2','successful verification advances marker');
    $assert((new InstallState($new))->record()['installation_id']===$oldId,'installation identity preserved');
    $assert($pdo->query("SELECT password_hash FROM users WHERE email='upgrade@example.invalid'")->fetchColumn()===$hash,'existing user unchanged');
    $assert($app->container()->get(CredentialsVault::class)->check()['ok'],'Vault verifier survives upgrade');
    $reject(fn()=>$upgrade->restore($key,$fromDb,$fromDb,$dir.'/bad-storage',$dir.'/bad-secrets'),'rollback cannot overwrite original database');
    $recovery=$dir.'/recovery';$storage=$dir.'/restored-storage';$report=$upgrade->restore($key,$restoreDb,$restoreDb,$storage,$recovery);
    $assert($report['restored']&&$report['source_version']==='0.9.1','real pg_restore into isolated replacement');
    $restored=new PDO($dsn($restoreDb),$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $assert($restored->query("SELECT installation_id::text FROM cajeer_installation WHERE singleton_id=1")->fetchColumn()===$oldId,'restored installation identity');
    $assert($restored->query("SELECT password_hash FROM users WHERE email='upgrade@example.invalid'")->fetchColumn()===$hash,'restored account data');
    $assert(hash_file('sha256',$storage.'/probe.bin')===$fileHash,'restored storage bytes');
    $assert(trim(file_get_contents($recovery.'/vault.key'))===$vault,'restored Vault key');
    $assert($upgrade->status()['maintenance'],'restore leaves original system offline');
    $assert(!$upgrade->disable()['maintenance'],'explicit readiness gate reopens original system');
    echo "Upgrade PostgreSQL integration: $checks PASS\n";
} catch(Throwable $error){$failed=true;fwrite(STDERR,'FAIL upgrade PostgreSQL integration: '.$error::class."\n");}
finally{
    $app?->terminate();$app=null;$pdo=null;$restored=null;
    if($admin!==null)foreach(array_reverse($created) as $db)try{$admin->exec('DROP DATABASE "'.$db.'" WITH (FORCE)');}catch(Throwable){$failed=true;}
    $delete($dir);
}
exit($failed?1:0);
