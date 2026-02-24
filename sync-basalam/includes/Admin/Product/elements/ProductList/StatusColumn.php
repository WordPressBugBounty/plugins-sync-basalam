<?php

namespace SyncBasalam\Admin\Product\elements\ProductList;

use SyncBasalam\Admin\Components\ProductListComponents;

defined('ABSPATH') || exit;
class StatusColumn
{
    public static function registerStatusColumn($columns)
    {
        $newColumns = [];
        foreach ($columns as $key => $value) {
            $newColumns[$key] = $value;
            if ($key === 'price') {
                $newColumns['sync_basalam_status'] = 'وضعیت محصول (باسلام)';
            }
        }

        return $newColumns;
    }

    public static function renderStatusColumnContent($column, $productId)
    {
        if ($column === 'sync_basalam_status') {
            $product = get_post_meta($productId, 'sync_basalam_product_sync_status', true);
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
