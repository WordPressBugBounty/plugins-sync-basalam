<?php

namespace SyncBasalam\Actions\Controller\CategoryActions;

use SyncBasalam\Admin\Product\Category\CategoryMapping;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class RemoveCategoryMap extends ActionController
{
    public function __invoke()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $mappingId = intval($_POST['mapping_id']);

        if (!$mappingId) {
            wp_send_json_error('Invalid mapping ID');

            return;
        }

        try {
            $result = CategoryMapping::deleteCategoryMapping($mappingId);

            if ($result) {
                wp_send_json_success('Mapping deleted successfully');
            } else {
                wp_send_json_error('Error deleting mapping');
            }
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}
