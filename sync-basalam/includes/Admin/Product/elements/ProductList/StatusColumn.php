<?php

namespace SyncBasalam\Admin\Product\elements\ProductList;

use SyncBasalam\Admin\Components\ProductListComponents;
use SyncBasalam\Utilities\ProductMetaKey;
use SyncBasalam\Services\Products\ProductOwnership;

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
            (new ProductOwnership())->forgetForeignConnection((int) $productId, false);
            $product = get_post_meta($productId, ProductMetaKey::basalamProductSyncStatus(), true);
            $remoteId = get_post_meta($productId, ProductMetaKey::basalamProductId(), true);
            if (!$remoteId) {
                ProductListComponents::renderSyncProductStatusUnsync();
                return;
            }
            $ownership = (new ProductOwnership())->inspect($remoteId, null, false);
            if ($ownership['status'] === 'unchecked') {
                echo '<span data-basalam-check-product="' . esc_attr((int) $productId) . '" aria-live="polite">در انتظار بررسی اتصال…</span>';
                return;
            }
            if ($remoteId && $ownership['status'] !== 'owned') {
                echo '<span title="' . esc_attr($ownership['message']) . '">' . esc_html(
                    $ownership['status'] === 'unverified' ? 'بررسی اتصال موقتاً ممکن نیست' : 'نیازمند بررسی اتصال'
                ) . '</span>';
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
