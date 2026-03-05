<?php

namespace SyncBasalam\Admin\Product\Services;

use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class ProductDisconnectService
{
    public function disconnectSelected(array $productIds): void
    {
        foreach ($productIds as $productId) {
            $this->disconnectSingle($productId);
        }
    }

    private function disconnectSingle(int $productId): void
    {
        $metaKeysToRemove = [
            ProductMetaKey::basalamProductId(),
            ProductMetaKey::basalamProductSyncStatus(),
            ProductMetaKey::basalamProductStatus(),
        ];

        foreach ($metaKeysToRemove as $metaKey) {
            delete_post_meta($productId, $metaKey);
        }

        $this->disconnectVariations($productId);
    }

    private function disconnectVariations(int $productId): void
    {
        $product = wc_get_product($productId);

        if ($product && $product->is_type('variable')) {
            $variationIds = $product->get_children();

            foreach ($variationIds as $variationId) {
                delete_post_meta($variationId, 'sync_basalam_variation_id');
            }
        }
    }
}
