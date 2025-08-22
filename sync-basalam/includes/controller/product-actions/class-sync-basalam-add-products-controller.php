<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Add_Products extends Sync_BasalamController
{
    public function __invoke()
    {
        $include_out_of_stock = isset($_POST['include_out_of_stock']) ? true : false;
        $result = sync_basalam_Product_Queue_Manager::create_all_products_in_basalam($include_out_of_stock);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
