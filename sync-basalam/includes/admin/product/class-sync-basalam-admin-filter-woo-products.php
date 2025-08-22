<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Filter_Woo_Products
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function filter_by_exist_on_basalam()
    {
        global $typenow;

        if ($typenow === 'product') {
            $selected = sanitize_text_field(isset($_GET['sync_basalam_not_added'])) ? sanitize_text_field(wp_unslash($_GET['sync_basalam_not_added'])) : '';
            wp_nonce_field('sync_basalam_filter_action', '_sync_basalam_filter_nonce'); ?>
            <select name="sync_basalam_not_added" style="font-family: PelakFa;font-size:12px;">
                <option value="">فیلتر ووسلام</option>
                <option value="0" <?php selected($selected, '0'); ?>>محصولات اضافه شده به باسلام</option>
                <option value="1" <?php selected($selected, '1'); ?>>محصولات اضافه نشده به باسلام</option>
            </select>
<?php
        }
    }


    public function filter_query_for_basalam($query)
    {
        global $pagenow;

        if (
            is_admin() &&
            $query->is_main_query() &&
            $pagenow === 'edit.php' &&
            isset($_GET['_sync_basalam_filter_nonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_sync_basalam_filter_nonce'])), 'sync_basalam_filter_action')
        ) {
            $post_type = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';
            $filter_value = isset($_GET['sync_basalam_not_added']) ? sanitize_text_field(wp_unslash($_GET['sync_basalam_not_added'])) : '';

            if ($post_type === 'product') {
                if ($filter_value === '1') {
                    $query->set('meta_query', array(
                        array(
                            'key'     => 'sync_basalam_product_id',
                            'compare' => 'NOT EXISTS',
                        )
                    ));
                } elseif ($filter_value === '0') {
                    $query->set('meta_query', array(
                        array(
                            'key'     => 'sync_basalam_product_id',
                            'compare' => 'EXISTS',
                        )
                    ));
                }
            }
        }
    }
}
