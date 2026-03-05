<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class FetchUnsyncProducts
{
    private $getProductsService;

    public function __construct()
    {
        $this->getProductsService = new FetchProductsData();
    }

    public function getUnsyncBasalamProducts($cursor = null, &$nextCursor = null)
    {
        $currentCursor = $cursor;

        while (true) {
            $productData = $this->getProductsService->getProductData(null, $currentCursor);
            $nextCursor = $productData['next_cursor'] ?? null;

            $basalamProducts = isset($productData['data']) && is_array($productData['data']) ? $productData['data'] : [];
            if (empty($basalamProducts)) return [];

            $products = [];

            foreach ($basalamProducts as $product) {
                if (!is_array($product) || empty($product['id'])) continue;

                if (!get_posts([
                    'post_type'  => 'product',
                    'meta_key'   => ProductMetaKey::basalamProductId(),
                    'meta_value' => $product['id'],
                    'fields'     => 'ids',
                ])) {
                    $products[] = $product;
                }
            }

            if (!empty($products)) return $products;

            if (empty($productData['has_more']) || $nextCursor === null) return [];

            $currentCursor = $nextCursor;
        }
    }
}
