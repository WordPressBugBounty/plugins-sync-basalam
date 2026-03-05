<?php

namespace SyncBasalam\Admin\Product\Data\Strategies;

use SyncBasalam\Admin\Product\Data\Handlers\ProductDataHandlerInterface;

defined('ABSPATH') || exit;

class UpdateProductStrategy implements DataStrategyInterface
{
    public function collect($product, ProductDataHandlerInterface $handler): array
    {
        $data = [
            'name' => $handler->getName($product),
            'description' => $handler->getDescription($product),
            'weight' => $handler->getWeight($product),
            'package_weight' => $handler->getPackageWeight($product),
            'photo' => $handler->getMainPhoto($product),
            'photos' => $handler->getGalleryPhotos($product),
            'preparation_days' => $handler->getPreparationDays($product),
            'unit_type' => $handler->getUnitType($product),
            'unit_quantity' => $handler->getUnitQuantity($product),
            'is_wholesale' => $handler->isWholesale($product),
            'variants' => $handler->getVariants($product),
        ];

        if (!$product->is_type('variable')) {
            $data['primary_price'] = $handler->getPrice($product);
            $data['stock'] = $handler->getStock($product);
        }

        return array_filter($data, fn($value) => $value !== null);
    }
}