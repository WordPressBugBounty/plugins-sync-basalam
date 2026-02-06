<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Utilities\OrderManagerUtilities;

class TrackingCodeOrderService
{
    public function trackingCodeOnBasalam()
    {
        if (!current_user_can('manage_woocommerce')) {
            return [
                'success'     => false,
                'message'     => 'تنها مدیر کل امکان تغییر وضعیت سفارش را دارد.',
                'status_code' => 400,
            ];
        }

        $orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $trackingCode = isset($_POST['tracking_code']) ? sanitize_text_field(wp_unslash($_POST['tracking_code'])) : '';
        $phoneNumber = isset($_POST['phone_number']) ? sanitize_text_field(wp_unslash($_POST['phone_number'])) : '';
        $shippingMethod = isset($_POST['shipping_method']) ? intval($_POST['shipping_method']) : 0;

        if (empty($orderId)) {
            return [
                'success'     => false,
                'message'     => 'شناسه سفارش نامعتبر است.',
                'status_code' => 400,
            ];
        }

        if (empty($trackingCode)) {
            return [
                'success'     => false,
                'message'     => 'لطفاً کد رهگیری را وارد کنید.',
                'status_code' => 400,
            ];
        }

        if (empty($phoneNumber)) {
            return [
                'success'     => false,
                'message'     => 'لطفاً شماره تلفن را وارد کنید.',
                'status_code' => 400,
            ];
        }

        if (empty($shippingMethod)) {
            return [
                'success'     => false,
                'message'     => 'لطفاً روش ارسال را انتخاب کنید.',
                'status_code' => 400,
            ];
        }

        update_post_meta($orderId, '_basalam_order_tracking_code', $trackingCode);

        $response = $this->sendTrackingCodeToBasalam($orderId, $trackingCode, $phoneNumber, $shippingMethod);

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

        $order = wc_get_order($orderId);
        if (!$order) {
            return [
                'success'     => false,
                'message'     => 'سفارش یافت نشد.',
                'status_code' => 400,
            ];
        }

        $order->update_status('bslm-shipping', 'سفارش توسط ادمین ارسال شد.');

        return [
            'success'     => true,
            'message'     => 'کد رهگیری و شماره تلفن با موفقیت ثبت و ارسال شد.',
            'status_code' => 200,
        ];
    }

    private function sendTrackingCodeToBasalam($orderId, $trackingCode, $phoneNumber, $shippingMethod)
    {
        global $wpdb;

        $syncBasalamOrderId = OrderManagerUtilities::getInvoiceId($wpdb, $orderId);
        if (!$syncBasalamOrderId) {
            return new \WP_Error('no_invoice_id', 'شناسه فاکتور سفارش یافت نشد.');
        }

        $apiUrl = 'https://order-processing.basalam.com/v2/vendor/set-posted-order';
        $body = [
            'order_id'        => $syncBasalamOrderId,
            'shipping_method' => $shippingMethod,
            'tracking_code'   => $trackingCode,
            'phone_number'    => $phoneNumber,
        ];

        $apiService = new ApiServiceManager();

        return $apiService->sendPostRequest($apiUrl, $body);
    }
}
