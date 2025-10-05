<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_Get_Mapping_Stats extends Sync_basalamController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        try {
            $stats = Sync_Basalam_Category_Mapping::get_mapping_stats();
            wp_send_json_success($stats);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}