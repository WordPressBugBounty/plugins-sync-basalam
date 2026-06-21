<?php

namespace SyncBasalam\Admin\Product\elements\SingleProduct;

defined('ABSPATH') || exit;

class GoldFields
{
    public static function renderCheckbox()
    {
        $isGoldProduct = get_post_meta(get_the_ID(), '_sync_basalam_is_gold_product_checkbox', true);
        $checked = ($isGoldProduct === 'yes') ? 'yes' : 'no';

        woocommerce_wp_checkbox([
            'id'          => '_sync_basalam_is_gold_product_checkbox',
            'label'       => 'محصول طلا',
            'description' => 'در صورتی که محصول شما طلا است ، این گزینه را فعال نمایید .',
            'desc_tip'    => true,
            'value'       => $checked,
        ]);

        wp_nonce_field('sync_basalam_save_gold_fields_action', '_sync_basalam_gold_fields_nonce');

        self::renderFields($checked);
    }

    public static function renderFields($checked)
    {
        $fields = [
            '_sync_basalam_gold_purity' => 'عیار طلا (مبنای 1000)*',
            '_sync_basalam_gold_weight' => 'وزن طلا (میلی گرم)*',
        ];

        echo '<div id="basalam_gold_product_fields" class="basalam-mobile-fields' . ($checked === 'yes' ? ' show' : '') . '">';

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
            !isset($_POST['_sync_basalam_gold_fields_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_gold_fields_nonce'])), 'sync_basalam_save_gold_fields_action')
        ) {
            return;
        }

        $syncBasalamIsGoldCheckboxValue = isset($_POST['_sync_basalam_is_gold_product_checkbox']) ? 'yes' : 'no';
        update_post_meta($postId, '_sync_basalam_is_gold_product_checkbox', $syncBasalamIsGoldCheckboxValue);

        if ($syncBasalamIsGoldCheckboxValue === 'no') {
            self::deleteFields($postId);
        } else {
            self::saveFields($postId);
        }
    }

    public static function deleteFields($postId)
    {
        $fields = [
            '_sync_basalam_gold_purity',
            '_sync_basalam_gold_weight',
        ];

        foreach ($fields as $field) {
            delete_post_meta($postId, $field);
        }
    }

    public static function saveFields($postId)
    {
        if (
            !isset($_POST['_sync_basalam_gold_fields_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_gold_fields_nonce'])), 'sync_basalam_save_gold_fields_action')
        ) {
            return;
        }

        $fields = [
            '_sync_basalam_gold_purity' => '',
            '_sync_basalam_gold_weight' => '',
        ];

        foreach ($fields as $fieldKey => $default) {
            if (isset($_POST[$fieldKey])) {
                $value = sanitize_text_field(wp_unslash($_POST[$fieldKey]));
                update_post_meta($postId, $fieldKey, $value);
            }
        }
    }
}
