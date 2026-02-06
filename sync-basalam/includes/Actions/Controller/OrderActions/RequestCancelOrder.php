<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Orders\CancelReqOrderService;

defined('ABSPATH') || exit;

class RequestCancelOrder extends ActionController
{
    public function __invoke()
    {
        $orderManager = new CancelReqOrderService();

        $result = $orderManager->reqCancelOrderOnBasalam();

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
