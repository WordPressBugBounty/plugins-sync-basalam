<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Orders\CancelOrderService;

defined('ABSPATH') || exit;
class CancelOrder extends ActionController
{
    public function __invoke()
    {
        $orderId     = isset($_POST['order_id'])    ? intval($_POST['order_id'])                                        : 0;
        $description = isset($_POST['description']) ? sanitize_text_field(wp_unslash($_POST['description']))           : '';
        $reasonId    = isset($_POST['reason_id'])   ? intval($_POST['reason_id'])                                      : 3481;

        $orderManager = new CancelOrderService();

        $result = $orderManager->cancelOrderOnBasalam($orderId, $description, $reasonId);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']] ?? null, $result['status_code'] ?? 200);
    }
}
