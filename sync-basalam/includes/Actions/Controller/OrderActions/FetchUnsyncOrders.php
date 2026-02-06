<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\Services\Orders\FetchWeeklyUnsyncOrders;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class FetchUnsyncOrders extends ActionController
{
    public function __invoke()
    {
        $getUnsyncOrdersService = new FetchWeeklyUnsyncOrders();

        $result = $getUnsyncOrdersService->addUnsyncBasalamOrderToWoo();

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
