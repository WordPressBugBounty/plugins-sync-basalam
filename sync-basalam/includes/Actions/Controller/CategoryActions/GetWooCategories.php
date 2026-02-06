<?php

namespace SyncBasalam\Actions\Controller\CategoryActions;

use SyncBasalam\Admin\Product\Category\CategoryMapping;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class GetWooCategories extends ActionController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        try {
            $categories = CategoryMapping::getWoocommerceCategories();
            wp_send_json_success($categories);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
