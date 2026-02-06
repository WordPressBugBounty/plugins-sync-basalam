<?php

namespace SyncBasalam\Admin\Product\elements\SingleProduct;

defined('ABSPATH') || exit;

class MobileFields
{
    public static function renderCheckbox()
    {
        $isMobileProduct = get_post_meta(get_the_ID(), '_sync_basalam_is_mobile_product_checkbox', true);
        $checked = ($isMobileProduct === 'yes') ? 'yes' : 'no';

        woocommerce_wp_checkbox([
            'id'          => '_sync_basalam_is_mobile_product_checkbox',
            'label'       => 'محصول موبایلی(باسلام)',
            'description' => 'در صورتی که محصول شما موبایل است ، این گزینه را فعال نمایید .',
            'desc_tip'    => true,
            'value'       => $checked,
        ]);

        wp_nonce_field('sync_basalam_save_mobile_fields_action', '_sync_basalam_mobile_fields_nonce');

        self::renderFields($checked);
    }

    public static function renderFields($checked)
    {
        $fields = [
            '_sync_basalam_mobile_storage'   => 'حافظه داخلی*',
            '_sync_basalam_cpu_type'         => 'نوع پردازنده - CPU*',
            '_sync_basalam_mobile_ram'       => 'حافظه RAM*',
            '_sync_basalam_screen_size'      => 'سایز صفحه نمایش*',
            '_sync_basalam_rear_camera'      => 'دوربین پشت*',
            '_sync_basalam_battery_capacity' => 'ظرفیت باتری*',
        ];

        echo '<div id="basalam_mobile_product_fields" class="basalam-mobile-fields' . ($checked === 'yes' ? ' show' : '') . '">';

        foreach ($fields as $id => $label) {
            woocommerce_wp_text_input([
                'id'    => $id,
                'label' => $label,
                'type'  => 'text',
                'value' => get_post_meta(get_the_ID(), $id, true),
            ]);
        }

        echo '</div>';
    }

    public static function saveCheckbox($postId)
    {
        if (
            !isset($_POST['_sync_basalam_mobile_fields_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_mobile_fields_nonce'])), 'sync_basalam_save_mobile_fields_action')
        ) {
            return;
        }

        $syncBasalamIsMobileCheckboxValue = isset($_POST['_sync_basalam_is_mobile_product_checkbox']) ? 'yes' : 'no';
        update_post_meta($postId, '_sync_basalam_is_mobile_product_checkbox', $syncBasalamIsMobileCheckboxValue);

        if ($syncBasalamIsMobileCheckboxValue === 'no') {
            self::deleteFields($postId);
        } else {
            self::saveFields($postId);
        }
    }

    public static function deleteFields($postId)
    {
        $fields = [
            '_sync_basalam_mobile_storage',
            '_sync_basalam_cpu_type',
            '_sync_basalam_mobile_ram',
            '_sync_basalam_screen_size',
            '_sync_basalam_rear_camera',
            '_sync_basalam_battery_capacity',
        ];

        foreach ($fields as $field) {
            delete_post_meta($postId, $field);
        }
    }

    public static function saveFields($postId)
    {
        if (
            !isset($_POST['_sync_basalam_mobile_fields_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_mobile_fields_nonce'])), 'sync_basalam_save_mobile_fields_action')
        ) {
            return;
        }

        $fields = [
            '_sync_basalam_mobile_storage'   => '',
            '_sync_basalam_cpu_type'         => '',
            '_sync_basalam_mobile_ram'       => '',
            '_sync_basalam_screen_size'      => '',
            '_sync_basalam_rear_camera'      => '',
            '_sync_basalam_battery_capacity' => '',
        ];

        foreach ($fields as $fieldKey) {
            if (isset($_POST[$fieldKey])) {
                $value = sanitize_text_field(wp_unslash($_POST[$fieldKey]));
                update_post_meta($postId, $fieldKey, $value);
            }
        }
    }
}
