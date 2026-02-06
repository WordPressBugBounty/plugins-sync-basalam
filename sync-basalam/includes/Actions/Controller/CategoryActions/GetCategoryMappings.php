<?php

namespace SyncBasalam\Actions\Controller\CategoryActions;

use SyncBasalam\Admin\Product\Category\CategoryMapping;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;
class GetCategoryMappings extends ActionController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        try {
            $mappings = CategoryMapping::getCategoryMappings();
            wp_send_json_success($mappings);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
