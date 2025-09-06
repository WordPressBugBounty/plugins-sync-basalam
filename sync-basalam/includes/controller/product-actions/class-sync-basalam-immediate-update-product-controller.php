<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Immediate_Update_Product extends Sync_BasalamController
{
    public function __invoke()
    {
        $product_id = isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : null;
        $product_operations = new sync_basalam_Admin_Product_Operations();

        if ($product_id) {
            $result = $product_operations->update_exist_product($product_id, null);
        }
        
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 200);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}