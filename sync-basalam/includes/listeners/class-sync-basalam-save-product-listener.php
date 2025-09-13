<?php
/**
 * Save Product Listener for Sync Basalam Plugin
 *
 * Handles product save events (create/update) for Basalam synchronization.
 * This unified listener manages both new product creation and existing product
 * updates, preventing duplicate processing through transient-based debouncing.
 *
 * @package     Sync_Basalam
 * @subpackage  Listeners
 * @since       1.0.0
 */

// Exit if accessed directly
if (! defined('ABSPATH')) exit;

class Sync_basalam_Save_Product_Listener extends sync_basalam_Listener implements sync_basalam_Listener_Interface
{
    use sync_basalam_Check_Product_Sync_Status;

    /**
     * Handle the product save event
     * 
     * Processes product creation or update with duplicate prevention
     * using transients to avoid multiple triggers within short timeframes
     * 
     * @param int $product_id The WordPress post ID of the product
     * @return void
     */
    public function handle($product_id)
    {
        // Skip post revisions
        if (wp_is_post_revision($product_id)) return;
        
        // Check if we've already processed this product recently (within 2 seconds)
        // This prevents duplicate processing from multiple hook triggers
        $transient_key = 'sync_basalam_processing_' . $product_id;
        if (get_transient($transient_key)) {
            error_log("Skipping duplicate trigger for Product ID: " . $product_id);
            return;
        }
        
        // Set transient to prevent duplicate processing for 2 seconds
        set_transient($transient_key, true, 2);
        
        // Validate product availability for sync
        if (!$this->is_avalabile_product($product_id)) {
            return;
        }

        // Check if product already exists in Basalam
        $sync_basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);

        // $has_job = get_post_meta($product_id, 'sync_basalam_product_sync_status', true) === 'pending';
        // if ($has_job) {
        //     return;
        // }

        if ($sync_basalam_product_id) {
            $this->schedule_update_task($product_id);
        } else {
            $this->schedule_create_task($product_id);
        }
    }

    /**
     * Schedule a background task to update an existing product in Basalam
     * 
     * Adds the update task to the queue with a 1 second delay
     * 
     * @param int $product_id The WordPress post ID of the product to update
     * @return void
     */
    private function schedule_update_task($product_id)
    {
        sync_basalam_Product_Queue_Manager::add_to_schedule(
            new sync_basalam_Update_Product_Task(),
            ['type' => 'update_product', 'id' => $product_id],
            1
        );
    }

    /**
     * Schedule a background task to create a new product in Basalam
     * 
     * Only schedules creation for published products that exist in WooCommerce
     * 
     * @param int $product_id The WordPress post ID of the product to create
     * @return void
     */
    private function schedule_create_task($product_id)
    {
        // Only create products that are published
        $post_status = get_post_status($product_id);
        if ($post_status !== 'publish') {
            return;
        }

        // Verify product exists
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        // Schedule the creation task with 1 second delay
        sync_basalam_Product_Queue_Manager::add_to_schedule(
            new sync_basalam_Create_Product_Task(),
            ['type' => 'create_product', 'id' => $product_id],
            1
        );
    }
    
    /**
     * Check if a product is available for synchronization
     * 
     * Validates that the product exists, is not a variation,
     * is a WooCommerce product, and sync is enabled
     * 
     * @param int $product_id The WordPress post ID to check
     * @return bool True if product can be synced, false otherwise
     */
    private function is_avalabile_product($product_id)
    {
        // Get WooCommerce product object
        $product = wc_get_product($product_id);
        
        // Get WordPress post type
        $post_type = get_post_type($product_id);
        
        // Check if synchronization is enabled globally
        $sync_status = $this->sync_basalam_Check_Product_Sync_Status();

        // Product must exist, not be a variation, be a product post type, and sync must be enabled
        if (!$product || $product->is_type('variation') || $post_type !== 'product' || !$sync_status) {
            return false;
        }
        return true;
    }
}
