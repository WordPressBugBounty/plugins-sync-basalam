<?php

namespace SyncBasalam\Admin\Product\elements\ProductList;

use SyncBasalam\Admin\ProductService;
use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;

class Actions
{
    public function registerBulkActions($actions)
    {
        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);

        $actions['sync_basalam_bulk_edit'] = 'ویرایش ووسلام';
        if ($vendorSyncPolicy->canCreate()) {
            $actions['add_to_basalam'] = 'افزودن به باسلام';
        }
        if ($vendorSyncPolicy->canUpdate(false)) {
            $actions['update_on_basalam'] = $vendorSyncPolicy->shouldRestrictUpdateFields(false)
                ? 'بروزرسانی قیمت و موجودی در باسلام'
                : 'آپدیت در باسلام';
        }
        $actions['disconnect_basalam_product'] = 'قطع اتصال محصول باسلام';

        return $actions;
    }

    public function handleBulkAction($redirectTo, $doaction, $postIds)
    {
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'bulk-posts')) {
            wp_die('درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        }

        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);

        if ($doaction === 'add_to_basalam' && $vendorSyncPolicy->canCreate()) {
            ProductService::enqueueSelectedProductsForCreation($postIds);
            $redirectTo = add_query_arg('sync_basalam_added');
        }

        if ($doaction === 'update_on_basalam' && $vendorSyncPolicy->canUpdate(false)) {
            ProductService::enqueueSelectedProductsForUpdate($postIds);
            $redirectTo = add_query_arg('sync_basalam_updated');
        }

        if ($doaction === 'disconnect_basalam_product') {
            ProductService::disconnectSelectedProducts($postIds);
            $redirectTo = add_query_arg('sync_basalam_disconnected_products');
        }

        return $redirectTo;
    }
}
