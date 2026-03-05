<?php

namespace SyncBasalam\Actions\Controller\OrderActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Services\Orders\TrackingCodeOrderService;

defined('ABSPATH') || exit;
class TrackingCodeOrder extends ActionController
{
    public function __invoke()
    {
        $orderId        = isset($_POST['order_id'])        ? intval($_POST['order_id'])                                : 0;
        $trackingCode   = isset($_POST['tracking_code'])   ? sanitize_text_field(wp_unslash($_POST['tracking_code'])) : '';
        $phoneNumber    = isset($_POST['phone_number'])    ? sanitize_text_field(wp_unslash($_POST['phone_number']))   : '';
        $shippingMethod = isset($_POST['shipping_method']) ? intval($_POST['shipping_method'])                         : 0;

        $orderManager = new TrackingCodeOrderService();

        $result = $orderManager->trackingCodeOnBasalam($orderId, $trackingCode, $phoneNumber, $shippingMethod);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
