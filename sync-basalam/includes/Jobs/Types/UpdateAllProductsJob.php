<?php

namespace SyncBasalam\Jobs\Types;

use SyncBasalam\Jobs\AbstractJobType;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Admin\ProductService;
use SyncBasalam\Logger\Logger;

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

    public function execute(array $payload): JobResult
    {
        $lastId = $payload['last_updatable_product_id'] ?? 0;

        try {
            $batchData = [
                'posts_per_page' => 100,
                'last_updatable_product_id' => $lastId,
            ];

            $productIds = ProductService::getUpdatableProducts($batchData);

            if (!$productIds) {
                return $this->success(['completed' => true, 'message' => 'All products updated']);
            }

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

            return $this->success(['last_id' => $newLastId, 'count' => count($productIds)]);
        } catch (\Exception $e) {
            Logger::error("خطا در ایجاد تسک های بروزرسانی محصولات: " . $e->getMessage(), [
                'operation' => 'ایجاد تسک های بروزرسانی محصولات',
            ]);
            throw $e;
        }
    }
}
