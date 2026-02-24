<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Utilities\OrderManagerUtilities;

class DelayReqOrderService
{
    public function delayReqOnBasalam()
    {
        if (!current_user_can('manage_woocommerce')) {
            return [
                'success'     => false,
                'message'     => 'تنها مدیر کل امکان تغییر وضعیت سفارش را دارد.',
                'status_code' => 400,
            ];
        }

        $orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $description = isset($_POST['description']) ? sanitize_text_field(wp_unslash($_POST['description'])) : '';
        $postponeDays = isset($_POST['postpone_days']) ? intval($_POST['postpone_days']) : 0;

        if (empty($orderId)) {
            return [
                'success'     => false,
                'message'     => 'شناسه سفارش نامعتبر است.',
                'status_code' => 400,
            ];
        }

        if (empty($description)) {
            return [
                'success'     => false,
                'message'     => 'لطفاً توضیحات را وارد کنید.',
                'status_code' => 400,
            ];
        }

        if (empty($postponeDays)) {
            return [
                'success'     => false,
                'message'     => 'لطفاً تعداد روزهای تاخیر را وارد کنید.',
                'status_code' => 400,
            ];
        }

        global $wpdb;

        $itemIds = OrderManagerUtilities::getAllItemIdsFromMeta($wpdb, $orderId);

        if (empty($itemIds)) {
            return [
                'success'     => false,
                'message'     => 'هیچ شناسه آیتمی یافت نشد.',
                'status_code' => 400,
            ];
        }

        foreach ($itemIds as $itemId) {
            $response = $this->sendDelayRequestToBasalam($itemId, $description, $postponeDays);
        }

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

        return [
            'success'     => true,
            'message'     => 'درخواست تاخیر برای سفارش با موفقیت ارسال شد.',
            'status_code' => 200,
        ];
    }

    private function sendDelayRequestToBasalam($itemId, $description, $postponeDays)
    {
        $apiUrl = 'https://order-processing.basalam.com/v1/vendor/set-overdue-agreement-request';

        $body = [
            'item_id'       => $itemId,
            'description'   => $description,
            'postpone_days' => $postponeDays,
        ];

        $apiService = new ApiServiceManager();

        try {
            return $apiService->sendPostRequest($apiUrl, $body);
        } catch (\Exception $e) {
            return [
                'status_code' => 500,
                'body' => 'خطا در ارسال درخواست تاخیر: ' . $e->getMessage(),
            ];
        }
    }
}
