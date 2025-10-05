<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_Create_Category_Mapping extends Sync_basalamController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $woo_category_id = intval($_POST['woo_category_id']);
        $woo_category_name = sanitize_text_field($_POST['woo_category_name']);
        $basalam_category_id = intval($_POST['basalam_category_id']);
        $basalam_category_name = sanitize_text_field($_POST['basalam_category_name']);

        if (!$woo_category_id || !$basalam_category_id) {
            wp_send_json_error('Invalid category data');
            return;
        }

        try {
            $result = Sync_Basalam_Category_Mapping::create_category_mapping(
                $woo_category_id,
                $woo_category_name,
                $basalam_category_id,
                $basalam_category_name
            );

            if ($result) {
                wp_send_json_success('Mapping created successfully');
            } else {
                wp_send_json_error('Error creating mapping');
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}