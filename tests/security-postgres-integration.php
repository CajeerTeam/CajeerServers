#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Real PostgreSQL 17+ integration; NEVER on a production database.
 * CAJEER_PG_TEST=1 CAJEER_PG_TEST_DATABASE=cajeer_test_security \
 * CAJEER_PG_TEST_USERNAME=cajeer CAJEER_PG_TEST_PASSWORD=... \
 * php tests/security-postgres-integration.php
 */
require dirname(__DIR__) . '/core/autoload.php';
use Cajeer\Servers\Audit\AuditLogger;
use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Auth\SessionCookie;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Database\ConnectionFactory;
use Cajeer\Servers\Database\DatabaseManager;
use Cajeer\Servers\Database\MigrationRunner;
use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Security\RateLimiter;
use Cajeer\Servers\Security\SecretBox;
use Cajeer\Servers\Security\SignedPayload;
use Cajeer\Servers\Security\TwoFactorManager;
use Cajeer\Servers\Security\WebhookManager;

if (getenv('CAJEER_PG_TEST') !== '1' || !preg_match('/^cajeer_test_[a-z0-9_]+$/D', getenv('CAJEER_PG_TEST_DATABASE') ?: '')) {
    fwrite(STDERR, "SKIP: set CAJEER_PG_TEST=1 and CAJEER_PG_TEST_DATABASE=cajeer_test_* on a disposable PostgreSQL DB.\n");
    exit(77);
}
if (!extension_loaded('pdo_pgsql')) { fwrite(STDERR,"SKIP: ext-pdo_pgsql unavailable.\n"); exit(77); }
$env = [
 'APP_ENV' => 'local', 'APP_URL' => 'http://127.0.0.1:8080',
 'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => getenv('CAJEER_PG_TEST_HOST') ?: '127.0.0.1',
 'DB_PORT' => getenv('CAJEER_PG_TEST_PORT') ?: '5432',
 'DB_DATABASE' => getenv('CAJEER_PG_TEST_DATABASE'), 'DB_USERNAME' => getenv('CAJEER_PG_TEST_USERNAME') ?: 'cajeer',
 'DB_PASSWORD' => getenv('CAJEER_PG_TEST_PASSWORD') ?: '', 'DB_SSLMODE' => getenv('CAJEER_PG_TEST_SSLMODE') ?: 'prefer',
];
$original=[]; foreach ($env as $key=>$val) { $original[$key]=getenv($key); putenv($key.'='.$val); }
$root = sys_get_temp_dir().'/cajeer-security-'.bin2hex(random_bytes(8));
mkdir($root.'/storage/app',0700,true);
$pdo = null; $userIds=[]; $checks=0;
$ok=static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Failed: '.$label);
    $checks++;
};
$reject=static function (int $status, callable $run, string $label) use ($ok): void {
    try { $run(); } catch (HttpException $error) { $ok($error->status===$status,$label); return; }
    throw new RuntimeException('Expected '.$status.': '.$label);
};
try {
    $db=new DatabaseManager(new ConfigRepository(dirname(__DIR__).'/config'), new ConnectionFactory());
    $pdo=$db->connection();
    $ok((int)$pdo->query("SELECT current_setting('server_version_num')")->fetchColumn()>=170000,'PostgreSQL 17+');
    (new MigrationRunner($db,dirname(__DIR__).'/migrations/pgsql'))->run();
    $ok($pdo->query("SELECT to_regclass('user_mfa') IS NOT NULL")->fetchColumn() !== false,'security migration installed');
    $state=new InstallState($root);
    $state->finish('00000000-0000-4000-8000-000000000001',gmdate('c'));
    $config=new ConfigRepository(dirname(__DIR__).'/config');
    $box=new SecretBox(); $audit=new AuditLogger($db); $limiter=new RateLimiter($box);
    $mfa=new TwoFactorManager($db,$box,$audit,$limiter);
    $rbac=new Rbac($db,$audit);
    $webhooks=new WebhookManager($db,$box,$audit,$limiter);
    $auth=new AuthenticationService($db,$config,$state,new SessionCookie($config),$mfa,$limiter);
    $password='Security!StrongPassword'.bin2hex(random_bytes(8));
    $email='security-admin-'.bin2hex(random_bytes(4)).'@example.invalid';
    $insert=$pdo->prepare('INSERT INTO users (email,name,password_hash) VALUES (:email,:name,:hash) RETURNING id::text');
    $insert->execute(['email'=>$email,'name'=>'Security Admin','hash'=>password_hash($password,PASSWORD_ARGON2ID)]);
    $adminId=(string)$insert->fetchColumn(); $userIds[]=$adminId;
    $roleId=$pdo->query("SELECT id::text FROM roles WHERE slug='administrator'")->fetchColumn();
    $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (:user,:role)')->execute(['user'=>$adminId,'role'=>$roleId]);
    $login=$auth->loginOrReject($email,$password,'192.0.2.44');
    $cookie=explode(';',$login['cookie'],2)[0];
    $request=static fn (): HttpRequest=>new HttpRequest('GET','/api/v1/auth/me',['Cookie'=>$cookie]);
    $principal=$auth->authenticate($request());
    $rbac->requirePermission($principal,'security.roles.manage');
    $ok(true,'admin inherits seeded role grants');
    $member=$rbac->createUser($principal,'member-'.bin2hex(random_bytes(4)).'@example.invalid','Regular', 'LongUserPassword!'.bin2hex(random_bytes(8)));
    $userIds[]=$member['id'];
    $weak=['mode'=>'session','user'=>['id'=>$member['id']]];
    $reject(403, static fn ()=>$rbac->requirePermission($weak,'security.audit.read'), 'member cannot read audit');
    $role=$rbac->createRole($principal,'custom-'.bin2hex(random_bytes(3)),'Custom viewer',['security.roles.read']);
    $rbac->membership($principal,$member['id'],$role['slug'],true);
    $rbac->requirePermission($weak,'security.roles.read');
    $reject(403,static fn()=>$rbac->requirePermission($weak,'security.users.manage'),'membership has least privilege');
    $reject(409,static fn()=>$rbac->membership($principal,$adminId,'administrator',false),'cannot revoke own admin role');
    $reject(409,static fn()=>$rbac->setUserActive($principal,$adminId,false),'cannot deactivate self');
    $rbac->setUserActive($principal,$member['id'],false);
    $ok(true,'user deactivation committed');
    $enrollment=$mfa->enroll($principal,$password);
    $ok(str_starts_with($enrollment['otpauth_url'],'otpauth://totp/') && strlen($enrollment['secret'])===32,'enrollment secret provided');
    $codes=$mfa->confirm($principal,TwoFactorManager::totp(TwoFactorManager::decode32($enrollment['secret']),time()));
    $ok(count($codes)===10,'MFA enabled and recovery codes issued once');
    $fresh=$auth->authenticate($request());
    $ok($fresh['mfa_verified']===true,'current session elevated after MFA confirm');
    $reject(401,static fn()=>$auth->loginOrReject($email,$password,'192.0.2.45'),'password-only MFA bypass denied');
    $newLogin=$auth->loginOrReject($email,$password,'192.0.2.46',$codes[0]);
    $ok(is_array($newLogin['user']),'one-use recovery code authenticates');
    $reject(401,static fn()=>$auth->loginOrReject($email,$password,'192.0.2.47',$codes[0]),'reused recovery code denied');
    $token=$auth->createToken($fresh,'MFA client',['profile:read'],600);
    $tokenPrincipal=$auth->authenticate(new HttpRequest('GET','/api/v1/auth/me',['Authorization'=>'Bearer '.$token['token']]));
    $ok($tokenPrincipal['mode']==='token','MFA-issued bearer token works');
    $hook=$webhooks->create($fresh,'security-test-hook');
    $body='{"type":"example","version":1}'; $timestamp=(string)time();
    $headers=['Content-Type'=>'application/json','X-Cajeer-Timestamp'=>$timestamp,
      'X-Cajeer-Signature'=>SignedPayload::signature($body,$hook['secret'],(int)$timestamp)];
    $hookReq=new HttpRequest('POST','/api/v1/webhooks/'.$hook['id'].'/events',$headers,$body);
    $received=$webhooks->ingest($hook['id'],$hookReq);
    $ok($received['status']==='received','verified webhook persisted');
    $reject(409,static fn()=>$webhooks->ingest($hook['id'],$hookReq),'replay refused');
    $bad=$headers; $bad['X-Cajeer-Signature']='sha256='.str_repeat('0',64);
    $reject(401,static fn()=>$webhooks->ingest($hook['id'],new HttpRequest('POST','/api/v1/webhooks/'.$hook['id'].'/events',$bad,$body)),'invalid signature refused');
    $webhooks->revoke($fresh,$hook['id']);
    $reject(401,static fn()=>$webhooks->ingest($hook['id'],$hookReq),'revoked webhook refused');
    $auditRows=$audit->recent(100);
    $ok(count($auditRows)>0,'audit trail readable');
    $auditId = $pdo->query('SELECT id::text FROM audit_log ORDER BY created_at DESC LIMIT 1')->fetchColumn();
    try { $pdo->prepare('DELETE FROM audit_log WHERE id=:id')->execute(['id' => $auditId]); throw new RuntimeException('Audit delete unexpectedly allowed'); }
    catch (PDOException) { $ok(true,'audit log delete blocked by trigger'); }
    echo "PASS: {$checks} assertions against real disposable PostgreSQL.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'[FAIL] security postgres integration: '.$error::class.': '.$error->getMessage()."\n"); exit(1);
} finally {
    if ($pdo !== null) {
        foreach ($userIds as $id) {
            try { $pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$id]); } catch (Throwable) {}
        }
    }
    foreach ($original as $name=>$value) { if ($value===false) putenv($name); else putenv($name.'='.$value); }
    @unlink($root.'/storage/app/installation.json');
    @unlink($root.'/storage/app/installed.json');
    @rmdir($root.'/storage/app'); @rmdir($root.'/storage'); @rmdir($root);
}
