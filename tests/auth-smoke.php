#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Auth\SessionCookie;
use Cajeer\Servers\Auth\TokenCodec;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Kernel\Application;
use Cajeer\Servers\Runtime\HttpRequest;

$count = 0;
$check = static function (bool $result, string $name) use (&$count): void {
    if (!$result) throw new RuntimeException('FAIL ' . $name);
    $count++;
    echo "[OK] $name\n";
};
$originalEnv = getenv('APP_ENV');
$originalUrl = getenv('APP_URL');
putenv('APP_ENV=local');
putenv('APP_URL=http://127.0.0.1:8080');
$app = null;
try {
    $seen = [];
    for ($i = 0; $i < 100; $i++) {
        $issued = TokenCodec::issue('cs1');
        $check(TokenCodec::parse($issued['credential'], 'cs1') === $issued['id'], 'session ID parsed ' . $i);
        $check(TokenCodec::validHash($issued['hash'], $issued['credential']), 'session digest matches ' . $i);
        $check(!isset($seen[$issued['id']]), 'session IDs unique ' . $i);
        $seen[$issued['id']] = true;
    }
    $token = TokenCodec::issue('at1');
    $check(TokenCodec::parse($token['credential'], 'at1') === $token['id'], 'API token parsed');
    $check(TokenCodec::parse($token['credential'], 'cs1') === null, 'API token cannot be a cookie');
    $check(TokenCodec::validHash($token['hash'], $token['credential']), 'API digest matches');
    $check(!TokenCodec::validHash($token['hash'], $token['credential'] . 'X'), 'mutated API token fails');
    $check(!str_contains($token['hash'], substr($token['credential'], -20)), 'hash does not include plaintext secret');
    foreach (['', 'cs1.a', 'cs1.' . $token['id'] . '.broken', 'at1.' . $token['id'] . '.broken'] as $invalid) {
        $check(TokenCodec::parse($invalid, 'cs1') === null, 'malformed token refused');
    }

    $config = new ConfigRepository(dirname(__DIR__) . '/config');
    $cookie = new SessionCookie($config);
    $session = TokenCodec::issue('cs1');
    $set = $cookie->set($session['credential']);
    $check(str_contains($set, 'cajeer_session=') && str_contains($set, 'SameSite=Strict') && str_contains($set, 'HttpOnly'), 'local cookie protected');
    $check(!str_contains($set, 'Secure'), 'local http cookie not Secure');
    $check($cookie->read(new HttpRequest('GET', '/', ['Cookie' => 'a=1; cajeer_session=' . $session['credential']])) === $session['credential'], 'cookie parsed');
    $check($cookie->read(new HttpRequest('GET', '/', ['Cookie' => 'cajeer_session=x; cajeer_session=y'])) === null, 'duplicate cookie refused');
    $check($cookie->read(new HttpRequest('GET', '/', ['Cookie' => 'cajeer_session=' . str_repeat('X', 300)])) === null, 'oversized cookie refused');
    $check(str_contains($cookie->clear(), 'Max-Age=0'), 'cookie removal uses expiry');
    $check(count(AuthenticationService::TOKEN_SCOPES) === 3, 'three narrow read-only token scopes');

    $app = (new Application(dirname(__DIR__)))->boot();
    foreach (['/api/v1/auth/me', '/api/v1/auth/csrf', '/api/v1/auth/sessions', '/api/v1/auth/tokens'] as $path) {
        $response = $app->handle(new HttpRequest('GET', $path));
        $check($response->status === 503, 'not installed: GET ' . $path . ' fails closed');
        $check(!str_contains($response->body, 'password_hash'), 'no credential details in response');
    }
    $request = static fn (string $path, string $body, array $headers = []): HttpRequest => new HttpRequest('POST', $path, ['Content-Type' => 'application/json', ...$headers], $body);
    $valid = json_encode(['email' => 'admin@example.test', 'password' => 'long-password-not-logged'], JSON_THROW_ON_ERROR);
    $check($app->handle($request('/api/v1/auth/login', $valid))->status === 503, 'login fails closed before installation');
    $check($app->handle($request('/api/v1/auth/login', $valid, ['Origin' => 'https://evil.example']))->status === 403, 'foreign Origin blocked');
    $check($app->handle($request('/api/v1/auth/login', $valid, ['Sec-Fetch-Site' => 'cross-site']))->status === 403, 'cross-site browser request blocked');
    $check($app->handle($request('/api/v1/auth/login', '{'))->status === 400, 'malformed login body rejected');
    $check($app->handle($request('/api/v1/auth/login', '{"email":"x"}'))->status === 422, 'missing password rejected');
    $check($app->handle($request('/api/v1/auth/login', '{"email":"a@b.c","password":"x","admin":true}'))->status === 422, 'unknown admin field rejected');
    $check($app->handle($request('/api/v1/auth/tokens', '{"name":"test","scopes":["profile:read"],"expires_in":3600}'))->status === 503, 'token creation fails closed without installation');
    $check($app->handle(new HttpRequest('POST', '/api/v1/auth/logout'))->status === 503, 'logout fails closed without installation');
    $check($app->handle(new HttpRequest('POST', '/api/v1/auth/password', ['Content-Type' => 'application/json'], '{"current_password":"a","new_password":"short"}'))->status === 422, 'password minimum enforced');
    $check($app->handle(new HttpRequest('GET', '/api/v1/inventory/assets'))->status === 404, 'unfinished infrastructure API still unavailable');
    echo "PASS: {$count} authentication smoke checks (PostgreSQL integration separate).\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $app?->terminate();
    if ($originalEnv === false) putenv('APP_ENV'); else putenv('APP_ENV=' . $originalEnv);
    if ($originalUrl === false) putenv('APP_URL'); else putenv('APP_URL=' . $originalUrl);
}
