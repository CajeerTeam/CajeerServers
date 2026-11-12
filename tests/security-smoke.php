#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/core/autoload.php';
use Cajeer\Servers\Security\SecretBox;
use Cajeer\Servers\Security\SignedPayload;
use Cajeer\Servers\Security\TwoFactorManager;
use Cajeer\Servers\Security\Rbac;
use Cajeer\Servers\Security\ApiToken;
use Cajeer\Servers\Security\CsrfProtection;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Http\Router;

$count = 0;
$check = static function (bool $ok, string $message) use (&$count): void {
    if (!$ok) throw new RuntimeException($message);
    $count++;
};
$throws = static function (callable $cb, string $label) use ($check): void {
    try { $cb(); } catch (Throwable) { $check(true, $label); return; }
    throw new RuntimeException('Expected failure: ' . $label);
};
$originalEnv = getenv('APP_ENV'); $originalUrl = getenv('APP_URL');
putenv('APP_ENV=local'); putenv('APP_URL=http://127.0.0.1:8080');
$app = null;
try {
    $secret = new SecretBox('safe-security-test-secret-do-not-use-in-production-123456789');
    $plaintext = random_bytes(100);
    $sealed = $secret->seal($plaintext, 'mfa:123');
    $check($secret->open($sealed, 'mfa:123') === $plaintext, 'encrypted secret round-trip');
    $check($sealed !== $secret->seal($plaintext, 'mfa:123'), 'random unique nonce');
    $throws(static fn () => $secret->open($sealed, 'webhook:123'), 'associated data enforced');
    $mutated = $sealed; $mutated[strlen($sealed) - 1] = chr(ord($mutated[strlen($sealed) - 1]) ^ 1);
    $throws(static fn () => $secret->open($mutated, 'mfa:123'), 'auth tag tamper rejected');
    $throws(static fn () => $secret->open('plaintext', 'mfa:123'), 'plaintext secret rejected');
    $check(strlen($secret->digest('sensitive', 'mfa')) === 64, 'keyed secret digest');
    $check($secret->digest('x','mfa') !== $secret->digest('x','rate'), 'purpose separation');
    for ($i=0; $i<30; $i++) {
        $random = random_bytes(20);
        $check(TwoFactorManager::decode32(TwoFactorManager::base32($random)) === $random, 'base32 roundtrip');
    }
    $check(TwoFactorManager::totp('12345678901234567890', 59) === '287082', 'RFC6238 SHA1 TOTP 59 seconds');
    $check(TwoFactorManager::totp('12345678901234567890', 1111111109) === '081804', 'RFC6238 2005 vector');
    $throws(static fn () => TwoFactorManager::decode32('XX!-INVALID'), 'invalid authenticator key rejected');
    $body = '{"event":"test"}'; $signSecret = rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    $ts = time();
    $signature = SignedPayload::signature($body, $signSecret, $ts);
    $check(strlen(SignedPayload::verify($body, $signSecret, (string)$ts, $signature, $ts)) === 64, 'signed webhook accepted');
    $throws(static fn () => SignedPayload::verify($body . 'x', $signSecret, (string)$ts, $signature, $ts), 'webhook body tamper rejected');
    $throws(static fn () => SignedPayload::verify($body, $signSecret, (string)($ts-301), $signature, $ts), 'expired webhook rejected');
    $throws(static fn () => SignedPayload::verify($body, $signSecret, 'x', $signature, $ts), 'invalid timestamp rejected');
    $throws(static fn () => SignedPayload::verify($body, $signSecret, (string)$ts, 'sha256=zz', $ts), 'invalid digest rejected');
    $check(count(Rbac::PERMISSIONS) === 45, 'forty-five active RBAC permissions');
    $check(ApiToken::validateScopes(['profile:read']) === ['profile:read'], 'valid API scopes accepted');
    $throws(static fn () => ApiToken::validateScopes(['profile:read','profile:read']), 'duplicate API scope rejected');
    $throws(static fn () => ApiToken::validateScopes(['security.roles.manage']), 'privileged token scope rejected');
    $throws(static fn () => ApiToken::authorize(['profile:read'],['tokens:read']), 'insufficient scope denied');
    $csrf = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    CsrfProtection::verify(hash('sha256', $csrf), $csrf);
    $check(true, 'valid session csrf accepted');
    $throws(static fn () => CsrfProtection::verify(hash('sha256', $csrf), $csrf.'x'), 'csrf tamper rejected');

    $app = (new Application(dirname(__DIR__)))->boot();
    $router = $app->container()->get(Router::class);
    $paths = array_column($router->routes(), 'path');
    foreach (['/api/v1/security/users','/api/v1/security/roles','/api/v1/security/audit',
        '/api/v1/security/webhooks','/api/v1/auth/mfa', '/api/v1/auth/mfa/enroll', '/api/v1/webhooks/{id}/events'] as $path) {
        $check(in_array($path, $paths, true), 'route present ' . $path);
    }
    foreach (['/api/v1/security/users','/api/v1/security/roles','/api/v1/security/audit','/api/v1/security/webhooks','/api/v1/auth/mfa'] as $path) {
        $resp = $app->handle(new HttpRequest('GET', $path));
        $check($resp->status === 503, 'fails closed without installed instance');
        $check(!str_contains($resp->body, 'password_hash'), 'no credential leakage');
        $check(($resp->headers['Cache-Control'] ?? '') === 'no-store', 'cache disabled');
    }
    $payload = json_encode(['name'=>'ops'], JSON_THROW_ON_ERROR);
    $post = $app->handle(new HttpRequest('POST','/api/v1/security/roles',['Content-Type'=>'application/json'],$payload));
    $check($post->status === 422, 'RBAC route validates required fields');
    $check(in_array($app->handle(new HttpRequest('GET','/api/v1/inventory/assets'))->status, [401,503], true), 'protected inventory route denies guests');
    echo "PASS: {$count} security smoke assertions (PostgreSQL integration separate).\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error::class . ': ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $app?->terminate();
    if ($originalEnv === false) putenv('APP_ENV'); else putenv('APP_ENV='.$originalEnv);
    if ($originalUrl === false) putenv('APP_URL'); else putenv('APP_URL='.$originalUrl);
}
