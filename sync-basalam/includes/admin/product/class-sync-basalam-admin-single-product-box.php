<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Admin_Single_Product_Box
{

    public static function sync_basalam_single_product_manage_box()
    {
        global $post;
        $product_id = $post->ID;
        $product_status = get_post_meta($product_id, 'sync_basalam_product_status', true);
        $is_active = ($product_status == 2976);

        $status_color = $is_active ? 'var(--basalam-success-color)' : 'var(--basalam-danger-color)';
        $status_tooltip = $is_active ? 'محصول در باسلام در دسترس است.' : 'محصول در باسلام در دسترس نیست.';

        add_meta_box(
            'sync_basalam_single_product_manage_box',
            'تنظیمات باسلام <span title="' . esc_attr($status_tooltip) . '" class="basalam-status-circle" style="background-color:' . esc_attr($status_color) . ';">
            <img src="' . esc_url(sync_basalam_configure()->assets_url() . "/icons/info.svg") . '" alt="" style="width: 15px;">
            </span>',
            array('sync_basalam_Admin_Single_Product_Box', 'sync_basalam_single_product_manage_box_content'),
            'product',
            'side',
            'high'
        );
    }

    public static function sync_basalam_single_product_manage_box_content($post)
    {
        $product_id = $post->ID;
        $product_status = get_post_status($product_id);

        $product = get_post_meta($product_id, 'sync_basalam_product_status', true);
        if ($product_status == 'publish') {
            if ($product) {
                $sync_basalam_product_id = get_post_meta($product_id, 'sync_basalam_product_id', true);
                if ($product == 2976) {
                    sync_basalam_Admin_UI::render_btn('بروزسانی محصول در باسلام', false, 'update_product_in_basalam', $post->ID, 'update_product_in_basalam_nonce');
                    sync_basalam_Admin_UI::render_btn('آرشیو کردن محصول در باسلام', false, 'archive_exist_product_on_basalam', $post->ID, 'archive_exist_product_on_basalam_nonce');
                } else {
                    sync_basalam_Admin_UI::render_btn('بروزسانی محصول در باسلام', false, 'update_product_in_basalam', $post->ID, 'update_product_in_basalam_nonce');
                    sync_basalam_Admin_UI::render_btn('بازگردانی محصول در باسلام', false, 'restore_exist_product_on_basalam', $post->ID, 'restore_exist_product_on_basalam_nonce');
                }
                $link = "https://basalam.com/p/" . $sync_basalam_product_id;
                sync_basalam_Admin_UI::render_btn('مشاهده محصول در باسلام', $link);
                sync_basalam_Admin_UI::render_btn('قطع اتصال محصول', false, 'disconnect_exist_product_on_basalam', $post->ID, 'disconnect_exist_product_on_basalam_nonce');
            } else {
                sync_basalam_Admin_UI::render_btn('اضافه کردن محصول در باسلام', false, 'create_product_basalam', $post->ID, 'create_product_basalam_nonce');
                require_once sync_basalam_configure()->template_path("admin/utilities/connect-button-single-product-page.php");
            }
        } else {
            echo '<p class="basalam-p" style="font-size:12px;">برای دسترسی به تنظیمات باسلام ، وضعیت محصول را به "منتشر شده" تغییر دهید.</p>';
        }
        $nonce_get_basalam_category_ids_action = 'basalam_get_category_ids_nonce';
        $nonce_get_basalam_category_id_value  = wp_create_nonce($nonce_get_basalam_category_ids_action);

        echo '<div id="sync_basalam_category_id" class="basalam-p__small basalam--hidden">
        <input type="hidden"  id="basalam_get_category_ids_nonce" value="' . esc_attr($nonce_get_basalam_category_id_value) . '">
        </div>';

        $nonce_get_basalam_category_attr_action = 'basalam_get_category_attrs_nonce';
        $nonce_get_basalam_category_attr_value  = wp_create_nonce($nonce_get_basalam_category_attr_action);

        echo '<div id="sync_basalam_category_attributes" class="basalam-p__small basalam--hidden">
        <input type="hidden"  id="basalam_get_category_attrs_nonce" value="' . esc_attr($nonce_get_basalam_category_attr_value) . '">
        </div>';
    }
}
