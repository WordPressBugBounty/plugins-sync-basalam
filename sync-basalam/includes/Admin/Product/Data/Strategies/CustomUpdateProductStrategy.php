<?php

namespace SyncBasalam\Admin\Product\Data\Strategies;

use SyncBasalam\Admin\Product\Data\Handlers\ProductDataHandlerInterface;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class CustomUpdateProductStrategy implements DataStrategyInterface
{
    public function collect($product, ProductDataHandlerInterface $handler): array
    {
        $data = apply_filters('sync_basalam_product_payload', null, $product, $handler, 'custom_update');
        if (!is_array($data)) {
            $data = [];
        }

        $basalamProductId = get_post_meta($product->get_id(), ProductMetaKey::basalamProductId(), true);

        if (!array_key_exists('id', $data)) {
            $data['id'] = $basalamProductId;
        }

        if (!array_key_exists('name', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_NAME)) {
            $data['name'] = $handler->getName($product);
        }

        if (!array_key_exists('photo', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_PHOTOS)) {
            $data['photo'] = $handler->getMainPhoto($product);
        }
        if (!array_key_exists('photos', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_PHOTOS)) {
            $data['photos'] = $handler->getGalleryPhotos($product);
        }

        if (!array_key_exists('primary_price', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_PRICE)) {
            if (!$product->is_type('variable')) {
                $data['primary_price'] = $handler->getPrice($product);
            }
        }
        if (!array_key_exists('variants', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_PRICE)) {
            $data['variants'] = $handler->getVariants($product);
        }

        if (!array_key_exists('stock', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_STOCK)) {
            if (!$product->is_type('variable')) {
                $data['stock'] = $handler->getStock($product);
            }
        }
        if (!array_key_exists('variants', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_STOCK)) {
            $data['variants'] = $handler->getVariants($product);
        }

        if (!array_key_exists('weight', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_WEIGHT)) {
            $data['weight'] = $handler->getWeight($product);
        }
        if (!array_key_exists('package_weight', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_WEIGHT)) {
            $data['package_weight'] = $handler->getPackageWeight($product);
        }

        if (!array_key_exists('description', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_DESCRIPTION)) {
            $data['description'] = $handler->getDescription($product);
        }

        if (!array_key_exists('product_attribute', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_ATTR)) {
            $data['product_attribute'] = $handler->getAttributes($product);
        }

        if (!array_key_exists('variants', $data)) {
            $data['variants'] = [];
        }

        return array_filter($data, fn($value) => $value !== null);
    }

    private function shouldSyncField(string $fieldKey): bool
    {
        $setting = syncBasalamSettings()->getSettings($fieldKey);
        return $setting == true || $setting === '1' || $setting === 1;
    }
}
