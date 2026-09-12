<?php
namespace Cajeer\Servers\Kernel;

final class Application
{
    public function __construct(private readonly string $basePath) {}

    public function basePath(string $path = ''): string
    {
        return rtrim($this->basePath . '/' . ltrim($path, '/'), '/');
    }

    public function name(): string
    {
        return 'CajeerServers';
    }

    public function version(): string
    {
        $file = $this->basePath('VERSION');
        return is_file($file) ? trim((string) file_get_contents($file)) : '0.0.0-dev';
    }
}
