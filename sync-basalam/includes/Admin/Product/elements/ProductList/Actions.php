<?php

namespace SyncBasalam\Admin\Product\elements\ProductList;

use SyncBasalam\Admin\ProductService;

defined('ABSPATH') || exit;

class Actions
{
    public function registerBulkActions($actions)
    {
        $actions['add_to_basalam'] = 'افزودن به باسلام';
        $actions['update_on_basalam'] = 'آپدیت در باسلام';
        $actions['disconnect_basalam_product'] = 'قطع اتصال محصول باسلام';

        return $actions;
    }

    public function handleBulkAction($redirectTo, $doaction, $postIds)
    {
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'bulk-posts')) {
            wp_die('درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        }

        if ($doaction === 'add_to_basalam') {
            ProductService::enqueueSelectedProductsForCreation($postIds);
            $redirectTo = add_query_arg('sync_basalam_added');
        }

        if ($doaction === 'update_on_basalam') {
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
