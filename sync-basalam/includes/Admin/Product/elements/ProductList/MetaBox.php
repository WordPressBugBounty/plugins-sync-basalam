<?php

namespace SyncBasalam\Admin\Product\elements\ProductList;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Components\CommonComponents;

defined('ABSPATH') || exit;

class MetaBox
{
    public static function registerMetaBox()
    {
        global $post;
        $productId = $post->ID;
        $productStatus = get_post_meta($productId, 'sync_basalam_product_sync_status', true);

        switch ($productStatus) {
            case 'synced':
                $statusColor = 'green';
                $statusTooltip = 'محصول با باسلام همگام است.';

                break;
            case 'pending':
                $statusColor = 'yellow';
                $statusTooltip = 'محصول در حال اجرای عملیات است.';

                break;
            default:
                $statusColor = 'red';
                $statusTooltip = 'محصول با باسلام همگام نیست.';

                break;
        }

        add_meta_box(
            'sync_basalam_single_product_manage_box',
            'تنظیمات باسلام <span title="' . esc_attr($statusTooltip) . '" style="background:' . esc_attr($statusColor) . ';width: 16px;height: 16px;border-radius: 100%;">
                <img src="' . esc_url(syncBasalamPlugin()->assetsUrl() . "/icons/info.svg") . '" alt="" class="basalam-img-15">
                </span>',
            [self::class, 'renderMetaBox'],
            'product',
            'side',
            'high'
        );
    }

    public static function renderMetaBox($post)
    {
        $productId = $post->ID;
        $productStatus = get_post_status($productId);

        $basalamProductStatus = get_post_meta($productId, 'sync_basalam_product_status', true);
        $basalamProductSyncStatus = get_post_meta($productId, 'sync_basalam_product_sync_status', true);

        $BasalamAccessToken = syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);
        $syncBasalamVendorId = syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);

        if ($productStatus == 'publish') {
            if ($basalamProductSyncStatus == 'pending') {
                echo '<p class="basalam-p basalam-font-12">یک عملیات برای این محصول در جریان است.</p>';
            } elseif (!$BasalamAccessToken || !$syncBasalamVendorId) {
                echo '<p class="basalam-p basalam-font-12">دسترسی های لازم دریافت نشده است ، ابتدا دسترسی ها را <a href="/wp-admin/admin.php?page=sync_basalam" target="_blank">دریافت</a> نمایید.</p>';
            } else {
                if ($basalamProductStatus) {
                    $syncBasalamProductId = get_post_meta($productId, 'sync_basalam_product_id', true);
                    CommonComponents::renderBtn('بروزسانی محصول در باسلام', 'update_product_in_basalam', $post->ID, 'update_product_in_basalam_nonce');
                    if ($basalamProductStatus == 2976) {
                        CommonComponents::renderBtn('آرشیو کردن محصول در باسلام', 'archive_exist_product_on_basalam', $post->ID, 'archive_exist_product_on_basalam_nonce');
                    } else {
                        CommonComponents::renderBtn('بازگردانی محصول در باسلام', 'restore_exist_product_on_basalam', $post->ID, 'restore_exist_product_on_basalam_nonce');
                    }
                    $link = "https://basalam.com/p/" . $syncBasalamProductId;
                    CommonComponents::renderLink('مشاهده محصول در باسلام', $link);
                    CommonComponents::renderBtn('قطع اتصال محصول', 'disconnect_exist_product_on_basalam', $post->ID, 'disconnect_exist_product_on_basalam_nonce');
                } else {
                    CommonComponents::renderBtn('اضافه کردن محصول در باسلام', 'create_product_basalam', $post->ID, 'create_product_basalam_nonce');
                    require_once syncBasalamPlugin()->templatePath("products/ConnectButton.php");
                }
            }
        } else {
            echo '<p class="basalam-p basalam-font-12">برای دسترسی به تنظیمات باسلام ، وضعیت محصول را به "منتشر شده" تغییر دهید.</p>';
        }
        $nonceGetBasalamCategoryIdsAction = 'basalam_get_category_ids_nonce';
        $nonceGetBasalamCategoryIdValue = wp_create_nonce($nonceGetBasalamCategoryIdsAction);
        echo '<hr>';
        echo '<div>
            <button type="button" id="basalam_fetch_categories_btn" class="basalam-p basalam-button-full-primary" style="padding:4px !important;">
                دریافت دسته‌بندی‌های پیشنهادی باسلام
            </button>
            <input type="hidden"  id="basalam_get_category_ids_nonce" value="' . esc_attr($nonceGetBasalamCategoryIdValue) . '">
        </div>
        <div id="sync_basalam_category_id" class="basalam-p__small basalam--hidden">
            </div>';

        $nonceGetBasalamCategoryAttrAction = 'basalam_get_category_attrs_nonce';
        $nonceGetBasalamCategoryAttrValue = wp_create_nonce($nonceGetBasalamCategoryAttrAction);

        echo '<div id="sync_basalam_category_attributes" class="basalam-p__small basalam--hidden">
            <input type="hidden"  id="basalam_get_category_attrs_nonce" value="' . esc_attr($nonceGetBasalamCategoryAttrValue) . '">
            </div>';
    }
}
