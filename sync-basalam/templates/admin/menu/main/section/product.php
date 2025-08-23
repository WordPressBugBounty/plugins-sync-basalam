<?php
if (! defined('ABSPATH')) exit;
$nonce = wp_create_nonce('sync_basalam_filter_action');

?>
<div class="basalam-action-card" style="position: relative;">
    <div class="basalam-info-icon" style="position: absolute;top: 10px;left: 10px;border: 1px solid;border-radius: 70%;width: 22px;height: 22px;">
        <a href="https://www.aparat.com/playlist/20965637" target="_blank">
            <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . "/icons/info-black.svg"); ?>" alt="اطلاعات" style="width: 22px; height: 20px; cursor: pointer;">
        </a>
    </div>

    <h3 class="basalam-h">محصولات</h3>
    <p class="basalam-p basalam-p__small">مدیریت محصولات و همگام‌سازی آنها با باسلام</p>

    <div style="display: flex; flex-direction: column; justify-content: space-between; align-items: center; height: 90%;">
        <div class="basalam-action-buttons" style="flex-direction: column;">
            <button type="button" id="basalamOpenAddProductsModal" class="basalam-secondary-button basalam-p">
                <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/new.svg'); ?>">
                اضافه کردن همه محصولات به باسلام
            </button>

            <button type="button" id="BasalamOpenUpdateProductsModal" class="basalam-secondary-button basalam-p">
                <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/update.svg'); ?>">
                بروزرسانی همه محصولات باسلامی
            </button>

            <button type="button" id="BasalamOpenConnectProductsModal" class="basalam-secondary-button basalam-p">
                <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/product.svg'); ?>">
                اتصال محصولات موجود غرفه به سایت </button>
        </div>

        <div style="display: flex; gap: 10px;">

            <a target="_blank" href="/wp-admin/edit.php?s&post_status=all&post_type=product&sync_basalam_not_added=1&_sync_basalam_filter_nonce=<?php echo esc_attr($nonce); ?>" class="basalam-secondary-button basalam-p" style="padding: 7px 5px; font-weight: bold;text-align:center;">
                محصولات سینک نشده ووکامرس
            </a>

            <a target="_blank" href="/wp-admin/admin.php?page=basalam-show-products" class="basalam-secondary-button basalam-p" style="padding: 7px 5px; font-weight: bold;text-align:center;">
                محصولات سینک نشده باسلام
            </a>
        </div>
    </div>
</div>