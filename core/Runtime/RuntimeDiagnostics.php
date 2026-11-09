<?php
declare(strict_types=1);

namespace Cajeer\Servers\Runtime;

use Cajeer\Servers\Config\ConfigRepository;

/** Deterministic runtime prerequisites. Not a database or connector health check. */
final class RuntimeDiagnostics
{
    public function __construct(private readonly string $basePath, private readonly ConfigRepository $config)
    {
    }

    /** @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>} */
    public function run(): array
    {
        $checks = [];
        $check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };
        $check('php_version', PHP_VERSION_ID >= 80400, 'PHP 8.4+ required; current: ' . PHP_VERSION);
        foreach (['json', 'pdo', 'openssl', 'sodium'] as $extension) {
            $check('extension_' . $extension, extension_loaded($extension), 'ext-' . $extension);
        }
        $check('config_directory', is_dir($this->basePath . '/config') && is_readable($this->basePath . '/config'), 'Configuration files must be readable.');
        foreach (['storage/logs', 'storage/cache', 'storage/tmp'] as $directory) {
            $path = $this->basePath . '/' . $directory;
            $check(str_replace('/', '_', $directory), is_dir($path) && is_writable($path), $directory . ' must be writable.');
        }
        $env = (string) $this->config->get('app.env', 'production');
        $check('app_env', in_array($env, ['local', 'testing', 'staging', 'production'], true), 'APP_ENV: ' . $env);
        $debug = $this->config->get('app.debug', false);
        $check('app_debug', $env !== 'production' || $debug === false, 'APP_DEBUG must be false in production.');
        $url = (string) $this->config->get('app.url', '');
        $check('app_url', filter_var($url, FILTER_VALIDATE_URL) !== false && ($env !== 'production' || str_starts_with(strtolower($url), 'https://')), 'APP_URL must be a valid URL (HTTPS in production).');
        $key = \env('APP_KEY', '');
        $keyValid = is_string($key) && strlen($key) >= 32 && !str_contains(strtolower($key), 'change-me');
        if (is_string($key) && str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $keyValid = $decoded !== false && strlen($decoded) >= 32;
        }
        $check('app_key', $env !== 'production' || $keyValid, 'Production requires a unique cryptographically random APP_KEY (at least 32 bytes).');
        return ['ok' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks];
    }
}
