<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Product_Status
{

    public static function add_sync_basalam_status_column($columns)
    {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'price') {
                $new_columns['basalam_status'] = 'وضعیت محصول (باسلام)';
            }
        }
        return $new_columns;
    }

    public static function add_sync_basalam_status_column_content($column, $product_id)
    {
        if ($column === 'basalam_status') {
            
            $product = get_post_meta($product_id, 'sync_basalam_product_sync_status', true);

            if ($product && $product == 'ok') {
                sync_basalam_Admin_UI::render_sync_product_status_ok();
            } elseif ($product == 'pending') {
                sync_basalam_Admin_UI::render_sync_product_status_pending();
            } else {
                sync_basalam_Admin_UI::render_sync_product_status_fail();
            }
        }
    }
}
