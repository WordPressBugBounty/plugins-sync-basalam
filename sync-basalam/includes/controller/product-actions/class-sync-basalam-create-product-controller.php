<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Create_Product extends Sync_BasalamController
{
    public function __invoke()
    {
        $product_operations = new sync_basalam_Admin_Product_Operations();
        $product_id = isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : null;
        $cat_id = isset($_POST['cat_id']) ? ($_POST['cat_id']) : null;

        $cat_id = explode(',', $cat_id);

        if ($product_id) {
            $result = $product_operations->create_new_product($product_id, $cat_id);
        }
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 200);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
