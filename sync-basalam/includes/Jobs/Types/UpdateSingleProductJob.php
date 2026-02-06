<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Admin\Product\ProductOperations;

defined('ABSPATH') || exit;

class UpdateSingleProductJob extends AbstractJobType
{
    public function getType(): string
    {
        return 'sync_basalam_update_single_product';
    }

    public function getPriority(): int
    {
        return 3;
    }

    public function execute(array $payload): void
    {
        $productId = $payload['product_id'] ?? $payload;

        if ($productId) {
            $productOperations = new ProductOperations();
            $productOperations->updateExistProduct($productId, null);
        }
    }
}
