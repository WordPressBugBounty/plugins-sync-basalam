<?php

namespace SyncBasalam\Admin\Product\elements\ProductList;

use SyncBasalam\Admin\Components\ProductListComponents;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;
class StatusColumn
{
    public static function registerStatusColumn($columns)
    {
        $newColumns = [];
        foreach ($columns as $key => $value) {
            $newColumns[$key] = $value;
            if ($key === 'price') {
                $newColumns['sync_basalam_status'] = 'وضعیت محصول';
            }
        }

        return $newColumns;
    }

    public static function renderStatusColumnContent($column, $productId)
    {
        if ($column === 'sync_basalam_status') {
            $vendorId = syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);
            if ((!is_int($vendorId) && !is_string($vendorId))
                || !ctype_digit((string) $vendorId) || (int) $vendorId <= 0) {
                ProductListComponents::renderSyncProductStatusUnsync();
                return;
            }
            $product = get_post_meta($productId, ProductMetaKey::basalamProductSyncStatus($vendorId), true);
            $remoteId = get_post_meta($productId, ProductMetaKey::basalamProductId($vendorId), true);
            if (!$remoteId) {
                ProductListComponents::renderSyncProductStatusUnsync();
                return;
            }
            if ($product && $product == 'synced') {
                ProductListComponents::renderSyncProductStatusSynced();
            } elseif ($product == 'pending') {
                ProductListComponents::renderSyncProductStatusPending();
            } else {
                ProductListComponents::renderSyncProductStatusUnsync();
            }
        }
    }
}
