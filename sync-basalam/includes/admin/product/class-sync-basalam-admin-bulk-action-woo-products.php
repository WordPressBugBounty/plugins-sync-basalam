<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Bulk_Action_Woo_Products
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function add_bulk_actions($actions)
    {
        $actions['add_to_basalam'] = 'افزودن به باسلام';
        $actions['update_on_basalam'] = 'آپدیت در باسلام';
        $actions['disconnect_basalam_product'] = 'قطع اتصال محصول باسلام';
        return $actions;
    }

    public function handle_bulk_actions($redirect_to, $doaction, $post_ids)
    {
        if (
            !isset($_REQUEST['_wpnonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'bulk-posts')
        ) {
            wp_die('درخواست نامعتبر است. لطفاً دوباره تلاش کنید.');
        }

        $added = 0;
        $updated = 0;

        if ($doaction === 'add_to_basalam') {
            sync_basalam_Product_Queue_Manager::create_specific_products_in_basalam($post_ids);
            $redirect_to = add_query_arg('sync_basalam_added', $added, $redirect_to);
        }

        if ($doaction === 'update_on_basalam') {
            sync_basalam_Product_Queue_Manager::update_specific_products_in_basalam($post_ids);
            $redirect_to = add_query_arg('sync_basalam_updated', $updated, $redirect_to);
        }

        if ($doaction === 'disconnect_basalam_product') {
            sync_basalam_Product_Queue_Manager::disconnect_specific_products_in_basalam($post_ids);
            $redirect_to = add_query_arg('sync_basalam_disconnected_products', $updated, $redirect_to);
        }

        return $redirect_to;
    }

    public function show_admin_notices()
    {
        if (!empty($_GET['sync_basalam_added'])) {
            $count = sanitize_text_field(intval($_GET['sync_basalam_added']));
            echo "<div class='notice notice-success is-dismissible'><p>" . esc_html("{$count} محصول با موفقیت به باسلام اضافه شد.") . "</p></div>";
        }

        if (!empty($_GET['sync_basalam_updated'])) {
            $count = sanitize_text_field(intval($_GET['sync_basalam_updated']));
            echo "<div class='notice notice-success is-dismissible'><p>" . esc_html("{$count} محصول در باسلام آپدیت شد.") . "</p></div>";
        }

        if (!empty($_GET['sync_basalam_disconnected_products'])) {
            $count = sanitize_text_field(intval($_GET['sync_basalam_updated']));
            echo "<div class='notice notice-success is-dismissible'><p>" . esc_html("قطع اتصال {$count} انجام شد.") . "</p></div>";
        }
    }
}
