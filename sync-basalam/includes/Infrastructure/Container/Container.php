<?php

namespace SyncBasalam\Infrastructure\Container;

use RuntimeException;

defined('ABSPATH') || exit;

class Container implements ContainerInterface
{
    /** @var array<string, array{factory: callable, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $aliases = [];

    public function bind(string $id, callable $factory): void
    {
        $this->bindings[$id] = [
            'factory' => $factory,
            'shared' => false,
        ];
    }

    public function singleton(string $id, callable $factory): void
    {
        $this->bindings[$id] = [
            'factory' => $factory,
            'shared' => true,
        ];
    }

    public function alias(string $alias, string $id): void
    {
        $this->aliases[$alias] = $id;
    }

    public function has(string $id): bool
    {
        $resolvedId = $this->resolveAlias($id);

        return isset($this->instances[$resolvedId])
            || isset($this->bindings[$resolvedId])
            || class_exists($resolvedId);
    }

    public function get(string $id)
    {
        $resolvedId = $this->resolveAlias($id);

        if (array_key_exists($resolvedId, $this->instances)) {
            return $this->instances[$resolvedId];
        }

        if (isset($this->bindings[$resolvedId])) {
            $entry = $this->bindings[$resolvedId];
            $instance = $entry['factory']($this);

            if ($entry['shared']) {
                $this->instances[$resolvedId] = $instance;
            }

            return $instance;
        }

        if (class_exists($resolvedId)) {
            $instance = new $resolvedId();
            return $instance;
        }

        throw new RuntimeException(esc_html(sprintf('Service "%s" is not bound in container.', $id)));
    }

    public function provider(ServiceProviderInterface $provider): void
    {
        $provider->register($this);
    }

    private function resolveAlias(string $id): string
    {
        return $this->aliases[$id] ?? $id;
    }
}
