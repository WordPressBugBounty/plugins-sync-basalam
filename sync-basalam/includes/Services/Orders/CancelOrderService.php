<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Utilities\OrderManagerUtilities;

class CancelOrderService
{
    public function cancelOrderOnBasalam(int $orderId, string $description, int $reasonId = 3481)
    {
        if (empty($orderId)) {
            return [
                'success'     => false,
                'message'     => 'شناسه سفارش نامعتبر است.',
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

        if (empty($description)) {
            return [
                'success'     => false,
                'message'     => 'لطفاً توضیحات را وارد کنید.',
                'status_code' => 400,
            ];
        }

        if (empty($reasonId)) {
            return [
                'success'     => false,
                'message'     => 'لطفاً علت لغو را ثبت کنید.',
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

        $response = $this->sendCancelOrderRequest($itemIds, $description, $reasonId);
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

        $order->update_status('bslm-rejected', 'سفارش توسط ادمین لغو شد.');

        return [
            'success'     => true,
            'message'     => 'سفارش با موفقیت لغو شد.',
            'status_code' => 200,
        ];
    }

    private function sendCancelOrderRequest($itemIds, $description, $reasonId)
    {
        $apiUrl = Endpoints::ORDER_CANCEL;

        $orderItems = [];
        foreach ($itemIds as $id) {
            $orderItems[] = [
                'item_id'     => $id,
                'reason_id'   => $reasonId,
                'description' => $description,
            ];
        }

        $body = [
            'order_items' => $orderItems,
        ];

        $apiService = syncBasalamContainer()->get(ApiServiceManager::class);

        try {
            return $apiService->post($apiUrl, $body);
        } catch (\Exception $e) {
            return [
                'status_code' => 500,
                'body' => 'خطا در ارسال درخواست لغو سفارش: ' . $e->getMessage(),
            ];
        }
    }
}
