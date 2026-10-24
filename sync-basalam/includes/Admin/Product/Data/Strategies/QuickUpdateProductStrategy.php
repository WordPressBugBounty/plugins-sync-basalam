<?php

namespace SyncBasalam\Admin\Product\Data\Strategies;

use SyncBasalam\Admin\Product\Data\Handlers\ProductDataHandlerInterface;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class QuickUpdateProductStrategy implements DataStrategyInterface
{
    public function collect($product, ProductDataHandlerInterface $handler): array
    {
        $data = [
            'id' => get_post_meta($product->get_id(), ProductMetaKey::basalamProductId(), true),
            'variants' => $handler->getVariants($product),
            'type' => $product->get_type(),
        ];

        $sku = $handler->getSku($product);
        if ($sku !== null) {
            $data['sku'] = $sku;
        }

        // No eligible priced variants must not leave an explicitly unavailable
        // Woo product saleable through a stale parent inventory in Basalam.
        if ($product->is_type('variable') && empty($data['variants']) && $product->get_stock_status() === 'outofstock') {
            $data['stock'] = 0;
            unset($data['variants']); // Do not replace or erase remote variations.
        }

        if (!$product->is_type('variable')) {
            $data['primary_price'] = $handler->getPrice($product);
            $data['stock'] = $handler->getStock($product);
        }

        return array_filter($data, fn($value) => $value !== null);
    }
}
