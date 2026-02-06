<?php

namespace SyncBasalam\Actions\Controller\OptionActions;

use SyncBasalam\Admin\Product\Category\CategoryOptions;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;
class RemoveMapOption extends ActionController
{
    public function __invoke()
    {
        $wooName = isset($_POST['woo_name']) ? sanitize_text_field(wp_unslash($_POST['woo_name'])) : null;

        $syncBasalamName = isset($_POST['basalam_name']) ? sanitize_text_field(wp_unslash($_POST['basalam_name'])) : null;

        if (!$wooName || !$syncBasalamName) {
            wp_send_json_error([
                'message' => 'اطلاعات ناقص ارسال شده.',
            ], 400);
        }

        global $wpdb;
        $categoryOptionsManager = new CategoryOptions($wpdb);

        $result = $categoryOptionsManager->delete($wooName, $syncBasalamName);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
