#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Test only on a disposable PostgreSQL 17+ DB named cajeer_test_*. Not for production. */
require dirname(__DIR__) . '/core/autoload.php';
use Cajeer\Servers\Api\Controller\AdminController;
use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Auth\SessionCookie;
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Cache\RedisClient;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\{ConnectionFactory,DatabaseManager,MigrationRunner};
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Kernel\ServiceContainer;
use Cajeer\Servers\Observability\{Diagnostics,OperationalStats};
use Cajeer\Servers\Queue\{JobProcessor,PostgresQueueDriver};
use Cajeer\Servers\Runtime\{HttpRequest,RuntimeDiagnostics};
use Cajeer\Servers\Scheduler\Scheduler;
use Cajeer\Servers\Security\{Rbac,RateLimiter,SecretBox,TwoFactorManager};
use Cajeer\Servers\Storage\LocalDisk;

if (getenv('CAJEER_PG_TEST') !== '1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D', getenv('CAJEER_PG_TEST_DATABASE') ?: '')) {
    fwrite(STDERR,"SKIP: requires CAJEER_PG_TEST=1, CAJEER_PG_TEST_DATABASE=cajeer_test_*.\n"); exit(77);
}
if (!extension_loaded('pdo_pgsql')) { fwrite(STDERR,"SKIP: ext-pdo_pgsql unavailable.\n"); exit(77); }
$env=['APP_ENV'=>'local','APP_URL'=>'http://127.0.0.1:8080','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
 'DB_CONNECTION'=>'pgsql','QUEUE_CONNECTION'=>'pgsql','DB_HOST'=>getenv('CAJEER_PG_TEST_HOST')?:'127.0.0.1',
 'DB_PORT'=>getenv('CAJEER_PG_TEST_PORT')?:'5432','DB_DATABASE'=>getenv('CAJEER_PG_TEST_DATABASE'),
 'DB_USERNAME'=>getenv('CAJEER_PG_TEST_USERNAME')?:'cajeer','DB_PASSWORD'=>getenv('CAJEER_PG_TEST_PASSWORD')?:''];
$old=[]; foreach($env as $k=>$v) {$old[$k]=getenv($k);putenv($k.'='.$v);}
$root=sys_get_temp_dir().'/cajeer-admin-'.bin2hex(random_bytes(7));mkdir($root.'/storage/app',0700,true);
$users=[]; $jobs=[]; $pdo=null; $count=0;
$ok=static function(bool $value,string $name) use (&$count):void { if(!$value) throw new RuntimeException('FAIL: '.$name);$count++; };
$reject=static function(int $status,callable $fn,string $name) use ($ok):void {
    try { $fn(); } catch(HttpException $e) { $ok($e->status===$status,$name); return; }
    throw new RuntimeException('Expected HTTP '.$status.': '.$name);
};
try {
    $config=new ConfigRepository(dirname(__DIR__).'/config');
    $db=new DatabaseManager($config,new ConnectionFactory()); $pdo=$db->connection();
    $ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+');
    (new MigrationRunner($db,dirname(__DIR__).'/migrations/pgsql'))->run();
    $found=$pdo->query("SELECT COUNT(*) FROM permissions WHERE scope LIKE 'admin.%'")->fetchColumn();
    $ok((int)$found===4,'migrated admin permissions');
    $state=new InstallState($root); $state->finish('00000000-0000-4000-8000-000000000001',gmdate('c'));
    $audit=new AuditLogger($db); $box=new SecretBox(); $limiter=new RateLimiter($box);
    $mfa=new TwoFactorManager($db,$box,$audit,$limiter); $rbac=new Rbac($db,$audit);
    $auth=new AuthenticationService($db,$config,$state,new SessionCookie($config),$mfa,$limiter);
    $insert=$pdo->prepare('INSERT INTO users (name,email,password_hash) VALUES (:name,:email,:hash) RETURNING id::text');
    $password='Admin_Integration!'.bin2hex(random_bytes(8));$email='admin-e2e-'.bin2hex(random_bytes(4)).'@example.invalid';
    $insert->execute(['name'=>'Admin E2E','email'=>$email,'hash'=>password_hash($password,PASSWORD_ARGON2ID)]);
    $id=(string)$insert->fetchColumn();$users[]=$id;
    $pdo->prepare("INSERT INTO user_roles(user_id,role_id) SELECT :user,id FROM roles WHERE slug='administrator'")->execute(['user'=>$id]);
    $login=$auth->loginOrReject($email,$password,'192.0.2.90');
    $cookie=explode(';',$login['cookie'],2)[0];$csrf=$login['csrf_token'];
    $request=static fn(string $method,string $path,array $attributes=[],string $token=''):HttpRequest=>new HttpRequest($method,$path,
       ['Cookie'=>$cookie]+($token!==''?['X-CSRF-Token'=>$token]:[]),'',[], $attributes);
    $principal=$auth->authenticate($request('GET','/api/v1/admin/session'));
    $queue=new PostgresQueueDriver($db); $runtime=new RuntimeDiagnostics(dirname(__DIR__),$config);
    $storage=new LocalDisk($root.'/storage/files');$processor=new JobProcessor($runtime,$storage,$queue);
    $stats=new OperationalStats($config,$db,new RedisClient('127.0.0.1'));
    $scheduler=new Scheduler($db,$queue,$processor);
    $c=new ServiceContainer();$c->instance(DatabaseManager::class,$db);
    $diagnostics=new Diagnostics($runtime,$config,$c,$state);
    $controller=new AdminController($auth,$rbac,$db,$queue,$processor,$stats,$scheduler,$diagnostics,$audit);
    $profile=json_decode($controller->session($request('GET','/api/v1/admin/session'))->body,true);
    $ok($profile['user']['id']===$id,'authenticated real session');
    $ok(in_array('admin.jobs.execute',$profile['permissions'],true),'admin receives new permissions');
    $reject(403,fn()=>$controller->dispatch($request('POST','/api/v1/admin/jobs',['json'=>['type'=>'runtime.diagnostics']])),'mutation requires csrf');
    $create=$controller->dispatch($request('POST','/api/v1/admin/jobs',['json'=>['type'=>'runtime.diagnostics']],$csrf));
    $ok($create->status===202,'diagnostic job queued');
    $job=json_decode($create->body,true);$jobs[]=$job['id'];
    $found=json_decode($controller->job($request('GET','/api/v1/admin/jobs/'.$job['id'],['id'=>$job['id']]))->body,true);
    $ok($found['job']['id']===$job['id'] && !isset($found['job']['payload']),'persisted job and no payload leak');
    $list=json_decode($controller->jobs($request('GET','/api/v1/admin/jobs'))->body,true);
    $ok(in_array($job['id'],array_column($list['data'],'id'),true),'job listed through PostgreSQL');
    $ok($controller->scheduler($request('GET','/api/v1/admin/scheduler'))->status===200,'scheduler available');
    $memberPassword='Member_Integration!'.bin2hex(random_bytes(8));$memberEmail='member-e2e-'.bin2hex(random_bytes(4)).'@example.invalid';
    $insert->execute(['name'=>'Member E2E','email'=>$memberEmail,'hash'=>password_hash($memberPassword,PASSWORD_ARGON2ID)]);
    $memberId=(string)$insert->fetchColumn();$users[]=$memberId;
    $memberLogin=$auth->loginOrReject($memberEmail,$memberPassword,'192.0.2.91');
    $memberCookie=explode(';',$memberLogin['cookie'],2)[0];
    $reject(403,fn()=>$controller->jobs(new HttpRequest('GET','/api/v1/admin/jobs',['Cookie'=>$memberCookie])),'RBAC denies unauthorised member');
    $reject(401,fn()=>$controller->session(new HttpRequest('GET','/api/v1/admin/session')),'guest denied');
    $ok(count($audit->recent(100))>0,'audit trail records job request');
    echo "PASS: $count assertions against disposable PostgreSQL.\n";
} catch(Throwable $e) {
    fwrite(STDERR,'[FAIL] admin PostgreSQL integration: '.$e::class.': '.$e->getMessage()."\n");exit(1);
} finally {
    if ($pdo!==null) {
        foreach($jobs as $jobId) {try {$pdo->prepare('DELETE FROM queue_jobs WHERE id=:id')->execute(['id'=>$jobId]);}catch(Throwable) {}}
        foreach($users as $userId) {try {$pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$userId]);}catch(Throwable) {}}
    }
    foreach($old as $key=>$value) { if($value===false) putenv($key);else putenv($key.'='.$value); }
    foreach(['storage/app/installed.json','storage/app/installation.json'] as $file) @unlink($root.'/'.$file);
    @rmdir($root.'/storage/files');@rmdir($root.'/storage/app');@rmdir($root.'/storage');@rmdir($root);
}
