<?php
namespace Cajeer\Servers\Http;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void { $this->routes['GET '.$path] = $handler; }
    public function post(string $path, callable $handler): void { $this->routes['POST '.$path] = $handler; }
    public function routes(): array { return $this->routes; }
}
