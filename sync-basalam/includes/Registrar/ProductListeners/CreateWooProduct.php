<?php

namespace SyncBasalam\Registrar\ProductListeners;

use SyncBasalam\JobManager;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class CreateWooProduct extends ProductListenerAbstract
{
    private $jobManager;

    public function __construct($jobManager = null)
    {
        $this->jobManager = $jobManager ?: syncBasalamContainer()->get(JobManager::class);
    }

    public function handle($productId)
    {
        if (!$this->isAvailableProduct($productId)) return;

        if (!$this->jobManager->hasProductJobInProgress($productId, 'sync_basalam_create_single_product')) {
            $this->jobManager->createJob(
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
        $syncBasalamProductId = get_post_meta($productId, ProductMetaKey::basalamProductId(), true);

        if (!$product || $product->is_type('variation') || $postType !== 'product' || !$syncStatus || $syncBasalamProductId || $postStatus !== 'publish') return false;

        return true;
    }
}
