<?php

namespace SyncBasalam\Registrar\ProductListeners;

use SyncBasalam\JobManager;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Utilities\ProductMetaKey;
use SyncBasalam\Services\VendorSyncPolicy;

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
        $product = wc_get_product($productId);
        if (!$product || $product->is_type('variation') || get_post_type($productId) !== 'product') return;

        if (!get_post_meta($productId, ProductMetaKey::basalamProductId(), true)) {
            // Editing an unconnected product is a create operation in Basalam.
            (new CreateWooProduct($this->jobManager))->handle($productId);
            return;
        }

        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);

        if (
            !$vendorSyncPolicy->canUpdate() ||
            !$this->isProductUpdateSyncEnabled() ||
            (!$vendorSyncPolicy->shouldRestrictUpdateFields(false) && !SettingsManager::isProductUpdateSelectionValid())
        ) {
            return;
        }

        if (!$this->jobManager->hasProductJobInProgress($productId, 'sync_basalam_update_single_product')) {
            $this->jobManager->createJob(
                'sync_basalam_update_single_product',
                'pending',
                json_encode(['product_id' => $productId, 'automatic' => true]),
            );
        }
    }
}
