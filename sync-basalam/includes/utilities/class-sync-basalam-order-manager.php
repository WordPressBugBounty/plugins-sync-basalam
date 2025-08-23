<?php
class Sync_Basalam_Order_Manager_Utilities
{
    static function get_all_item_ids_from_meta($wpdb, $order_id)
    {
        $meta_key_pattern = '_sync_basalam_item_id_%';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta 
                 WHERE order_id = %d AND meta_key LIKE %s",
                $order_id,
                $meta_key_pattern
            )
        );

        $item_ids = [];
        if ($results) {
            foreach ($results as $row) {
                $item_ids[] = $row->meta_value;
            }
        }

        return $item_ids;
    }


    static function get_invoice_id($wpdb, $order_id)
    {
        $table_name = $wpdb->prefix . 'sync_basalam_payments';

        $order_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT invoice_id FROM {$table_name} WHERE order_id = %d LIMIT 1",
                $order_id
            )
        );

        return $order_data ? $order_data->invoice_id : null;
    }
}
