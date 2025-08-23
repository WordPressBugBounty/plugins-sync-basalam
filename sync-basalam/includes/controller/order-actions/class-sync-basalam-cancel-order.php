<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Cancel_Order extends Sync_BasalamController
{
    public function __invoke()
    {
        $order_manager = new Sync_Basalam_Cancel_Order_Service();
        $result = $order_manager->cancel_order_on_basalam();

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 200);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
