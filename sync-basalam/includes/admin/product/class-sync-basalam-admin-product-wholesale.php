<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Product_Wholesale
{
    static public function sync_basalam_wholesale_button()
    {
        $is_wholesale = get_post_meta(get_the_ID(), '_sync_basalam_is_wholesale', true);
        $checked = ($is_wholesale === 'yes') ? 'yes' : 'no';

        woocommerce_wp_checkbox(array(
            'id'            => '_sync_basalam_is_wholesale',
            'label'         => 'محصول عمده است(باسلام)',
            'description'   => 'اگر این محصول عمده است، این گزینه را فعال نمایید.',
            'desc_tip'      => true,
            'value'         => $checked
        ));

        wp_nonce_field('sync_basalam_save_wholesale_action', '_sync_basalam_wholesale_nonce');
    }

    static public function save_sync_basalam_product_wholesale($post_id)
    {
        if (
            !isset($_POST['_sync_basalam_wholesale_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_wholesale_nonce'])), 'sync_basalam_save_wholesale_action')
        ) {
            return;
        }

        $sync_basalam_is_sync_basalam_checkbox_value = isset($_POST['_sync_basalam_is_wholesale']) ? 'yes' : 'no';
        update_post_meta($post_id, '_sync_basalam_is_wholesale', $sync_basalam_is_sync_basalam_checkbox_value);
    }
}
