<?php

namespace SyncBasalam\Infrastructure\Container;

defined('ABSPATH') || exit;

interface ContainerInterface
{
    public function bind(string $id, callable $factory): void;

    public function singleton(string $id, callable $factory): void;

    public function alias(string $alias, string $id): void;

    public function has(string $id): bool;

    public function get(string $id);

    public function provider(ServiceProviderInterface $provider): void;
}
