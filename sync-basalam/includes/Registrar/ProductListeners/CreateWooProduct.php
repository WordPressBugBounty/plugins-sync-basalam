<?php

namespace SyncBasalam\Registrar\ProductListeners;

use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\JobManager;

defined('ABSPATH') || exit;

class CreateWooProduct extends ProductListenerAbstract
{
    public function handle($productId)
    {

        if (!$this->isAvailableProduct($productId)) {
            return;
        }

        $operationType = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_OPERATION_TYPE);
        $jobManager = JobManager::getInstance();

        if (!$jobManager->hasProductJobInProgress($productId, 'sync_basalam_create_single_product')) {

            if ($operationType === 'immediate') {
                $this->executeImmediateCreate($productId);
            } else {
                $jobManager->createJob(
                    'sync_basalam_create_single_product',
                    'pending',
                    json_encode(['product_id' => $productId]),
                );
            }
        }
    }

    private function executeImmediateCreate($productId)
    {

        update_post_meta($productId, 'sync_basalam_product_sync_status', 'pending');

        $productOperations = new ProductOperations();
        $result = $productOperations->createNewProduct($productId, []);

        if ($result['success']) {
            update_post_meta($productId, 'sync_basalam_product_sync_status', 'synced');
        } else {
            update_post_meta($productId, 'sync_basalam_product_sync_status', 'no');
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
