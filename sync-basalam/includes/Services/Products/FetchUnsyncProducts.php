<?php

namespace SyncBasalam\Services\Products;

defined('ABSPATH') || exit;

class FetchUnsyncProducts
{
    private $getProductsService;

    public function __construct()
    {
        $this->getProductsService = new FetchProductsData();
    }

    public function getUnsyncBasalamProducts($cursor = null, $nextCursor = null)
    {
        $productData = $this->getProductsService->getProductData(null, $cursor);
        $nextCursor = $productData['next_cursor'];

        $BasalamProducts = isset($productData['data']) && is_array($productData['data']) ? $productData['data'] : [];
        if (empty($BasalamProducts)) return [];

        $products = [];

        foreach ($BasalamProducts as $product) {
            if (!is_array($product) || empty($product['id'])) continue;

            if (!get_posts([
                'post_type'  => 'product',
                'meta_key'   => 'sync_basalam_product_id',
                'meta_value' => $product['id'],
                'fields'     => 'ids',
            ])) {
                $products[] = $product;
            }
        }

        if (empty($products) && !empty($productData['has_more']) && $nextCursor !== null) {
            return $this->getUnsyncBasalamProducts($nextCursor, $nextCursor);
        }

        return $products;
    }
}
