<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Orders\ConfirmOrderService;

defined('ABSPATH') || exit;
class ConfirmOrder extends ActionController
{
    public function __invoke()
    {
        $orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        $orderManager = new ConfirmOrderService();

        $result = $orderManager->confirmOrderOnBasalam($orderId);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
