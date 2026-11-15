<?php
declare(strict_types=1);

namespace Cajeer\Servers\Logging;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/** JSON Lines logger: atomic appends, restrictive permissions and secret redaction. */
final class JsonLogger
{
    public function __construct(private readonly string $logDirectory, private readonly string $channel = 'application')
    {
        if (preg_match('/^[a-z0-9_-]+$/iD', $channel) !== 1) {
            throw new RuntimeException('Invalid log channel.');
        }
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true)) {
            throw new RuntimeException('Unsupported log level.');
        }
        $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $entry = [
            'time' => $timestamp->format('Y-m-d\TH:i:s.u\Z'),
            'level' => $level,
            'channel' => $this->channel,
            'message' => self::redactString($message),
            'context' => self::redact($context),
        ];
        try {
            $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        } catch (Throwable) {
            $encoded = '{"level":"error","message":"Unable to encode log entry"}' . "\n";
        }
        if (!is_dir($this->logDirectory) && !@mkdir($this->logDirectory, 0750, true) && !is_dir($this->logDirectory)) {
            throw new RuntimeException('Cannot create log directory.');
        }
        $path = rtrim($this->logDirectory, '/') . '/' . $this->channel . '-' . $timestamp->format('Y-m-d') . '.jsonl';
        $newFile = !file_exists($path);
        $previousUmask = umask(0077);
        try {
            $written = @file_put_contents($path, $encoded, FILE_APPEND | LOCK_EX);
        } finally {
            umask($previousUmask);
        }
        if ($written === false || $written !== strlen($encoded)) {
            throw new RuntimeException('Failed to write application log.');
        }
        if ($newFile) {
            @chmod($path, 0640);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public static function redact(mixed $data, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[MAX_DEPTH]';
        }
        if (is_array($data)) {
            $clean = [];
            foreach ($data as $key => $value) {
                $clean[$key] = is_string($key) && self::sensitive($key)
                    ? '[REDACTED]'
                    : self::redact($value, $depth + 1);
            }
            return $clean;
        }
        if (is_string($data)) {
            return self::redactString($data);
        }
        if (is_object($data)) {
            return '[OBJECT:' . $data::class . ']';
        }
        if (is_resource($data)) {
            return '[RESOURCE]';
        }
        return $data;
    }

    public static function sensitive(string $key): bool
    {
        return preg_match('/(?:password|passwd|secret|token|api[_-]?key|app[_-]?key|private[_-]?key|community|passphrase|recovery[_-]?code|(?:^|[_-])(?:otp|totp|csrf|pin)(?:$|[_-])|signing[_-]?key|(?:^|[_-])key(?:$|[_-])|authorization|cookie|credential|dsn|vault|bearer)/i', $key) === 1;
    }

    public static function redactString(string $value): string
    {
        // Treat private key material and credential headers as opaque, not whitespace-separated words.
        $value = preg_replace('~-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY-----|\\z)~s', '[REDACTED PRIVATE KEY]', $value) ?? '[REDACTED]';
        $value = preg_replace('~\\b(?:authorization|proxy-authorization|set-cookie|cookie)\\s*[:=][^\\r\\n]*~i', '[REDACTED HEADER]', $value) ?? '[REDACTED]';
        $value = preg_replace('~\\b(?:cs1|at1)\\.[0-9a-f-]{36}\\.[A-Za-z0-9_-]{43}\\b~i', '[REDACTED TOKEN]', $value) ?? '[REDACTED]';
        $value = preg_replace('~\\b(?:Bearer|Basic|PVEAPIToken)(?:\\s+|=)[^\\r\\n,;]+~i', '[REDACTED AUTHORIZATION]', $value) ?? '[REDACTED]';
        $value = preg_replace('~(https?://)[^\\s/@]+@~i', '$1[REDACTED]@', $value) ?? '[REDACTED]';
        // Match JSON/quoted assignments too. Redact the remainder of the line: values may contain spaces.
        $value = preg_replace('~\\b(?:password|passwd|passphrase|secret|token|api[_-]?key|app[_-]?key|vault[_-]?key|private[_-]?key|community|recovery[_-]?code|totp|csrf)\\b["\']?\\s*[:=][^\\r\\n]*~i', '[REDACTED ASSIGNMENT]', $value) ?? '[REDACTED]';
        return $value;
    }
}

