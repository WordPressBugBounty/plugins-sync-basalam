<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Orders\DelayReqOrderService;

defined('ABSPATH') || exit;

class DelayOrder extends ActionController
{
    public function __invoke()
    {
        $orderId      = isset($_POST['order_id'])      ? intval($_POST['order_id'])                                    : 0;
        $description  = isset($_POST['description'])  ? sanitize_text_field(wp_unslash($_POST['description']))        : '';
        $postponeDays = isset($_POST['postpone_days']) ? intval($_POST['postpone_days'])                               : 0;

        $orderManager = new DelayReqOrderService();

        $result = $orderManager->delayReqOnBasalam($orderId, $description, $postponeDays);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
