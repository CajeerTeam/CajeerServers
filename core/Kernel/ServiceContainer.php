<?php
declare(strict_types=1);

namespace Cajeer\Servers\Kernel;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/** Lightweight DI container supporting instances, factories and constructor autowiring. */
final class ServiceContainer
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];
    /** @var array<string, bool> */
    private array $shared = [];
    /** @var array<string, mixed> */
    private array $instances = [];
    /** @var array<string, string> */
    private array $aliases = [];
    /** @var list<string> */
    private array $resolving = [];

    public function set(string $id, mixed $service): void
    {
        $this->instance($id, $service);
    }

    public function instance(string $id, mixed $service): void
    {
        $this->validateId($id);
        unset($this->factories[$id], $this->shared[$id], $this->aliases[$id]);
        $this->instances[$id] = $service;
    }

    public function singleton(string $id, callable $factory): void
    {
        $this->register($id, $factory, true);
    }

    public function bind(string $id, callable $factory): void
    {
        $this->register($id, $factory, false);
    }

    public function alias(string $id, string $target): void
    {
        $this->validateId($id);
        $this->validateId($target);
        if ($id === $target) {
            throw new InvalidArgumentException('Service cannot alias itself.');
        }
        unset($this->instances[$id], $this->factories[$id], $this->shared[$id]);
        $this->aliases[$id] = $target;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances)
            || isset($this->factories[$id])
            || isset($this->aliases[$id])
            || class_exists($id);
    }

    public function get(string $id): mixed
    {
        if (in_array($id, $this->resolving, true)) {
            throw new RuntimeException('Circular service dependency: ' . implode(' -> ', [...$this->resolving, $id]));
        }
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        $this->resolving[] = $id;
        try {
            if (isset($this->aliases[$id])) {
                return $this->get($this->aliases[$id]);
            }
            if (isset($this->factories[$id])) {
                $service = ($this->factories[$id])($this);
                if ($this->shared[$id]) {
                    $this->instances[$id] = $service;
                }
                return $service;
            }
            return $this->autowire($id);
        } finally {
            array_pop($this->resolving);
        }
    }

    private function register(string $id, callable $factory, bool $shared): void
    {
        $this->validateId($id);
        $this->factories[$id] = Closure::fromCallable($factory);
        $this->shared[$id] = $shared;
        unset($this->instances[$id], $this->aliases[$id]);
    }

    private function autowire(string $id): object
    {
        if (!class_exists($id)) {
            throw new RuntimeException('Service not found: ' . $id);
        }
        $reflection = new ReflectionClass($id);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException('Cannot instantiate service: ' . $id);
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }
        $arguments = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                // Registered instances/factories take precedence over an optional default.
                if ($this->has($type->getName())) {
                    $arguments[] = $this->get($type->getName());
                    continue;
                }
            }
            if ($param->isDefaultValueAvailable()) {
                $arguments[] = $param->getDefaultValue();
                continue;
            }
            throw new RuntimeException(sprintf('Unresolvable constructor parameter %s::$%s.', $id, $param->getName()));
        }
        return $reflection->newInstanceArgs($arguments);
    }

    private function validateId(string $id): void
    {
        if ($id === '') {
            throw new InvalidArgumentException('Service ID cannot be empty.');
        }
    }
}
