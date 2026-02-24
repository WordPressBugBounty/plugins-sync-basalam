<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Utilities\OrderManagerUtilities;

class CancelReqOrderService
{
    public function reqCancelOrderOnBasalam()
    {
        $orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $description = isset($_POST['description']) ? sanitize_text_field(wp_unslash($_POST['description'])) : '';

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

        global $wpdb;

        $itemIds = OrderManagerUtilities::getAllItemIdsFromMeta($wpdb, $orderId);

        if (empty($itemIds)) {
            return [
                'success'     => false,
                'message'     => 'هیچ شناسه آیتمی یافت نشد.',
                'status_code' => 400,
            ];
        }

        $syncBasalamOrderId = OrderManagerUtilities::getInvoiceId($wpdb, $orderId);

        if (!$syncBasalamOrderId) {
            return [
                'success'     => false,
                'message'     => 'شناسه فاکتور سفارش یافت نشد',
                'status_code' => 400,
            ];
        }
        $response = $this->sendCancelRequestToBasalam($itemIds, $description, $syncBasalamOrderId);

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
            'message'     => 'درخواست لغو سفارش با موفقیت ارسال شد.',
            'status_code' => 200,
        ];
    }

    private function sendCancelRequestToBasalam($itemIds, $description, $syncBasalamOrderId)
    {
        $apiUrl = "https://order-processing.basalam.com/v1/vendor/order/$syncBasalamOrderId/cancel-request";

        $body = [
            'item_ids'    => $itemIds,
            'description' => $description,
        ];

        $apiService = new ApiServiceManager();

        try {
            return $apiService->sendPostRequest($apiUrl, $body);
        } catch (\Exception $e) {
            return [
                'status_code' => 500,
                'body' => 'خطا در ارسال درخواست لغو: ' . $e->getMessage(),
            ];
        }
    }
}
