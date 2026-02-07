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
            'primary_price' => $handler->getPrice($product),
            'stock' => $handler->getStock($product),
            'weight' => $handler->getWeight($product),
            'package_weight' => $handler->getPackageWeight($product),
            'photo' => $handler->getMainPhoto($product),
            'photos' => $handler->getGalleryPhotos($product),
            'status' => 2976,
            'preparation_days' => $handler->getPreparationDays($product),
            'unit_type' => $handler->getUnitType($product),
            'unit_quantity' => $handler->getUnitQuantity($product),
            'is_wholesale' => $handler->isWholesale($product),
            'variants' => $handler->getVariants($product),
        ];

        return array_filter($data, fn($value) => $value !== null);
    }
}