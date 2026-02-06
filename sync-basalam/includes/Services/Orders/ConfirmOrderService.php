<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Admin\Settings;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Utilities\OrderManagerUtilities;

class ConfirmOrderService
{
    public function confirmOrderOnBasalam()
    {
        global $wpdb;

        $orderId = isset($_POST['order_id']) ? sanitize_text_field(intval($_POST['order_id'])) : 0;
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

        if (!current_user_can('manage_woocommerce')) {
            return [
                'success'     => false,
                'message'     => 'تنها مدیر کل امکان تغییر وضعیت سفارش را دارد.',
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
        $apiUrl = 'https://order-processing.basalam.com/v1/vendor/set-preparation-order';
        $body = [
            'order_id' => $syncBasalamOrderId,
        ];

        $apiService = new ApiServiceManager();

        return $apiService->sendPostRequest($apiUrl, $body);
    }
}
