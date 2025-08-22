<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Delete_Map_Option extends Sync_BasalamController
{
    public function __invoke()
    {
        $woo_name  = isset($_POST['woo_name']) ? sanitize_text_field(wp_unslash($_POST['woo_name'])) : null;
        $sync_basalam_name = isset($_POST['basalam_name']) ? sanitize_text_field(wp_unslash($_POST['basalam_name'])) : null;

        if (!$woo_name || !$sync_basalam_name) {
            wp_send_json_error([
                'message' => 'اطلاعات ناقص ارسال شده.'
            ], 400);
        }

        global $wpdb;
        $categoryOptionsManager = new sync_basalam_Manage_Category_Options($wpdb);
        $result = $categoryOptionsManager->delete($woo_name, $sync_basalam_name);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
