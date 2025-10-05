<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_Get_Basalam_Categories extends Sync_basalamController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        try {
            $categories = Sync_Basalam_Category_Mapping::get_basalam_categories();
            wp_send_json_success($categories);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}