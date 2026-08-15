<?php

namespace SyncBasalam\Admin\Product\Data\Strategies;

use SyncBasalam\Admin\Product\Data\Handlers\ProductDataHandlerInterface;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\Products\UpdateProductVariationsService;
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

        if (!array_key_exists('video', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_VIDEO)) {
            $video = $handler->getVideo($product);
            if ($video !== null) {
                $data['video'] = $video;
            }
        }

        if (!array_key_exists('primary_price', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_PRICE)) {
            if (!$product->is_type('variable')) {
                $data['primary_price'] = $handler->getPrice($product);
            }
        }

        if (!array_key_exists('stock', $data) && $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_STOCK)) {
            if (!$product->is_type('variable')) {
                $data['stock'] = $handler->getStock($product);
            }
        }

        if (!array_key_exists('variants', $data) && $product->is_type('variable')) {
            $variants = $this->collectVariants($product, $handler);
            if (!empty($variants)) $data['variants'] = $variants;
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

        return array_filter($data, fn($value) => $value !== null);
    }

    private function collectVariants($product, ProductDataHandlerInterface $handler): array
    {
        $variants = $handler->getVariants($product);
        if (empty($variants)) return [];

        // When at least one variation is not connected to Basalam yet, the product is updated with a
        // single request and the variants section must be complete (properties, price and stock) so
        // Basalam can match the variations and their ids can be stored.
        if (!UpdateProductVariationsService::allVariantsHaveBasalamId($variants)) return $variants;

        $syncPrice = $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_VARIANT_PRICE);
        $syncStock = $this->shouldSyncField(SettingsConfig::SYNC_PRODUCT_FIELD_VARIANT_STOCK);

        if (!$syncPrice && !$syncStock) return [];

        // Connected variations are patched one by one, so only the ticked fields are sent.
        return array_map(function ($variant) use ($syncPrice, $syncStock) {
            $selected = ['id' => $variant['id']];

            if ($syncPrice && array_key_exists('primary_price', $variant)) $selected['primary_price'] = $variant['primary_price'];
            if ($syncStock && array_key_exists('stock', $variant)) $selected['stock'] = $variant['stock'];

            return $selected;
        }, $variants);
    }

    private function shouldSyncField(string $fieldKey): bool
    {
        $setting = syncBasalamSettings()->getSettings($fieldKey);
        return $setting == true || $setting === '1' || $setting === 1;
    }
}
