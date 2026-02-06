<?php

namespace SyncBasalam\Admin\Product\elements\SingleProduct;

defined('ABSPATH') || exit;

class WholesaleField
{
    public static function renderCheckbox()
    {
        $isWholesale = get_post_meta(get_the_ID(), '_sync_basalam_is_wholesale', true);
        $checked = ($isWholesale === 'yes') ? 'yes' : 'no';

        woocommerce_wp_checkbox([
            'id'          => '_sync_basalam_is_wholesale',
            'label'       => 'محصول عمده است(باسلام)',
            'description' => 'اگر این محصول عمده است، این گزینه را فعال نمایید.',
            'desc_tip'    => true,
            'value'       => $checked,
        ]);

        wp_nonce_field('sync_basalam_save_wholesale_action', '_sync_basalam_wholesale_nonce');
    }

    public static function saveCheckbox($postId)
    {
        if (
            !isset($_POST['_sync_basalam_wholesale_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_wholesale_nonce'])), 'sync_basalam_save_wholesale_action')
        ) {
            return;
        }

        $syncBasalamIsCheckboxValue = isset($_POST['_sync_basalam_is_wholesale']) ? 'yes' : 'no';
        update_post_meta($postId, '_sync_basalam_is_wholesale', $syncBasalamIsCheckboxValue);
    }
}
