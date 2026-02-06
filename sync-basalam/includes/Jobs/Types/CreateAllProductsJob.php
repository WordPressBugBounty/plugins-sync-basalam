<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Admin\ProductService;

defined('ABSPATH') || exit;

class CreateAllProductsJob extends AbstractJobType
{
    public function getType(): string
    {
        return 'sync_basalam_create_all_products';
    }

    public function getPriority(): int
    {
        return 5;
    }

    public function canRun(): bool
    {
        return $this->areAllSingleJobsCompleted('sync_basalam_create_single_product');
    }

    public function execute(array $payload): void
    {
        $lastId = $payload['last_creatable_product_id'] ?? 0;
        $postsPerPage = 100;
        $includeOutOfStock = $payload['include_out_of_stock'] ?? false;

        $batchData = [
            'posts_per_page' => $postsPerPage,
            'include_out_of_stock' => $includeOutOfStock,
            'last_creatable_product_id' => $lastId,
        ];

        $productIds = ProductService::getCreatableProducts($batchData);

        if (!$productIds) return;

        foreach ($productIds as $productId) {
            if (!$this->hasProductJobInProgress($productId, 'sync_basalam_create_single_product')) {
                $this->jobManager->createJob(
                    'sync_basalam_create_single_product',
                    'pending',
                    json_encode(['product_id' => $productId])
                );
            }
        }

        $newLastId = max($productIds);

        $this->jobManager->createJob(
            'sync_basalam_create_all_products',
            'pending',
            json_encode([
                'posts_per_page' => $postsPerPage,
                'include_out_of_stock' => $includeOutOfStock,
                'last_creatable_product_id' => $newLastId,
            ])
        );
    }
}
