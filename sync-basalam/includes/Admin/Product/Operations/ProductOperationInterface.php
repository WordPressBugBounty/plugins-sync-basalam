<?php

namespace SyncBasalam\Admin\Product\Operations;

defined('ABSPATH') || exit;

interface ProductOperationInterface
{
    public function execute(int $product_id, array $args = []): array;

    public function validate(int $product_id): bool;
}