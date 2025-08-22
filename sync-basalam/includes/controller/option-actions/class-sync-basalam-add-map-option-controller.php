<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Add_Map_Option extends Sync_BasalamController
{
    public function __invoke()
    {
        global $wpdb;
        
        $woo_map_option = isset($_POST['woo-option-name']) ? sanitize_text_field(wp_unslash($_POST['woo-option-name'])) : null;
        $sync_basalam_map_option = isset($_POST['basalam-option-name']) ? sanitize_text_field(wp_unslash($_POST['basalam-option-name'])) : null;
        $categoryOptionsManager = new sync_basalam_Manage_Category_Options($wpdb);
        $result =  $categoryOptionsManager->add($woo_map_option, $sync_basalam_map_option);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }
        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
