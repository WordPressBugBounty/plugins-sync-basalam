<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Update_Products extends Sync_BasalamController
{
    public function __invoke()
    {
        $result = sync_basalam_Product_Queue_Manager::update_all_products_in_basalam();

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
