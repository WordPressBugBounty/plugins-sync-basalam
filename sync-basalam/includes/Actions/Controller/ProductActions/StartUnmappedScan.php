<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Products\UnmappedProducts\UnmappedProductsManager;

defined('ABSPATH') || exit;

class StartUnmappedScan extends ActionController
{
    public function __invoke()
    {
        $result = (new UnmappedProductsManager())->startScan();

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code']);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code']);
    }
}
