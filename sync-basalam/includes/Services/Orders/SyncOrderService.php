<?php

namespace SyncBasalam\Services\Orders;

use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class SyncOrderService
{
    public function syncOrders(array $orders): array
    {
        $synced = 0;
        $skipped = 0;
        $errors = [];

        if (empty($orders)) {
            return [
                'synced' => 0,
                'skipped' => 0,
                'errors' => []
            ];
        }

        global $wpdb;
        $tableName = $wpdb->prefix . 'sync_basalam_payments';

        foreach ($orders as $order) {
            $invoiceId = $order['order']['id'] ?? null;

            if (!$invoiceId) {
                Logger::warning("سفارش بدون شناسه فاکتور پیدا شد: " . json_encode($order));
                continue;
            }

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT invoice_id FROM {$tableName} WHERE invoice_id = %d",
                    $invoiceId
                )
            );

            if ($exists) {
                $skipped++;
                continue;
            }

            try {
                $request = new \WP_REST_Request('POST');
                $request->set_param('invoice_id', $order['order']['id']);
                $request->set_param('user_id', $order['order']['customer']['user']['id'] ?? null);
                $request->set_param('city_id', $order['order']['customer']['city']['id'] ?? null);
                $request->set_param('province_id', $order['order']['customer']['city']['parent']['id'] ?? null);

                $result = OrderManager::createOrderWoo($request->get_params());

                if (is_wp_error($result) || (isset($result->data['success']) && !$result->data['success'])) {
                    $errorMsg = is_wp_error($result) ? $result->get_error_message() : ($result->data['error'] ?? 'خطای نامشخص');
                    Logger::error("خطا در ایجاد سفارش {$invoiceId}: " . $errorMsg);
                    $errors[] = "سفارش {$invoiceId}: " . $errorMsg;
                } else {
                    $synced++;
                    Logger::info("سفارش {$invoiceId} با موفقیت ایجاد شد.");
                }
            } catch (\Exception $e) {
                Logger::error("خطا در ایجاد سفارش {$invoiceId}: " . $e->getMessage());
                $errors[] = "سفارش {$invoiceId}: " . $e->getMessage();
            }
        }

        return [
            'synced' => $synced,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }
}
