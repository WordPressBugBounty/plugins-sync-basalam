<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Orders\ConfirmOrderService;

defined('ABSPATH') || exit;
class ConfirmOrder extends ActionController
{
    public function __invoke()
    {
        $orderManager = new ConfirmOrderService();

        $result = $orderManager->confirmOrderOnBasalam();

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
