#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/core/autoload.php';

use Cajeer\Servers\Installer\InstallService;
use Cajeer\Servers\Installer\EnvironmentWriter;
use Cajeer\Servers\Installer\InstallState;
use Cajeer\Servers\Installer\WebInstaller;
use Cajeer\Servers\Runtime\HttpRequest;

ob_start();
$checks = 0;
$assert = static function (bool $condition, string $title) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $title);
    ++$checks;
    echo '[OK] ' . $title . "\n";
};
$root = sys_get_temp_dir() . '/cajeer-install-test-' . bin2hex(random_bytes(8));
mkdir($root . '/storage/app', 0700, true);
mkdir($root . '/migrations/pgsql', 0700, true);
mkdir($root . '/config', 0700, true);
copy(dirname(__DIR__) . '/migrations/pgsql/0003_installation_state.sql', $root . '/migrations/pgsql/0003_installation_state.sql');
file_put_contents($root . '/.env.example', "# test environment\nAPP_URL=http://127.0.0.1:8080\nAPP_KEY=\nDB_PASSWORD=\nAPP_LOCALE=ru\n");
$cleanup = static function (string $directory) use (&$cleanup): void {
    foreach (scandir($directory) ?: [] as $name) {
        if (in_array($name, ['.', '..'], true)) continue;
        $file = $directory . '/' . $name;
        if (is_dir($file) && !is_link($file)) $cleanup($file);
        else unlink($file);
    }
    rmdir($directory);
};
try {
    $writer = new EnvironmentWriter($root);
    $assert($writer->checkWritable(), 'environment writable');
    $writer->write(['APP_URL' => 'https://infra.example.test', 'DB_PASSWORD' => 'abc#"\\xyz', 'APP_KEY' => 'base64:' . base64_encode(random_bytes(32))]);
    $content = file_get_contents($root . '/.env');
    $assert(str_contains($content, '# test environment'), 'preserves comments');
    $assert(str_contains($content, 'APP_LOCALE=ru'), 'preserves unrelated configuration');
    $assert(str_contains($content, 'APP_URL="https://infra.example.test"'), 'writes URL');
    $assert(str_contains($content, 'DB_PASSWORD="abc#\\"\\\\xyz"'), 'correct quote/backslash escaping');
    $assert((fileperms($root . '/.env') & 0777) === 0600, 'private env file permissions');
    $writer->write(['DB_PASSWORD' => 'replaced']);
    $assert(substr_count(file_get_contents($root . '/.env'), 'DB_PASSWORD=') === 1, 'updates existing values without duplicates');
    $rejected = false;
    try { $writer->write(['APP_URL' => "https://host\ninjected=true"]); } catch (RuntimeException) { $rejected = true; }
    $assert($rejected, 'refuses newline injection');
    $rejected = false;
    try { $writer->write(['MALICIOUS' => 'yes']); } catch (RuntimeException) { $rejected = true; }
    $assert($rejected, 'refuses undeclared environment keys');

    $state = new InstallState($root);
    $assert(!$state->isInstalled(), 'initially not installed');
    $assert(!$state->verifyWebToken('invalid'), 'rejects invalid tokens');
    $token = $state->issueWebToken();
    $assert(strlen($token) === 43, 'generated token has 256 bits entropy');
    $assert($state->verifyWebToken($token), 'accepts correct web token');
    $assert((fileperms($state->directory() . '/web-install-token.sha256') & 0777) === 0600, 'token hash file permissions');
    $token2 = $state->issueWebToken();
    $assert($token2 !== $token && !$state->verifyWebToken($token) && $state->verifyWebToken($token2), 'rotates setup token');
    $assert(!str_contains(file_get_contents($state->directory() . '/web-install-token.sha256'), $token2), 'plaintext token never persisted');

    $service = new InstallService($root);
    $input = [
        'admin_email' => 'Root@Example.Test', 'admin_name' => 'Installer Test',
        'admin_password' => 'correct horse battery staple',
        'APP_ENV' => 'production', 'APP_URL' => 'https://infra.example.test',
        'APP_KEY' => '', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '5432', 'DB_DATABASE' => 'cajeer_install_test',
        'DB_USERNAME' => 'cajeer', 'DB_PASSWORD' => 'private',
        'DB_SSLMODE' => 'prefer', 'DB_SSLROOTCERT' => '',
    ];
    $normalized = $service->normalize($input);
    $assert($normalized['admin_email'] === 'root@example.test', 'normalizes email');
    $assert(str_starts_with($normalized['env']['APP_KEY'], 'base64:'), 'generates app key');
    $assert(strlen(base64_decode(substr($normalized['env']['APP_KEY'], 7), true)) === 32, 'app key has 256 bits entropy');
    $assert($normalized['env']['APP_DEBUG'] === 'false', 'debug disabled during initial setup');
    $negative = [
        ['admin_email' => 'invalid'], ['admin_name' => ''], ['admin_password' => 'short'],
        ['APP_URL' => 'http://example.test'], ['DB_CONNECTION' => 'mysql'],
        ['DB_PORT' => '70000'], ['DB_HOST' => 'db.example.test'], ['APP_KEY' => 'change-me-change-me-change-me-change-me'],
    ];
    foreach ($negative as $mutation) {
        $failed = false;
        try { $service->normalize(array_replace($input, $mutation)); } catch (RuntimeException) { $failed = true; }
        $assert($failed, 'rejects invalid ' . array_key_first($mutation));
    }
    $preflight = $service->preflight();
    $assert(count($preflight['checks']) >= 10, 'prerequisite checks actually run');
    if (!extension_loaded('pdo_pgsql')) {
        $assert($preflight['ok'] === false, 'missing pdo_pgsql blocks installation');
        $rejected = false;
        try { $service->install($input); } catch (RuntimeException) { $rejected = true; }
        $assert($rejected && !$state->isInstalled(), 'failed preflight cannot mark installed');
    }

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $web = new WebInstaller($service);
    $get = $web(new HttpRequest('GET', '/install', ['Accept'=>'text/html']));
    $assert($get->status === 200 && str_contains($get->body, '<form'), 'real web installer serves form');
    $assert(!str_contains($get->body, $token2), 'web installer does not disclose setup token');
    $wrong = $web(new HttpRequest('POST', '/install', ['Content-Type'=>'application/x-www-form-urlencoded'], 'csrf=invalid&setup_token=invalid'));
    $assert($wrong->status === 403, 'web installer rejects invalid CSRF');
    $state->finish('00000000-0000-0000-0000-000000000001', '2026-11-09 11:00:00+00');
    $assert($state->isInstalled(), 'atomic local installation marker');
    $assert(!$state->verifyWebToken($token2), 'setup token revoked after install');
    $rejected = false;
    try { $state->issueWebToken(); } catch (RuntimeException) { $rejected = true; }
    $assert($rejected, 'cannot reissue setup token after install');
    $assert($web(new HttpRequest('GET','/install'))->status === 410, 'finished web installer is closed');
    $assert($state->status()['installed_at'] === '2026-11-09 11:00:00+00', 'installation date persists');
    $assert((fileperms($state->directory() . '/installed.json') & 0777) === 0600, 'marker private permissions');
    echo "PASS: {$checks} installer native assertions (PostgreSQL integration separate).\n";
    ob_end_flush();
} catch (Throwable $exception) {
    ob_end_clean();
    fwrite(STDERR, "[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
} finally {
    $cleanup($root);
}
