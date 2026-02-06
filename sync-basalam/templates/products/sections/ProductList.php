<?php defined('ABSPATH') || exit; ?>
<div class="basalam-action-card basalam-relative">
    <div class="basalam-info-icon basalam-info-icon-small">
        <a href="https://www.aparat.com/playlist/20965637" target="_blank">
            <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/icons/info-black.svg"); ?>" alt="اطلاعات" class="basalam-product-image-size">
        </a>
    </div>

    <h3 class="basalam-h">محصولات</h3>
    <p class="basalam-p basalam-p__small">مدیریت محصولات و همگام‌سازی آنها با باسلام</p>

    <div class="basalam-flex-fill">
        <div class="basalam-action-buttons basalam-action-buttons-col">
            <button type="button" id="basalamOpenAddProductsModal" class="basalam-secondary-button basalam-p">
                <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/new.svg'); ?>">
                اضافه کردن همه محصولات به باسلام
            </button>

            <button type="button" id="BasalamOpenUpdateProductsModal" class="basalam-secondary-button basalam-p">
                <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/update.svg'); ?>">
                بروزرسانی همه محصولات باسلامی
            </button>

            <button type="button" id="BasalamOpenConnectProductsModal" class="basalam-secondary-button basalam-p">
                <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/product.svg'); ?>">
                اتصال محصولات موجود غرفه به سایت </button>
        </div>

        <div class="basalam-flex basalam-gap-10" style="margin-bottom: 20px;">

            <a target="_blank" href="/wp-admin/edit.php?s&post_status=all&post_type=product&sync_basalam_not_added=1&_sync_basalam_filter_nonce=<?php echo esc_attr(wp_create_nonce('sync_basalam_filter_action')); ?>" class="basalam-secondary-button basalam-p basalam-padding-small">
                محصولات سینک نشده ووکامرس
            </a>

            <a target="_blank" href="/wp-admin/admin.php?page=basalam-show-products" class="basalam-secondary-button basalam-p basalam-padding-small">
                محصولات سینک نشده باسلام
            </a>
        </div>
    </div>
</div>