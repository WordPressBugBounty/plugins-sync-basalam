<?php

namespace SyncBasalam\Admin\Order;

defined("ABSPATH") || exit;

class OrderMetaBox
{
    public function registerMetaBox()
    {
        if (!current_user_can("manage_woocommerce")) return;

        // HPOS uses 'id', traditional CPT uses 'post'
        $orderId = isset($_GET["id"]) ? absint($_GET["id"]) : 0;
        if (!$orderId)  $orderId = isset($_GET["post"]) ? absint($_GET["post"]) : 0;
        if (!$orderId) return;

        $order = wc_get_order($orderId);
        if (!$order) return;

        $allowedStatuses = [
            "bslm-rejected",
            "bslm-preparation",
            "bslm-wait-vendor",
            "bslm-shipping",
            "bslm-completed",
        ];

        if (!in_array($order->get_status(), $allowedStatuses, true)) return;

        add_meta_box(
            "basalam_order_settings",
            "تنظیمات باسلام",
            [$this, "renderMetaBox"],
            ["woocommerce_page_wc-orders", "shop_order"],
            "side",
            "high"
        );

        add_meta_box(
            'basalam_order_info',
            'اطلاعات سفارش باسلام',
            [$this, 'renderBasalamOrderMetaBox'],
            ["woocommerce_page_wc-orders", "shop_order"],
            "side",
            "high"
        );
    }

    public function renderMetaBox($post_or_order)
    {
        // HPOS passes WC_Order object, traditional CPT passes WP_Post object
        if (is_object($post_or_order) && method_exists($post_or_order, 'get_id')) {
            $orderId = $post_or_order->get_id();
        } else $orderId = $post_or_order->ID;

        $order = wc_get_order($orderId);
        if (!$order) return;

        $orderStatus = $order->get_status();

        require_once plugin_dir_path(__FILE__) . "OrderTrackingBox.php";
    }

    public function renderBasalamOrderMetaBox($post_or_order)
    {
        // HPOS passes WC_Order object, traditional CPT passes WP_Post object
        if (is_object($post_or_order) && method_exists($post_or_order, 'get_id')) {
            $orderId = $post_or_order->get_id();
        } else $orderId = $post_or_order->ID;

        $order = wc_get_order($orderId);
        if (!$order) {
            echo '<p class="basalam-p">سفارش یافت نشد</p>';
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sync_basalam_payments';

        $invoice_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT invoice_id FROM {$table_name} WHERE order_id = %d",
                $orderId
            )
        );

        if (!$invoice_id) {
            echo '<p class="basalam-p">اطلاعات سفارش باسلام یافت نشد</p>';
            return;
        }

        $fee_amount = $order->get_meta('_basalam_fee_amount', true);
        $balance_amount = $order->get_meta('_basalam_balance_amount', true);
        $purchase_count = $order->get_meta('_basalam_purchase_count', true);
        $hash_id = $order->get_meta('_sync_basalam_hash_id', true);

        $fee_formatted = $this->formatBasalamCurrency($fee_amount);
        $balance_formatted = $this->formatBasalamCurrency($balance_amount);

        require_once syncBasalamPlugin()->templatePath('orders/OrderMetaBox.php');
    }

    private function formatBasalamCurrency($amount)
    {
        if (empty($amount) || $amount == 0) return '0 تومان';

        return number_format($amount) . ' تومان';
    }
}
