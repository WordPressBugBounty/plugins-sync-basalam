<?php

namespace SyncBasalam\Admin\Product\Services;

use SyncBasalam\Services\Products\ProductConnection;

defined('ABSPATH') || exit;

class ProductDisconnectService
{
    public function disconnectSelected(array $productIds): void
    {
        foreach ($productIds as $productId) {
            $this->disconnectSingle((int) $productId);
        }
    }

    private function disconnectSingle(int $productId): void
    {
        ProductConnection::purge($productId);
    }
}
