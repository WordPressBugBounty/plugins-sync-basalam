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
                $message = "سفارش بدون شناسه فاکتور پیدا شد: " . wp_json_encode($order, JSON_UNESCAPED_UNICODE);
                Logger::warning($message);
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, not user input.
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
                $result = OrderManager::createOrderWoo([
                    'invoice_id'  => $order['order']['id'],
                    'user_id'     => $order['order']['customer']['user']['id'] ?? null,
                    'city_id'     => $order['order']['customer']['city']['id'] ?? null,
                    'province_id' => $order['order']['customer']['city']['parent']['id'] ?? null,
                ]);

                if (empty($result['success'])) {
                    $errorMsg = $result['error'] ?? 'خطای نامشخص';
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
