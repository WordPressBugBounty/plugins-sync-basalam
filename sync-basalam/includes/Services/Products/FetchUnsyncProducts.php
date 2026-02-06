<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class FetchUnsyncProducts
{
    private $getProductsService;
    
    public function __construct()
    {
        $this->getProductsService = new FetchProductsData();
    }

    public function getUnsyncBasalamProducts($page)
    {
        $productData = $this->getProductsService->getProductData(null, $page);

        if (empty($productData['products'])) return [];

        $products = [];

        foreach ($productData['products'] as $product) {
            if (!get_posts([
                'post_type'  => 'product',
                'meta_key'   => 'sync_basalam_product_id',
                'meta_value' => $product['id'],
                'fields'     => 'ids',
            ])) {
                $products[] = $product;
            }
        }

        if (empty($products)) return $this->getUnsyncBasalamProducts($page + 1);

        return $products;
    }
}
