<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Mobile_Category_Fields
{
    static function mobile_category_require_fildes()
    {
        $is_mobile_product = get_post_meta(get_the_ID(), '_sync_basalam_is_mobile_product_checkbox', true);
        $checked = ($is_mobile_product === 'yes') ? 'yes' : 'no';

        woocommerce_wp_checkbox(array(
            'id'            => '_sync_basalam_is_mobile_product_checkbox',
            'label'         => 'محصول موبایلی(باسلام)',
            'description'   => 'در صورتی که محصول شما موبایل است ، این گزینه را فعال نمایید .',
            'desc_tip'      => true,
            'value'         => $checked
        ));

        wp_nonce_field('sync_basalam_save_mobile_fields_action', '_sync_basalam_mobile_fields_nonce');

        self::display_mobile_fields($checked);
    }

    static function mobile_category_require_fildes_var()
    {
        $is_mobile_product = get_post_meta(get_the_ID(), '_sync_basalam_is_mobile_product_checkbox', true);
        $checked = ($is_mobile_product === 'yes') ? 'yes' : 'no';

        woocommerce_wp_checkbox(array(
            'id'            => '_sync_basalam_is_mobile_product_checkbox',
            'label'         => 'محصول موبایلی(باسلام)',
            'description'   => 'در صورتی که محصول شما موبایل است ، این گزینه را فعال نمایید .',
            'desc_tip'      => true,
            'value'         => $checked
        ));

        wp_nonce_field('sync_basalam_save_mobile_fields_action', '_sync_basalam_mobile_fields_nonce');

        self::display_mobile_fields($checked);
    }

    static function display_mobile_fields($checked)
    {
        $fields = array(
            '_sync_basalam_mobile_storage'     => 'حافظه داخلی*',
            '_sync_basalam_cpu_type'           => 'نوع پردازنده - CPU*',
            '_sync_basalam_mobile_ram'         => 'حافظه RAM*',
            '_sync_basalam_screen_size'        => 'سایز صفحه نمایش*',
            '_sync_basalam_rear_camera'        => 'دوربین پشت*',
            '_sync_basalam_battery_capacity'   => 'ظرفیت باتری*',
        );

        echo '<div id="basalam_mobile_product_fields" style="display:' . ($checked === 'yes' ? 'block' : 'none') . ';">';

        foreach ($fields as $id => $label) {
            woocommerce_wp_text_input(array(
                'id'    => $id,
                'label' => $label,
                'type'  => 'text',
                'value' => get_post_meta(get_the_ID(), $id, true),
            ));
        }

        echo '</div>';
    }

    static function save_sync_basalam_is_mobile_checkbox_field($post_id)
    {
        if (
            !isset($_POST['_sync_basalam_mobile_fields_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_mobile_fields_nonce'])), 'sync_basalam_save_mobile_fields_action')
        ) {
            return;
        }

        $sync_basalam_is_mobile_checkbox_value = isset($_POST['_sync_basalam_is_mobile_product_checkbox']) ? 'yes' : 'no';
        update_post_meta($post_id, '_sync_basalam_is_mobile_product_checkbox', $sync_basalam_is_mobile_checkbox_value);

        if ($sync_basalam_is_mobile_checkbox_value === 'no') {
            self::delete_mobile_fields($post_id);
        } else {
            self::save_sync_basalam_mobile_attr_fields($post_id);
        }
    }

    static function delete_mobile_fields($post_id)
    {
        $fields = array(
            '_sync_basalam_mobile_storage',
            '_sync_basalam_cpu_type',
            '_sync_basalam_mobile_ram',
            '_sync_basalam_screen_size',
            '_sync_basalam_rear_camera',
            '_sync_basalam_battery_capacity'
        );

        foreach ($fields as $field) {
            delete_post_meta($post_id, $field);
        }
    }

    static function save_sync_basalam_mobile_attr_fields($post_id)
    {
        if (
            !isset($_POST['_sync_basalam_mobile_fields_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_mobile_fields_nonce'])), 'sync_basalam_save_mobile_fields_action')
        ) {
            return;
        }

        $fields = array(
            '_sync_basalam_mobile_storage'     => '',
            '_sync_basalam_cpu_type'           => '',
            '_sync_basalam_mobile_ram'         => '',
            '_sync_basalam_screen_size'        => '',
            '_sync_basalam_rear_camera'        => '',
            '_sync_basalam_battery_capacity'   => ''
        );

        foreach ($fields as $field_key => $default_value) {
            if (isset($_POST[$field_key])) {
                update_post_meta(
                    $post_id,
                    $field_key,
                    sanitize_text_field(wp_unslash($_POST[$field_key]))
                );
            }
        }
    }
}
