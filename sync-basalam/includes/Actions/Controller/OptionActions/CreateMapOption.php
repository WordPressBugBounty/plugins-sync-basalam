<?php

namespace SyncBasalam\Actions\Controller\OptionActions;

use SyncBasalam\Admin\Product\Category\CategoryOptions;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class CreateMapOption extends ActionController
{
    public function __invoke()
    {
        global $wpdb;

        $wooMapOption = isset($_POST['woo-option-name']) ? sanitize_text_field(wp_unslash($_POST['woo-option-name'])) : null;

        $syncBasalamMapOption = isset($_POST['basalam-option-name']) ? sanitize_text_field(wp_unslash($_POST['basalam-option-name'])) : null;

        $categoryOptionsManager = new CategoryOptions($wpdb);

        $result = $categoryOptionsManager->add($wooMapOption, $syncBasalamMapOption);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
