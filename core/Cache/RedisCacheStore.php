<?php
declare(strict_types=1);

namespace Cajeer\Servers\Cache;

use InvalidArgumentException;
use RuntimeException;

/** Redis-backed TTL cache with atomic increments and bounded keys. */
final class RedisCacheStore
{
    public function __construct(private readonly RedisClient $redis, private readonly string $prefix = 'cajeer:cache:') {}

    public function get(string $key): ?string
    {
        $value = $this->redis->command('GET', $this->key($key));
        return $value === null ? null : (string) $value;
    }

    public function put(string $key, string $value, int $ttlSeconds = 300): void
    {
        if ($ttlSeconds < 1 || $ttlSeconds > 31536000) throw new InvalidArgumentException('Cache TTL must be 1..31536000.');
        if (strlen($value) > 8 * 1024 * 1024) throw new InvalidArgumentException('Cache value exceeds 8 MiB.');
        if ($this->redis->command('SET', $this->key($key), $value, 'EX', (string) $ttlSeconds) !== 'OK') {
            throw new RuntimeException('Redis cache write failed.');
        }
    }

    public function delete(string $key): bool { return $this->redis->command('DEL', $this->key($key)) === 1; }
    public function has(string $key): bool { return $this->redis->command('EXISTS', $this->key($key)) === 1; }

    public function increment(string $key, int $by = 1, int $ttlSeconds = 300): int
    {
        if ($by < 1 || $by > 1000000 || $ttlSeconds < 1 || $ttlSeconds > 31536000) throw new InvalidArgumentException('Invalid increment arguments.');
        $script = "local n=redis.call('INCRBY',KEYS[1],ARGV[1]); if n==tonumber(ARGV[1]) then redis.call('EXPIRE',KEYS[1],ARGV[2]); end; return n";
        return (int) $this->redis->command('EVAL', $script, '1', $this->key($key), (string) $by, (string) $ttlSeconds);
    }

    public function ping(): bool { return $this->redis->command('PING') === 'PONG'; }

    private function key(string $key): string
    {
        if (strlen($key) === 0 || strlen($key) > 200 || !preg_match('/^[a-zA-Z0-9:_\-.]+$/D', $key)) throw new InvalidArgumentException('Invalid cache key.');
        if (!preg_match('/^[a-zA-Z0-9:_\-.]+$/D', $this->prefix)) throw new InvalidArgumentException('Invalid Redis cache namespace.');
        return $this->prefix . $key;
    }
}
