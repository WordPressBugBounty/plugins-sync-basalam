<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Update_Product_Listener extends sync_basalam_Listener implements sync_basalam_Listener_Interface
{
    use sync_basalam_Check_Product_Sync_Status;

    public function handle($product_id)
    {

        if (!$this->is_avalabile_product($product_id)) {
            return;
        }

        $transient_key = 'sync_basalam_processing_' . $product_id;
        set_transient($transient_key, true, 2);

        // Check operation type setting
        $operation_type = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::PRODUCT_OPERATION_TYPE);
        
        if ($operation_type === 'immediate') {
            // Execute immediately via Ajax controller
            $this->execute_immediate_update($product_id);
        } else {
            // Use WP Cron (original behavior)
            sync_basalam_Product_Queue_Manager::add_to_schedule(new sync_basalam_Update_Product_Task(), ['type' => 'update_product', 'id' => $product_id], 1);
        }
    }

    private function execute_immediate_update($product_id)
    {
        // Set status to pending
        update_post_meta($product_id, 'sync_basalam_product_sync_status', 'pending');
        
        // Execute immediately
        $product_operations = new sync_basalam_Admin_Product_Operations();
        $result = $product_operations->update_exist_product($product_id, null);
        
        // Update status based on result
        if ($result['success']) {
            update_post_meta($product_id, 'sync_basalam_product_sync_status', 'ok');
        } else {
            update_post_meta($product_id, 'sync_basalam_product_sync_status', 'no');
        }
    }

    private function is_avalabile_product($product_id)
    {
        $product = wc_get_product($product_id);
        $post_type = get_post_type($product_id);
        $sync_status = $this->sync_basalam_Check_Product_Sync_Status();
        $sync_basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);
        $transient_key = 'sync_basalam_processing_' . $product_id;

        if (!$product || $product->is_type('variation') || $post_type !== 'product' || !$sync_status || !$sync_basalam_product_id || get_transient($transient_key)) {
            return false;
        }
        return true;
    }
}
