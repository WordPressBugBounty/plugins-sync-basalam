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

        if (!$product->is_type('variable')) {
            $data['primary_price'] = $handler->getPrice($product);
            $data['stock'] = $handler->getStock($product);
        }

        return array_filter($data, fn($value) => $value !== null);
    }
}
