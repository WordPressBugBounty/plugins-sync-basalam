<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Create_Product_Listener extends sync_basalam_Listener implements sync_basalam_Listener_Interface
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
        $post_status = get_post_status($product_id);
        $post_type   = get_post_type($product_id);
        $sync_status = $this->sync_basalam_Check_Product_Sync_Status();
        $sync_basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);

        if ($post_type !== 'product') {
            return;
        }

        if ($post_status !== 'publish' || !$product || !$sync_status || $sync_basalam_product_id) {
            return;
        }

        // $has_job = get_post_meta($product_id, 'sync_basalam_product_sync_status', true) === 'pending';
        // if ($has_job) {
        //     return;
        // }

        sync_basalam_Product_Queue_Manager::add_to_schedule(new sync_basalam_Create_Product_Task(), ['type' => 'create_product', 'id' => $product_id], 1);
    }
}
