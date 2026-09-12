<?php
namespace Cajeer\Servers\Config;

final class ConfigRepository
{
    public function __construct(private readonly string $configPath) {}

    public function get(string $name): array
    {
        $file = $this->configPath . '/' . $name . '.php';
        return is_file($file) ? require $file : [];
    }
}
