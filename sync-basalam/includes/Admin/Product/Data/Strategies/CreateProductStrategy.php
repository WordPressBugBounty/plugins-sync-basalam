<?php

namespace SyncBasalam\Admin\Product\Data\Strategies;

use SyncBasalam\Admin\Product\Data\Handlers\ProductDataHandlerInterface;

defined('ABSPATH') || exit;

class CreateProductStrategy implements DataStrategyInterface
{
    public function collect($product, ProductDataHandlerInterface $handler): array
    {
        $data = apply_filters('sync_basalam_product_payload', null, $product, $handler, 'create');
        if (!is_array($data)) {
            $data = [];
        }

        if (!array_key_exists('name', $data)) {
            $data['name'] = $handler->getName($product);
        }
        if (!array_key_exists('description', $data)) {
            $data['description'] = $handler->getDescription($product);
        }
        if (!array_key_exists('category_id', $data)) {
            $data['category_id'] = $handler->getCategoryId($product);
        }
        if (!array_key_exists('weight', $data)) {
            $data['weight'] = $handler->getWeight($product);
        }
        if (!array_key_exists('package_weight', $data)) {
            $data['package_weight'] = $handler->getPackageWeight($product);
        }
        if (!array_key_exists('photo', $data)) {
            $data['photo'] = $handler->getMainPhoto($product);
        }
        if (!array_key_exists('photos', $data)) {
            $data['photos'] = $handler->getGalleryPhotos($product);
        }
        if (!array_key_exists('status', $data)) {
            $data['status'] = 2976;
        }
        if (!array_key_exists('preparation_days', $data)) {
            $data['preparation_days'] = $handler->getPreparationDays($product);
        }
        if (!array_key_exists('unit_type', $data)) {
            $data['unit_type'] = $handler->getUnitType($product);
        }
        if (!array_key_exists('unit_quantity', $data)) {
            $data['unit_quantity'] = $handler->getUnitQuantity($product);
        }
        if (!array_key_exists('is_wholesale', $data)) {
            $data['is_wholesale'] = $handler->isWholesale($product);
        }
        if (!array_key_exists('variants', $data)) {
            $data['variants'] = $handler->getVariants($product);
        }
        if (!array_key_exists('product_attribute', $data)) {
            $data['product_attribute'] = $handler->getAttributes($product);
        }

        if (!$product->is_type('variable')) {
            if (!array_key_exists('primary_price', $data)) {
                $data['primary_price'] = $handler->getPrice($product);
            }
            if (!array_key_exists('stock', $data)) {
                $data['stock'] = $handler->getStock($product);
            }
        }

        return $data;
    }
}
