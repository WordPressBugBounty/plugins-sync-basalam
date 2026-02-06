<?php

namespace SyncBasalam\Registrar\ProductListeners;

use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\JobManager;

defined('ABSPATH') || exit;

class UpdateWooProduct extends ProductListenerAbstract
{
    public function handle($productId)
    {

        if (!$this->isAvailableProduct($productId) || !$this->isProductSyncEnabled()) {
            return;
        }

        $operationType = syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_OPERATION_TYPE);

        $jobManager = JobManager::getInstance();
        if (!$jobManager->hasProductJobInProgress($productId, 'sync_basalam_update_single_product')) {

            if ($operationType === 'immediate') {
                $this->executeImmediateUpdate($productId);
            } else {
                $jobManager->createJob(
                    'sync_basalam_update_single_product',
                    'pending',
                    json_encode(['product_id' => $productId]),
                );
            }
        }
    }

    private function executeImmediateUpdate($productId)
    {
        update_post_meta($productId, 'sync_basalam_product_sync_status', 'pending');

        $productOperations = new ProductOperations();
        $result = $productOperations->updateExistProduct($productId, null);

        if ($result['success']) {
            update_post_meta($productId, 'sync_basalam_product_sync_status', 'synced');
        } else {
            update_post_meta($productId, 'sync_basalam_product_sync_status', 'no');
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
