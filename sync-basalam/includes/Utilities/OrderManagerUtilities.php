<?php

namespace SyncBasalam\Utilities;

class OrderManagerUtilities
{
    public static function getAllItemIdsFromMeta($wpdb, $orderId)
    {
        $metaKeyPattern = '_sync_basalam_item_id_%';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta
                 WHERE order_id = %d AND meta_key LIKE %s",
                $orderId,
                $metaKeyPattern
            )
        );

        $itemIds = [];
        if ($results) {
            foreach ($results as $row) {
                $itemIds[] = $row->meta_value;
            }
        }

        return $itemIds;
    }

    public static function getInvoiceId($wpdb, $orderId)
    {
        $tableName = $wpdb->prefix . 'sync_basalam_payments';

        $orderData = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT invoice_id FROM {$tableName} WHERE order_id = %d LIMIT 1",
                $orderId
            )
        );

        return $orderData ? $orderData->invoice_id : null;
    }
}
