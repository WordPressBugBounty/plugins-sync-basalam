<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Asset
{
    static function is_product($product_id)
    {
        return get_post_type($product_id) === 'product';
    }
    public static function handle_sync_basalam_action($operation_name, $callback)
    {
        if (isset($_POST['operation']) && $_POST['operation'] == $operation_name) {

            if (sanitize_text_field(isset($_POST['nonce'])) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'sync_basalam_nonce_action')) {

                if (isset($_POST['product_id'])) {
                    $product_id = sanitize_text_field(wp_unslash($_POST['product_id']));

                    $callback($product_id);
                } else {
                    wp_die('آی دی محصول ارسال نشده است.');
                }
            } else {
                wp_die('معتبر نیست. لطفاً دوباره تلاش کنید.');
            }
        }
    }

    public static function check_woo_queue_status()
    {
        $store = ActionScheduler_Store::instance();
        $pending_count = $store->query_actions(['status' => 'pending'], 'count');
        if ($pending_count === false) {
            return false;
        } else {
            return true;
        }
    }

    public static function get_count_of_synced_basalam_products()
    {
        global $wpdb;
        $get_count_of_synced_basalam_products = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'sync_basalam_product_id'
        ");
        return intval($get_count_of_synced_basalam_products);
    }
    public static function count_of_published_woocamerce_products()
    {
        $count = wp_count_posts('product');
        return $count->publish;
    }
    public static function remove_sync_basalam_meta_on_duplicate_product($new_product_id, $old_product)
    {
        if ($old_product->post_type !== 'product') return;
        sync_basalam_Admin_Product_Operations::disconnect_product($new_product_id);
    }
}
