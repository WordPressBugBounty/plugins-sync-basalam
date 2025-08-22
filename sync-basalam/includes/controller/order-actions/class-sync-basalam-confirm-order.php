<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Confirm_Order extends Sync_BasalamController
{
    public function __invoke()
    {
        $order_manager = new sync_basalam_Order_Manager();
        $result = $order_manager->handle_admin_confirm_order();

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
