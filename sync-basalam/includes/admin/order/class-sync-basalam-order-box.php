<?php
if (! defined('ABSPATH')) exit;

class Sync_Basalam_Order_Box
{
    public function add_custom_order_tracking_box()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = get_current_screen();
        if ('shop_order' !== $screen->post_type) {
            return;
        }

        add_meta_box(
            'wc_order_tracking_box',
            'تنظیمات باسلام',
            array($this, 'display_order_tracking_box'),
            ['woocommerce_page_wc-orders', 'shop_order'],
            'side',
            'high'
        );
    }

    function display_order_tracking_box($post)
    {
        global $theorder;
        if (!is_object($theorder)) {
            $nonce = isset($_POST['sync_basalam_order_nonce']) ? sanitize_text_field(wp_unslash($_POST['sync_basalam_order_nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'sync_basalam_manage_order_actions')) {
                wp_die('درخواست نامعتبر است.');
            }
            $order_id = sanitize_text_field(isset($_GET['id'])) ? sanitize_text_field(absint($_GET['id'])) : 0;
            $theorder = wc_get_order($order_id);
        }

        if (!$theorder) {
            return;
        }

        $order_id = $theorder->get_id();
        $order_status = $theorder->get_status();

        include_once plugin_dir_path(__FILE__) . 'views/order-tracking-box.php';
    }
}
