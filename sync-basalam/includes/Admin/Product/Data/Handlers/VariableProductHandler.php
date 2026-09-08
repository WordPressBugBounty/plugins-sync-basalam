<?php

namespace SyncBasalam\Admin\Product\Data\Handlers;

use SyncBasalam\Admin\Product\Data\Services\VariantService;

defined('ABSPATH') || exit;

class VariableProductHandler extends SimpleProductHandler
{
    private $variantService;

    public function __construct()
    {
        parent::__construct();
        $this->variantService = new VariantService();
    }

    public function getVariants($product): array
    {
        return $this->variantService->getVariants($product);
    }

    public function getSku($product): ?string
    {
        $sku = trim((string) $product->get_sku());
        if ($sku !== '') return $sku;

        // Variable products keep their SKUs at variation level (the parent SKU field
        // is usually empty in WooCommerce), so resolve the product-level SKU from
        // the first variation that has one.
        foreach ($product->get_children() as $variationId) {
            $variation = \wc_get_product($variationId);
            if (!$variation) continue;

            $variationSku = trim((string) $variation->get_sku());
            if ($variationSku !== '') return $variationSku;
        }

        return null;
    }
}