<?php

namespace SyncBasalam\Registrar\ProductListeners;

use SyncBasalam\Admin\Product\ProductOperations;

defined('ABSPATH') || exit;

class RestoreProduct extends ProductListenerAbstract
{
    public function handle($productId)
    {
        $product = wc_get_product($productId);

        if (!$product || $product->is_type('variation')) {
            return;
        }

        $syncStatus = $this->isProductSyncEnabled();

        if (!$syncStatus || !wc_get_product($productId)) {
            return;
        }

        $productOperations = new ProductOperations();
        $productOperations->restoreExistProduct($productId);
    }
}
