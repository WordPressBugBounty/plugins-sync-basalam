<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Product_Type
{
    public static function add_sync_basalam_is_product_type_checkbox_to_product_page()
    {
        global $post;
        $checked = get_post_meta($post->ID, '_sync_basalam_is_product_type_checkbox', true) === 'yes';

        woocommerce_wp_checkbox(array(
            'id'          => '_sync_basalam_is_product_type_checkbox',
            'label'       => 'نوع محصول (باسلام)',
            'description' => 'اگر این محصول از نوع باسلام است، این گزینه را فعال نمایید.',
            'desc_tip'    => true,
            'checked'     => $checked,
        ));

        wp_nonce_field('sync_basalam_save_product_type_action', '_sync_basalam_product_type_nonce');

        echo '<div id="basalam_product_fields" style="display:' . ($checked ? 'block' : 'none') . ';">';

        self::render_unit_and_value_fields($post->ID);

        echo '</div>';
    }

    private static function render_unit_and_value_fields($post_id)
    {
        $unit_value = get_post_meta($post_id, '_sync_basalam_product_unit', true);
        $amount_value = get_post_meta($post_id, '_sync_basalam_product_value', true);

        $units = array(
            'none' => 'انتخاب کنید',
            '6304' => 'عدد',
            '6305' => 'کیلوگرم',
            '6306' => 'گرم',
            '6307' => 'متر',
            '6308' => 'سانتی‌متر',
            '6374' => 'میلی‌متر',
            '6375' => 'مترمربع',
            '6373' => 'جلد',
            '6313' => 'سی‌سی',
            '6322' => 'تخته',
            '6392' => 'رول',
            '6318' => 'جفت',
            '6326' => 'شاخه',
            '6324' => 'دست',
            '6311' => 'لیتر',
            '6332' => 'فوت',
            '6331' => 'اینچ',
            '6330' => 'سیر',
            '6329' => 'اصله',
            '6328' => 'کلاف',
            '6327' => 'قالب',
            '6325' => 'بوته',
            '6323' => 'بطری',
            '6320' => 'توپ',
            '6317' => 'جین',
            '6316' => 'طاقه',
            '6315' => 'قواره',
            '6314' => 'انس',
            '6312' => 'میلی‌لیتر',
            '6310' => 'تکه (اسلایس)',
            '6309' => 'مثقال',
        );

        woocommerce_wp_select(array(
            'id'      => '_sync_basalam_product_unit',
            'label'   => 'واحد محصول',
            'options' => $units,
            'value'   => $unit_value,
        ));

        woocommerce_wp_text_input(array(
            'id'    => '_sync_basalam_product_value',
            'label' => 'مقدار محصول',
            'type'  => 'number',
            'value' => $amount_value,
        ));
    }

    public static function save_sync_basalam_product_units($post_id)
    {
        if (
            !isset($_POST['_sync_basalam_product_type_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_product_type_nonce'])), 'sync_basalam_save_product_type_action')
        ) {
            return;
        }

        $checkbox_value = isset($_POST['_sync_basalam_is_product_type_checkbox']) ? sanitize_text_field(wp_unslash($_POST['_sync_basalam_is_product_type_checkbox'])) : '';
        $is_checked = ($checkbox_value === 'yes' || $checkbox_value === '1') ? 'yes' : 'no';

        update_post_meta($post_id, '_sync_basalam_is_product_type_checkbox', $is_checked);

        if ($is_checked === 'yes') {
            self::save_sync_basalam_units_fields($post_id);
        } else {
            delete_post_meta($post_id, '_sync_basalam_product_unit');
            delete_post_meta($post_id, '_sync_basalam_product_value');
        }
    }

    private static function save_sync_basalam_units_fields($post_id)
    {
        $fields = array(
            '_sync_basalam_product_unit',
            '_sync_basalam_product_value'
        );

        foreach ($fields as $field_key) {
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
