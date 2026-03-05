<?php

namespace SyncBasalam\Registrar\ProductListeners;

use SyncBasalam\JobManager;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class UpdateWooProduct extends ProductListenerAbstract
{
    private $jobManager;

    public function __construct($jobManager = null)
    {
        $this->jobManager = $jobManager ?: syncBasalamContainer()->get(JobManager::class);
    }

    public function handle($productId)
    {
        if (!$this->isAvailableProduct($productId) || !$this->isProductSyncEnabled()) {
            return;
        }

        if (!$this->jobManager->hasProductJobInProgress($productId, 'sync_basalam_update_single_product')) {
            $this->jobManager->createJob(
                'sync_basalam_update_single_product',
                'pending',
                json_encode(['product_id' => $productId]),
            );
        }
    }

    private function isAvailableProduct($productId)
    {
        $product = wc_get_product($productId);
        $syncBasalamProductId = get_post_meta($productId, ProductMetaKey::basalamProductId(), true);

        if (!$product || $product->is_type('variation') || !$syncBasalamProductId) return false;

        return true;
    }
}
