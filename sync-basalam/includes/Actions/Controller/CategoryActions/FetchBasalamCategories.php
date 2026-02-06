<?php

namespace SyncBasalam\Actions\Controller\CategoryActions;

use SyncBasalam\Admin\Product\Category\CategoryMapping;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;
class FetchBasalamCategories extends ActionController
{
    public function __invoke()
    {
        try {
            $categories = CategoryMapping::getBasalamCategories();
            wp_send_json_success($categories);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
