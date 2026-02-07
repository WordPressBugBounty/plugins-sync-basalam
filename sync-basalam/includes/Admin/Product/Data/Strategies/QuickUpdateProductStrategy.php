<?php

namespace SyncBasalam\Admin\Product\Data\Strategies;

use SyncBasalam\Admin\Product\Data\Handlers\ProductDataHandlerInterface;

defined('ABSPATH') || exit;

class QuickUpdateProductStrategy implements DataStrategyInterface
{
    public function collect($product, ProductDataHandlerInterface $handler): array
    {
        $variants = $handler->getVariants($product);
        $data = [
            'id' => get_post_meta($product->get_id(), 'sync_basalam_product_id', true),
            'status' => 2976,
            'primary_price' => $handler->getPrice($product),
            'stock' => $handler->getStock($product),
            'variants' => $variants,
        ];

        return array_filter($data, fn($value) => $value !== null);
    }
}