<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_Get_Category_Mappings extends Sync_basalamController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        try {
            $mappings = Sync_Basalam_Category_Mapping::get_category_mappings();
            wp_send_json_success($mappings);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}