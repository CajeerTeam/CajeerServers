<?php
declare(strict_types=1);

namespace Cajeer\Servers\Config;

use InvalidArgumentException;
use RuntimeException;

/** Lazy configuration loader with dot paths, caching and path traversal protection. */
final class ConfigRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly string $configPath)
    {
        if (!is_dir($configPath)) {
            throw new InvalidArgumentException('Configuration directory does not exist.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->all();
        }
        $parts = explode('.', $key);
        $file = array_shift($parts);
        if ($file === null || preg_match('/^[A-Za-z0-9_-]+$/D', $file) !== 1) {
            throw new InvalidArgumentException('Invalid configuration key.');
        }
        $value = $this->load($file);
        if ($value === null) {
            return $default;
        }
        foreach ($parts as $part) {
            if ($part === '' || !is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function has(string $key): bool
    {
        $sentinel = new \stdClass();
        return $this->get($key, $sentinel) !== $sentinel;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $files = glob($this->configPath . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $result = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $result[$name] = $this->load($name) ?? [];
        }
        return $result;
    }

    /** @return array<string, mixed>|null */
    private function load(string $name): ?array
    {
        if (array_key_exists($name, $this->cache)) {
            return $this->cache[$name];
        }
        $path = $this->configPath . '/' . $name . '.php';
        if (!is_file($path)) {
            return null;
        }
        if (!is_readable($path)) {
            throw new RuntimeException('Configuration is unreadable: ' . $name);
        }
        $data = (static fn (string $file): mixed => require $file)($path);
        if (!is_array($data)) {
            throw new RuntimeException('Configuration must return an array: ' . $name);
        }
        return $this->cache[$name] = $data;
    }
}
