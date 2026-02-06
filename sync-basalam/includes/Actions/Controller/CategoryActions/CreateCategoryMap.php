<?php

namespace SyncBasalam\Actions\Controller\CategoryActions;

use SyncBasalam\Admin\Product\Category\CategoryMapping;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;
class CreateCategoryMap extends ActionController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $wooCategoryId = intval($_POST['woo_category_id']);
        $wooCategoryName = sanitize_text_field($_POST['woo_category_name']);
        $basalamCategoryIds = isset($_POST['basalam_category_ids']) ? $_POST['basalam_category_ids'] : null;
        $basalamCategoryName = sanitize_text_field($_POST['basalam_category_name']);

        if (!$wooCategoryId || !$basalamCategoryIds || !is_array($basalamCategoryIds)) {
            wp_send_json_error('Invalid category data');

            return;
        }

        $basalamCategoryIds = array_map('intval', $basalamCategoryIds);

        try {
            $result = CategoryMapping::createCategoryMapping(
                $wooCategoryId,
                $wooCategoryName,
                $basalamCategoryIds,
                $basalamCategoryName
            );

            if ($result) {
                wp_send_json_success('Mapping created successfully');
            } else {
                wp_send_json_error('Error creating mapping');
            }
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
