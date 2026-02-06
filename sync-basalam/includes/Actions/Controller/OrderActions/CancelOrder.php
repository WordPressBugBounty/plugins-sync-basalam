<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Orders\CancelOrderService;

defined('ABSPATH') || exit;
class CancelOrder extends ActionController
{
    public function __invoke()
    {
        $orderManager = new CancelOrderService();

        $result = $orderManager->cancelOrderOnBasalam();

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']] ?? null, $result['status_code'] ?? 200);
    }
}
