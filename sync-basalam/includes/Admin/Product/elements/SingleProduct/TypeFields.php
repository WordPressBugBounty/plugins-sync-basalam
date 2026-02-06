<?php

namespace SyncBasalam\Admin\Product\elements\SingleProduct;

defined('ABSPATH') || exit;

class TypeFields
{
    public static function renderCheckbox()
    {
        global $post;
        $checked = get_post_meta($post->ID, '_sync_basalam_is_product_type_checkbox', true) === 'yes';

        woocommerce_wp_checkbox([
            'id'          => '_sync_basalam_is_product_type_checkbox',
            'label'       => 'نوع محصول (باسلام)',
            'description' => 'اگر این محصول از نوع باسلام است، این گزینه را فعال نمایید.',
            'desc_tip'    => true,
            'checked'     => $checked,
        ]);

        wp_nonce_field('sync_basalam_save_product_type_action', '_sync_basalam_product_type_nonce');

        echo '<div id="basalam_product_fields" class="basalam-mobile-fields' . ($checked ? ' show' : '') . '">';

        self::renderFields($post->ID);

        echo '</div>';
    }

    private static function renderFields($postId)
    {
        $unitValue = get_post_meta($postId, '_sync_basalam_product_unit', true);
        $amountValue = get_post_meta($postId, '_sync_basalam_product_value', true);

        $units = [
            'none' => 'انتخاب کنید',
            '6304' => 'عددی',
            '6305' => 'کیلو‌گرم',
            '6306' => 'گرم',
            '6307' => 'متر',
            '6308' => 'سانتی‌متر',
            '6309' => 'مثقال',
            '6310' => 'تکه (اسلایس)',
            '6311' => 'لیتر',
            '6312' => 'میلی‌لیتر',
            '6313' => 'سی‌سی',
            '6314' => 'انس',
            '6315' => 'قواره',
            '6316' => 'طاقه',
            '6317' => 'جین',
            '6318' => 'جفت',
            '6319' => 'بسته',
            '6320' => 'توپ',
            '6321' => 'کارتن',
            '6322' => 'تخته',
            '6323' => 'بطری',
            '6324' => 'دست',
            '6325' => 'بوته',
            '6326' => 'شاخه',
            '6327' => 'قالب',
            '6328' => 'کلاف',
            '6329' => 'اصله',
            '6330' => 'سیر',
            '6331' => 'اینچ',
            '6332' => 'فوت',
            '6373' => 'جلد',
            '6374' => 'میلی‌متر',
            '6375' => 'مترمربع',
            '6392' => 'رول',
            '6438' => 'سوت',
            '6466' => 'قیراط',
        ];

        woocommerce_wp_select([
            'id'      => '_sync_basalam_product_unit',
            'label'   => 'واحد محصول',
            'options' => $units,
            'value'   => $unitValue,
        ]);

        woocommerce_wp_text_input([
            'id'    => '_sync_basalam_product_value',
            'label' => 'مقدار محصول',
            'type'  => 'number',
            'value' => $amountValue,
        ]);
    }

    public static function saveCheckbox($postId)
    {
        if (
            !isset($_POST['_sync_basalam_product_type_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_product_type_nonce'])), 'sync_basalam_save_product_type_action')
        ) {
            return;
        }

        $checkboxValue = isset($_POST['_sync_basalam_is_product_type_checkbox']) ? sanitize_text_field(wp_unslash($_POST['_sync_basalam_is_product_type_checkbox'])) : '';
        $isChecked = ($checkboxValue === 'yes' || $checkboxValue === '1') ? 'yes' : 'no';

        update_post_meta($postId, '_sync_basalam_is_product_type_checkbox', $isChecked);

        if ($isChecked === 'yes') {
            self::saveFields($postId);
        } else {
            delete_post_meta($postId, '_sync_basalam_product_unit');
            delete_post_meta($postId, '_sync_basalam_product_value');
        }
    }

    private static function saveFields($postId)
    {
        $fields = [
            '_sync_basalam_product_unit',
            '_sync_basalam_product_value',
        ];

        foreach ($fields as $fieldKey) {
            if (isset($_POST[$fieldKey])) {
                update_post_meta(
                    $postId,
                    $fieldKey,
                    sanitize_text_field(wp_unslash($_POST[$fieldKey]))
                );
            }
        }
    }
}
