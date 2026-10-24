<?php

namespace SyncBasalam\Admin\Product\Data;

use SyncBasalam\Admin\Product\ProductDataFactory;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Services\Products\ProductPriceStockRetry;

defined('ABSPATH') || exit;

/** Fresh required fields, without invoking media, descriptions or SKU handlers. */
final class PriceStockProductData
{
    public static function get(int $productId): array
    {
        clean_post_cache($productId);
        $product = wc_get_product($productId);
        if (!$product || $product->get_status() !== 'publish') {
            throw NonRetryableException::invalidData('محصول قابل بروزرسانی نیست.');
        }

        $handler = (new ProductDataFactory())->createHandler($product);
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $childId) clean_post_cache($childId);
            $variants = $handler->getVariants($product);
            if ($variants) return ProductPriceStockRetry::onlyPriceStock(['variants' => $variants]);
            if ($product->get_stock_status() === 'outofstock') return ['stock' => 0];
            throw NonRetryableException::invalidData('هیچ تنوع دارای قیمت معتبر برای بروزرسانی وجود ندارد.');
        }

        $price = $handler->getPrice($product);
        if ($price === null || $price <= 0) {
            throw NonRetryableException::invalidData('قیمت معتبر برای بروزرسانی محصول وجود ندارد.');
        }
        return ['primary_price' => $price, 'stock' => $handler->getStock($product)];
    }
}
