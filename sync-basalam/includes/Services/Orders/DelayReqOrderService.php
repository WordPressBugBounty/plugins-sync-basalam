<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Utilities\OrderManagerUtilities;

class DelayReqOrderService
{
    public function delayReqOnBasalam(int $orderId, string $description, int $postponeDays)
    {
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

        $syncBasalamOrderId = OrderManagerUtilities::getInvoiceId($wpdb, $orderId);
        if (!$syncBasalamOrderId) {
            return [
                'success'     => false,
                'message'     => 'شناسه فاکتور سفارش یافت نشد.',
                'status_code' => 400,
            ];
        }

        $response = $this->sendDelayRequestToBasalam($syncBasalamOrderId, $description, $postponeDays);

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

    private function sendDelayRequestToBasalam($orderId, $description, $postponeDays)
    {
        $apiUrl = sprintf(Endpoints::ORDER_DELAY, $orderId);

        $body = [
            "topic"    => 5075,
            "metadata" => [
                "postpone_days" => $postponeDays,
                "description"   => $description,
            ],
        ];

        $apiService = syncBasalamContainer()->get(ApiServiceManager::class);

        try {
            return $apiService->put($apiUrl, $body);
        } catch (\Exception $e) {
            return [
                'status_code' => 500,
                'body'        => $e->getMessage(),
            ];
        }
    }
}
