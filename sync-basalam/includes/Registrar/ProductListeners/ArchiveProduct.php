<?php

namespace SyncBasalam\Registrar\ProductListeners;

use SyncBasalam\Admin\Product\ProductOperations;

defined('ABSPATH') || exit;

class ArchiveProduct extends ProductListenerAbstract
{
    private $productOperations;

    public function __construct($productOperations = null)
    {
        $this->productOperations = $productOperations ?: syncBasalamContainer()->get(ProductOperations::class);
    }

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

        $this->productOperations->archiveExistProduct($productId);
    }
}
