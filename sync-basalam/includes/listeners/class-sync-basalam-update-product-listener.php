<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Update_Product_Listener extends sync_basalam_Listener implements sync_basalam_Listener_Interface
{
    use sync_basalam_Check_Product_Sync_Status;

    public function handle($product_id)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        if ($product->get_type() === 'variation') {
            $parent_id = $product->get_parent_id();
            if (!$parent_id) return;
            $product_id = $parent_id;
            $product = wc_get_product($product_id);
            if (!$product) return;
        }

        $post_type = get_post_type($product_id);
        if ($post_type !== 'product') {
            return;
        }

        $sync_status = $this->sync_basalam_Check_Product_Sync_Status();
        $sync_basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);
        $last_update_time = get_post_meta($sync_basalam_product_id, 'last_update_req_sync_basalam_time', true);

        if (
            !$sync_status ||
            !$sync_basalam_product_id ||
            !wc_get_product($product_id) ||
            ($last_update_time && (time() - $last_update_time) < 10)
        ) {
            return;
        }

        $product_operations = new sync_basalam_Admin_Product_Operations();
        $product_operations->update_exist_product($product_id);

        update_post_meta($sync_basalam_product_id, 'last_update_req_sync_basalam_time', time());
    }
}
