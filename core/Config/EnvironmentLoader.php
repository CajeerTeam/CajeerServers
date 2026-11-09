<?php
declare(strict_types=1);

namespace Cajeer\Servers\Config;

use RuntimeException;

/** Loads dotenv files without overriding variables supplied by the process. */
final class EnvironmentLoader
{
    /** @var array<string, string> */
    private array $loaded = [];

    public function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        if (!is_readable($path)) {
            throw new RuntimeException('Cannot read environment file: ' . basename($path));
        }
        $size = filesize($path);
        if ($size === false || $size > 1024 * 1024) {
            throw new RuntimeException('Environment file must not exceed 1 MiB.');
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Failed to read environment file.');
        }
        $parsed = [];
        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/D', $line, $matches)) {
                throw new RuntimeException(sprintf('Invalid .env syntax at line %d.', $number + 1));
            }
            $key = $matches[1];
            $parsed[$key] = self::parseValue($matches[2], $number + 1);
        }
        // No partial environment mutation on syntax errors.
        foreach ($parsed as $key => $value) {
            if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
                continue;
            }
            if (!putenv($key . '=' . $value)) {
                throw new RuntimeException('Unable to set environment variable: ' . $key);
            }
            $_ENV[$key] = $value;
            $this->loaded[$key] = $value;
        }
    }

    /** @return array<string, string> */
    public function loaded(): array
    {
        return $this->loaded;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? null;
        }
        if ($value === null) {
            return $default;
        }
        if (!is_string($value)) {
            return $value;
        }
        return match (strtolower(trim($value))) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => self::coerceNumber($value),
        };
    }

    private static function coerceNumber(string $value): mixed
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1 && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }
        return $value; // Keep decimal strings, IPs, ports with leading zeroes and secrets intact.
    }

    private static function parseValue(string $source, int $line): string
    {
        if ($source === '') {
            return '';
        }
        $quote = $source[0];
        if ($quote === '"' || $quote === "'") {
            $out = '';
            $closed = false;
            $length = strlen($source);
            for ($i = 1; $i < $length; $i++) {
                $char = $source[$i];
                if ($char === '\\' && $i + 1 < $length) {
                    $next = $source[++$i];
                    if ($quote === '"') {
                        $out .= match ($next) {
                            'n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\',
                            default => '\\' . $next,
                        };
                    } else {
                        $out .= ($next === "'" || $next === '\\') ? $next : '\\' . $next;
                    }
                    continue;
                }
                if ($char === $quote) {
                    $tail = trim(substr($source, $i + 1));
                    if ($tail !== '' && !str_starts_with($tail, '#')) {
                        throw new RuntimeException(sprintf('Invalid trailing .env data at line %d.', $line));
                    }
                    $closed = true;
                    break;
                }
                $out .= $char;
            }
            if (!$closed) {
                throw new RuntimeException(sprintf('Unclosed .env quote at line %d.', $line));
            }
            return $out;
        }
        // Inline comments start after whitespace; # within a token remains literal.
        $source = preg_replace('/\s+#.*$/', '', $source) ?? $source;
        return rtrim($source);
    }
}
