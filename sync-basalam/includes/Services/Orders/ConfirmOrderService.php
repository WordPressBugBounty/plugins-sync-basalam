<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Utilities\OrderManagerUtilities;

class ConfirmOrderService
{
    public function confirmOrderOnBasalam(int $orderId)
    {
        global $wpdb;

        if (!$orderId) {
            return [
                'success'     => false,
                'message'     => 'شناسه سفارش نامعتبر است.',
                'status_code' => 400,
            ];
        }


        $syncBasalamOrderId = OrderManagerUtilities::getInvoiceId($wpdb, $orderId);
        if (!$syncBasalamOrderId) {
            return [
                'success'     => false,
                'message'     => 'شناسه فاکتور سفارش یافت نشد.',
                'status_code' => 400,
            ];
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return [
                'success'     => false,
                'message'     => 'سفارش یافت نشد.',
                'status_code' => 400,
            ];
        }

        $response = $this->sendConfirmOrderRequest($syncBasalamOrderId);

        $statusCode = $response['status_code'];
        if ($statusCode !== 200 && $statusCode !== 201) {

            $body = $response['body'];
            $errorMessage = '';

            if (is_array($body)) {
                if (!empty($body['errors']) && is_array($body['errors'])) {
                    $errorMessage = $body['errors'][0]['message'] ?? '';
                }
            } else {
                $errorMessage = $body;
            }

            return [
                'success'     => false,
                'message'     => $errorMessage,
                'status_code' => $statusCode,
            ];
        }

        $order->update_status('bslm-preparation', 'سفارش توسط ادمین تایید شد.');
        $order->add_order_note('درخواست تایید سفارش با موفقیت به سرور باسلام ارسال شد.');

        return [
            'success'     => true,
            'message'     => 'سفارش با موفقیت در باسلام تایید شد.',
            'status_code' => 200,
        ];
    }

    private function sendConfirmOrderRequest($syncBasalamOrderId)
    {
        $apiUrl = Endpoints::ORDER_CONFIRM;
        $body = [
            'order_id' => $syncBasalamOrderId,
        ];

        $apiService = syncBasalamContainer()->get(ApiServiceManager::class);

        try {
            return $apiService->post($apiUrl, $body);
        } catch (\Exception $e) {
            return [
                'status_code' => 500,
                'body' => 'خطا در ارسال درخواست تایید سفارش: ' . $e->getMessage(),
            ];
        }
    }
}
