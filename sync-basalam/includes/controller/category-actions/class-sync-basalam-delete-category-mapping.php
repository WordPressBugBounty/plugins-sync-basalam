<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_Delete_Category_Mapping extends Sync_basalamController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $mapping_id = intval($_POST['mapping_id']);

        if (!$mapping_id) {
            wp_send_json_error('Invalid mapping ID');
            return;
        }

        try {
            $result = Sync_Basalam_Category_Mapping::delete_category_mapping($mapping_id);

            if ($result) {
                wp_send_json_success('Mapping deleted successfully');
            } else {
                wp_send_json_error('Error deleting mapping');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}