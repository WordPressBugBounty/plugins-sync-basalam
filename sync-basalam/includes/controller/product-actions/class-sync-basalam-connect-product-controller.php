<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_connect_product extends Sync_BasalamController
{
    public function __invoke()
    {
        $result = sync_basalam_handle_connect_product_ajax();
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 200);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
