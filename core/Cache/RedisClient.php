<?php
declare(strict_types=1);

namespace Cajeer\Servers\Cache;

use RuntimeException;

/** Binary-safe RESP2 client. No PHP redis extension required. No automatic write retries. */
class RedisClient
{
    private $socket = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly int $database = 0,
        private readonly float $timeout = 3.0,
        private readonly bool $tls = false,
        private readonly ?string $caFile = null,
    ) {
        if ($port < 1 || $port > 65535 || $database < 0 || $database > 255 || $timeout <= 0 || $timeout > 30) {
            throw new RuntimeException('Invalid Redis port, database or timeout.');
        }
        if ($tls && $caFile !== null && (!is_file($caFile) || !is_readable($caFile))) {
            throw new RuntimeException('Redis TLS CA file cannot be read.');
        }
    }

    public function command(string ...$args): mixed
    {
        if ($args === [] || count($args) > 128) throw new RuntimeException('Invalid Redis command.');
        foreach ($args as $arg) if (strlen($arg) > 16 * 1024 * 1024) throw new RuntimeException('Redis argument exceeds 16 MiB.');
        $connection = $this->connect();
        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        $remaining = strlen($payload);
        $offset = 0;
        while ($remaining > 0) {
            $written = @fwrite($connection, substr($payload, $offset, min($remaining, 65536)));
            if ($written === false || $written === 0) { $this->close(); throw new RuntimeException('Redis connection write failed.'); }
            $offset += $written; $remaining -= $written;
        }
        try { return $this->reply($connection); }
        catch (RuntimeException $e) { $this->close(); throw $e; }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) @fclose($this->socket);
        $this->socket = null;
    }

    public function __destruct() { $this->close(); }

    private function connect()
    {
        if (is_resource($this->socket)) return $this->socket;
        if ($this->host === '' || !preg_match('/^[A-Za-z0-9.:-]+$/D', $this->host)) throw new RuntimeException('Invalid Redis host.');
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true,
            'cafile' => $this->caFile,
        ]]);
        $scheme = $this->tls ? 'tls' : 'tcp';
        $hostname = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
        $socket = @stream_socket_client("$scheme://{$hostname}:{$this->port}", $code, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) throw new RuntimeException('Redis connection failed.');
        stream_set_timeout($socket, (int) $this->timeout, (int) (($this->timeout - floor($this->timeout)) * 1000000));
        $this->socket = $socket;
        try {
            if ($this->password !== '') {
                if ($this->username === '') $this->command('AUTH', $this->password);
                else $this->command('AUTH', $this->username, $this->password);
            }
            if ($this->database > 0) $this->command('SELECT', (string) $this->database);
        } catch (\Throwable $e) {
            $this->close();
            throw new RuntimeException('Redis authentication or database selection failed.', 0, $e);
        }
        return $socket;
    }

    private function reply($socket, int $depth = 0): mixed
    {
        if ($depth > 8) throw new RuntimeException('Redis protocol nesting limit exceeded.');
        $line = $this->line($socket);
        $prefix = $line[0];
        $value = substr($line, 1);
        return match ($prefix) {
            '+' => $value,
            '-' => throw new RuntimeException('Redis command rejected: ' . substr($value, 0, 256)),
            ':' => $this->integer($value),
            '$' => $this->bulk($socket, $this->integer($value)),
            '*' => $this->arrayReply($socket, $this->integer($value), $depth),
            default => throw new RuntimeException('Invalid Redis protocol response.'),
        };
    }

    private function integer(string $v): int
    {
        if (!preg_match('/^-?[0-9]{1,18}$/D', $v)) throw new RuntimeException('Invalid Redis integer.');
        return (int) $v;
    }

    private function arrayReply($socket, int $count, int $depth): ?array
    {
        if ($count === -1) return null;
        if ($count < 0 || $count > 10000) throw new RuntimeException('Invalid Redis array size.');
        $rows = [];
        for ($i = 0; $i < $count; $i++) $rows[] = $this->reply($socket, $depth + 1);
        return $rows;
    }

    private function bulk($socket, int $length): ?string
    {
        if ($length === -1) return null;
        if ($length < 0 || $length > 16 * 1024 * 1024) throw new RuntimeException('Redis reply exceeds maximum size.');
        $data = '';
        while (strlen($data) < $length + 2) {
            $chunk = @fread($socket, min($length + 2 - strlen($data), 65536));
            if (!is_string($chunk) || $chunk === '') throw new RuntimeException('Redis bulk read failed.');
            $data .= $chunk;
        }
        if (substr($data, -2) !== "\r\n") throw new RuntimeException('Malformed Redis bulk terminator.');
        return substr($data, 0, -2);
    }

    private function line($socket): string
    {
        $line = @fgets($socket, 65538);
        if (!is_string($line) || strlen($line) < 3 || !str_ends_with($line, "\r\n")) throw new RuntimeException('Redis response read failed.');
        return substr($line, 0, -2);
    }
}
