<?php

namespace SyncBasalam\Registrar\ProductListeners;

use SyncBasalam\JobManager;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class CreateWooProduct extends ProductListenerAbstract
{
    public function handle($productId)
    {
        if (!$this->isAvailableProduct($productId)) return;

        $jobManager = JobManager::getInstance();

        if (!$jobManager->hasProductJobInProgress($productId, 'sync_basalam_create_single_product')) {
            $jobManager->createJob(
                'sync_basalam_create_single_product',
                'pending',
                json_encode(['product_id' => $productId]),
            );
        }
    }

    private function isAvailableProduct($productId)
    {
        $product = wc_get_product($productId);
        $postType = get_post_type($productId);
        $postStatus = get_post_status($productId);
        $syncStatus = $this->isProductSyncEnabled();
        $syncBasalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);

        if (!$product || $product->is_type('variation') || $postType !== 'product' || !$syncStatus || $syncBasalamProductId || $postStatus !== 'publish') return false;

        return true;
    }
}
