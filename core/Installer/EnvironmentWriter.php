<?php
declare(strict_types=1);

namespace Cajeer\Servers\Installer;

use RuntimeException;

/** Preserves unrelated .env settings while atomically updating installer-owned keys. */
final class EnvironmentWriter
{
    public function __construct(private readonly string $root) {}

    /** @param array<string,string> $values */
    public function write(array $values): void
    {
        $path = $this->root . '/.env';
        $template = is_file($path) ? $path : $this->root . '/.env.example';
        if (is_link($path) || is_link($template)) throw new RuntimeException('Environment file must not be a symlink.');
        $original = @file_get_contents($template);
        if ($original === false || strlen($original) > 1024 * 1024) throw new RuntimeException('Cannot read environment template.');
        $allowed = ['APP_NAME','APP_ENV','APP_DEBUG','APP_URL','APP_KEY','APP_LOCALE',
            'DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','DB_SSLMODE','DB_SSLROOTCERT','DB_CONNECT_TIMEOUT'];
        $replacements = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new RuntimeException('Invalid installer environment field: ' . $key);
            }
            $replacements[$key] = $value;
        }
        $seen = [];
        $lines = preg_split('/\r\n|\n|\r/', $original);
        if (!is_array($lines)) throw new RuntimeException('Invalid environment file.');
        foreach ($lines as &$line) {
            if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match)) continue;
            $key = $match[1];
            if (!array_key_exists($key, $replacements)) continue;
            if (isset($seen[$key])) throw new RuntimeException('Duplicate environment variable: ' . $key);
            $seen[$key] = true;
            $line = $key . '=' . self::quote($replacements[$key]);
        }
        unset($line);
        foreach ($replacements as $key => $value) {
            if (!isset($seen[$key])) $lines[] = $key . '=' . self::quote($value);
        }
        $data = implode("\n", $lines);
        if (!str_ends_with($data, "\n")) $data .= "\n";
        $temporary = tempnam($this->root, '.cajeer-env-');
        if ($temporary === false) throw new RuntimeException('Cannot create temporary configuration.');
        try {
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $data, LOCK_EX) !== strlen($data) || !rename($temporary, $path)) {
                throw new RuntimeException('Unable to atomically save .env.');
            }
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    public function checkWritable(): bool
    {
        $path = $this->root . '/.env';
        return !is_link($path) && is_writable($this->root) && (!is_file($path) || (is_readable($path) && is_writable($path)));
    }

    private static function quote(string $value): string
    {
        // EnvironmentLoader accepts double-quoted escaped backslashes and quotes.
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
