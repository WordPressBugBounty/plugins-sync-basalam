<?php

namespace SyncBasalam\Registrar\ProductListeners;

use SyncBasalam\JobManager;

defined('ABSPATH') || exit;

class UpdateWooProduct extends ProductListenerAbstract
{
    public function handle($productId)
    {
        if (!$this->isAvailableProduct($productId) || !$this->isProductSyncEnabled()) {
            return;
        }

        $jobManager = JobManager::getInstance();
        if (!$jobManager->hasProductJobInProgress($productId, 'sync_basalam_update_single_product')) {
            $jobManager->createJob(
                'sync_basalam_update_single_product',
                'pending',
                json_encode(['product_id' => $productId]),
            );
        }
    }

    private function isAvailableProduct($productId)
    {
        $product = wc_get_product($productId);
        $syncBasalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);

        if (!$product || $product->is_type('variation') || !$syncBasalamProductId) return false;

        return true;
    }
}
