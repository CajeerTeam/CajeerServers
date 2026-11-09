<?php
declare(strict_types=1);

namespace Cajeer\Servers\Tests\Auth;

use Cajeer\Servers\Auth\AuthenticationService;
use Cajeer\Servers\Auth\SessionCookie;
use Cajeer\Servers\Auth\TokenCodec;
use Cajeer\Servers\Config\ConfigRepository;
use Cajeer\Servers\Runtime\HttpRequest;
use PHPUnit\Framework\TestCase;

final class AuthenticationTest extends TestCase
{
    public function testUnpredictableCredentialsHaveStableIdentifiersAndNoStoredPlaintext(): void
    {
        foreach (['cs1', 'at1'] as $kind) {
            $one = TokenCodec::issue($kind);
            $two = TokenCodec::issue($kind);
            self::assertNotSame($one['credential'], $two['credential']);
            self::assertSame(84, strlen($one['credential']));
            self::assertSame($one['id'], TokenCodec::parse($one['credential'], $kind));
            self::assertSame(hash('sha256', $one['credential']), $one['hash']);
            self::assertTrue(TokenCodec::validHash($one['hash'], $one['credential']));
            self::assertFalse(TokenCodec::validHash($one['hash'], $two['credential']));
            self::assertNull(TokenCodec::parse($one['credential'], $kind === 'cs1' ? 'at1' : 'cs1'));
        }
    }

    public function testSessionCookieRejectsDuplicatesAndForcesSecurePrefixUnderHttps(): void
    {
        $temporary = sys_get_temp_dir() . '/cajeer-cookie-test-' . bin2hex(random_bytes(8));
        mkdir($temporary, 0700);
        try {
            file_put_contents($temporary . '/app.php', "<?php return ['env'=>'production','url'=>'https://servers.example.test'];\n");
            $cookie = new SessionCookie(new ConfigRepository($temporary));
            $issued = TokenCodec::issue('cs1');
            $header = $cookie->set($issued['credential']);
            self::assertStringStartsWith('__Host-cajeer_session=', $header);
            self::assertStringContainsString('; Secure', $header);
            self::assertStringContainsString('; HttpOnly;', $header);
            self::assertSame($issued['credential'], $cookie->read(new HttpRequest('GET', '/', [
                'Cookie' => '__Host-cajeer_session=' . $issued['credential'],
            ])));
            self::assertNull($cookie->read(new HttpRequest('GET', '/', [
                'Cookie' => '__Host-cajeer_session=a; __Host-cajeer_session=b',
            ])));
            self::assertSame(['profile:read', 'sessions:read', 'tokens:read'], AuthenticationService::TOKEN_SCOPES);
        } finally {
            @unlink($temporary . '/app.php');
            @rmdir($temporary);
        }
    }
}
