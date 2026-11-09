<?php
declare(strict_types=1);

namespace Cajeer\Servers\Http;

use Cajeer\Servers\Http\Exception\HttpException;
use Cajeer\Servers\Http\Validation\JsonInput;
use Cajeer\Servers\Runtime\HttpRequest;
use Cajeer\Servers\Runtime\HttpResponse;
use LogicException;

/** Real method-aware router with safe named path parameters and per-route JSON validation. */
final class Router
{
    /** @var list<array{methods:list<string>,path:string,regex:string,handler:callable,json:array<string,mixed>|null}> */
    private array $routes = [];
    /** @var list<string> */
    private array $prefixes = [];

    /** @param list<string> $methods @param array<string,mixed>|null $jsonSchema */
    public function add(array $methods, string $path, callable $handler, ?array $jsonSchema = null): void
    {
        if (!$methods || $path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException('Route must have methods and an absolute path.');
        }
        $prefix = implode('', $this->prefixes);
        $path = $prefix . ($path === '/' && $prefix !== '' ? '' : $path);
        $methods = array_values(array_unique(array_map('strtoupper', $methods)));
        foreach ($methods as $method) {
            if (!preg_match('/^[A-Z]+$/D', $method)) {
                throw new \InvalidArgumentException('Invalid route method.');
            }
        }
        foreach ($this->routes as $route) {
            if ($route['path'] === $path && array_intersect($methods, $route['methods'])) {
                throw new LogicException('Duplicate route: ' . $path);
            }
        }
        $pattern = '';
        $used = [];
        foreach (explode('/', $path) as $index => $fragment) {
            if ($index > 0) $pattern .= '/';
            if (preg_match('/^\{([a-z][a-z0-9_]*)\}$/iD', $fragment, $match)) {
                if (isset($used[$match[1]])) throw new \InvalidArgumentException('Duplicate route parameter.');
                $used[$match[1]] = true;
                $pattern .= '(?P<' . $match[1] . '>[A-Za-z0-9_-]{1,128})';
            } elseif (str_contains($fragment, '{') || str_contains($fragment, '}')) {
                throw new \InvalidArgumentException('Malformed route parameter.');
            } else {
                $pattern .= preg_quote($fragment, '~');
            }
        }
        $this->routes[] = ['methods' => $methods, 'path' => $path, 'regex' => '~^' . $pattern . '$~Du', 'handler' => $handler, 'json' => $jsonSchema];
    }

    public function group(string $prefix, callable $register): void
    {
        if (!preg_match('~^/[a-zA-Z0-9/_-]+$~D', $prefix) || str_ends_with($prefix, '/')) {
            throw new \InvalidArgumentException('Invalid route group prefix.');
        }
        $this->prefixes[] = $prefix;
        try {
            $register($this);
        } finally {
            array_pop($this->prefixes);
        }
    }

    public function get(string $path, callable $handler): void { $this->add(['GET'], $path, $handler); }
    public function post(string $path, callable $handler, ?array $schema = null): void { $this->add(['POST'], $path, $handler, $schema); }
    public function put(string $path, callable $handler, ?array $schema = null): void { $this->add(['PUT'], $path, $handler, $schema); }
    public function patch(string $path, callable $handler, ?array $schema = null): void { $this->add(['PATCH'], $path, $handler, $schema); }
    public function delete(string $path, callable $handler): void { $this->add(['DELETE'], $path, $handler); }

    /** @return list<array{path:string,methods:list<string>}> */
    public function routes(): array
    {
        return array_map(static fn (array $route): array => ['path' => $route['path'], 'methods' => $route['methods']], $this->routes);
    }

    public function dispatch(HttpRequest $request): HttpResponse
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $methods = $route['methods'];
            if (in_array('GET', $methods, true) && !in_array('HEAD', $methods, true)) {
                $methods[] = 'HEAD';
            }
            foreach ($methods as $method) {
                if (!in_array($method, $allowed, true)) $allowed[] = $method;
            }
            if ($request->method === 'OPTIONS') continue;
            if (!in_array($request->method, $methods, true)) continue;
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $request = $request->withAttribute($key, $value);
                }
            }
            if ($route['json'] !== null) {
                $request = $request->withAttribute('json', JsonInput::parseAndValidate($request, $route['json']));
            }
            $response = ($route['handler'])($request);
            if (!$response instanceof HttpResponse) {
                throw new LogicException('Route handler must return HttpResponse.');
            }
            return $response;
        }
        if ($allowed) {
            if ($request->method === 'OPTIONS') {
                return new HttpResponse(204, '', ['Allow' => implode(', ', [...$allowed, 'OPTIONS']), 'Cache-Control' => 'no-store']);
            }
            throw new HttpException(405, 'Method Not Allowed', 'method_not_allowed', ['Allow' => implode(', ', $allowed)]);
        }
        throw new HttpException(404, 'Not Found', 'not_found');
    }
}
