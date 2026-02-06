<?php
defined('ABSPATH') || exit;

use SyncBasalam\Admin\Components;

?>
<div class="basalam-action-card basalam-relative">
    <div class="basalam-info-icon basalam-info-icon-small">
        <a href="https://www.aparat.com/v/qbf0kw7?playlist=20857018" target="_blank">
            <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/icons/info-black.svg"); ?>" alt="اطلاعات" class="basalam-product-image-size">
        </a>
    </div>

    <h3 class="basalam-h">سفارشات</h3>
    <p class="basalam-p basalam-p__small">مدیریت سفارشات دریافتی از باسلام</p>
    <div class="basalam-action-buttons basalam-action-buttons-col">

        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form basalam-form-margin-0">
            <input type="hidden" name="action" value="basalam_update_setting">
            <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
            <?php Components::syncStatusOrder(); ?>
            <?php if ($syncStatusOrder == true): ?>
                <button type="submit" class="basalam-danger-button basalam-p basalam-width-fill-available">
                    <img class="basalam-btn-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/unsync.svg'); ?>">
                    توقف همگام‌سازی سفارشات
                </button>
            <?php else: ?>
                <button type="submit" class="basalam-primary-button basalam-p basalam-width-fill-available">
                    <img class="basalam-btn-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/sync.svg'); ?>">
                    همگام‌سازی سفارشات
                </button>
            <?php endif; ?>
        </form>

        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form basalam-form-margin-0">
            <input type="hidden" name="action" value="auto_confirm_order_in_basalam">
            <?php wp_nonce_field('auto_confirm_order_in_basalam_nonce', '_wpnonce'); ?>
            <?php Components::renderAutoConfirmOrderButton(); ?>
            <?php if ($autoConfirmOrder == true): ?>
                <button type="submit" class="basalam-danger-button basalam-p basalam-width-fill-available">
                    <img class="basalam-btn-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/unsync.svg'); ?>">
                    توقف تایید اتوماتیک سفارشات باسلام
                </button>
            <?php else: ?>
                <button type="submit" class="basalam-primary-button basalam-p basalam-width-fill-available">
                    <img class="basalam-btn-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/order.svg'); ?>">
                    تایید اتوماتیک سفارشات باسلام
                </button>
            <?php endif; ?>
        </form>
    </div>
</div>