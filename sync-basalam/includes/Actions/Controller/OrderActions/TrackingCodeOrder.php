<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Orders\TrackingCodeOrderService;

defined('ABSPATH') || exit;
class TrackingCodeOrder extends ActionController
{
    public function __invoke()
    {
        $orderManager = new TrackingCodeOrderService();

        $result = $orderManager->trackingCodeOnBasalam();

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
