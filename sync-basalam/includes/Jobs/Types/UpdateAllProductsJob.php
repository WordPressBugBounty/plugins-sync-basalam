<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Admin\ProductService;

defined('ABSPATH') || exit;

class UpdateAllProductsJob extends AbstractJobType
{
    public function getType(): string
    {
        return 'sync_basalam_update_all_products';
    }

    public function getPriority(): int
    {
        return 2;
    }

    public function canRun(): bool
    {
        return $this->areAllSingleJobsCompleted('sync_basalam_update_single_product');
    }

    public function execute(array $payload): void
    {
        $lastId = $payload['last_updatable_product_id'] ?? 0;

        $batchData = [
            'posts_per_page' => 100,
            'last_updatable_product_id' => $lastId,
        ];

        $productIds = ProductService::getUpdatableProducts($batchData);

        if (!$productIds) return;

        foreach ($productIds as $productId) {
            if (!$this->hasProductJobInProgress($productId, 'sync_basalam_update_single_product')) {
                $this->jobManager->createJob(
                    'sync_basalam_update_single_product',
                    'pending',
                    json_encode(['product_id' => $productId])
                );
            }
        }

        $newLastId = max($productIds);

        $this->jobManager->createJob(
            'sync_basalam_update_all_products',
            'pending',
            json_encode(['last_updatable_product_id' => $newLastId])
        );
    }
}
