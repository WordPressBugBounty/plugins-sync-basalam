<?php

namespace SyncBasalam\Admin\Product\elements\SingleProduct;

defined('ABSPATH') || exit;

class Tab
{
    public static function registerTab($tabs)
    {
        $tabs['basalam_settings'] = [
            'label'    => 'تنظیمات بیشتر محصول ووسلام',
            'target'   => 'basalam_product_data',
            'class'    => ['basalam-product-tab'],
        ];

        return $tabs;
    }

    public static function renderTabContent()
    {
        global $post;

        echo '<div id="basalam_product_data" class="panel woocommerce_options_panel">';
        echo '<div class="options_group">';

        // Mobile Product Field
        MobileFields::renderCheckbox();

        echo '</div>';
        echo '<div class="options_group">';

        // Product Type Field
        TypeFields::renderCheckbox();

        echo '</div>';
        echo '<div class="options_group">';

        // Wholesale Product Field
        WholesaleField::renderCheckbox();

        echo '</div>';
        echo '</div>';
    }

    public static function saveTabData($post_id)
    {
        MobileFields::saveCheckbox($post_id);
        TypeFields::saveCheckbox($post_id);
        WholesaleField::saveCheckbox($post_id);
    }
}
