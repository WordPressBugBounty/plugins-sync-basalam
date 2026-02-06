<?php

namespace SyncBasalam\Admin\Product\Data\Strategies;

use SyncBasalam\Admin\Product\Data\Handlers\ProductDataHandlerInterface;
use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class CustomUpdateProductStrategy implements DataStrategyInterface
{
    public function collect($product, ProductDataHandlerInterface $handler): array
    {
        $basalamProductId = get_post_meta($product->get_id(), 'sync_basalam_product_id', true);

        $data = [
            'id' => $basalamProductId,
            'status' => 2976,
        ];

        if ($this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_NAME)) {
            $data['name'] = $handler->getName($product);
        }

        if ($this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_PHOTOS)) {
            $data['photo'] = $handler->getMainPhoto($product);
            $data['photos'] = $handler->getGalleryPhotos($product);
        }

        if ($this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_PRICE)) {
            $data['primary_price'] = $handler->getPrice($product);
            $data['variants'] = $handler->getVariants($product);
        }

        if ($this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_STOCK)) {
            if (!$product->is_type('variable')) $data['stock'] = $handler->getStock($product);
            if (!isset($data['variants'])) $data['variants'] = $handler->getVariants($product);
        }

        if ($this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_WEIGHT)) {
            $data['weight'] = $handler->getWeight($product);
            $data['package_weight'] = $handler->getPackageWeight($product);
        }

        if ($this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_DESCRIPTION)) {
            $data['description'] = $handler->getDescription($product);
        }

        if ($this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_ATTR)) {
            $data['product_attribute'] = $handler->getAttributes($product);
        }

        if (!isset($data['variants'])) $data['variants'] = [];

        return $data;
    }

    private function shouldSyncField(string $fieldKey): bool
    {
        $setting = syncBasalamSettings()->getSettings($fieldKey);
        return $setting == true || $setting === '1' || $setting === 1;
    }
}
