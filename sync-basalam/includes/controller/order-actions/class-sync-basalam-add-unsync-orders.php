<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Add_Unsync_Orders extends Sync_BasalamController
{
    public function __invoke()
    {
        $get_unsync_orders_service = new sync_basalam_Unsync_Orders_Detection();
        $result = $get_unsync_orders_service->add_unsync_basalam_order_to_woo();

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}