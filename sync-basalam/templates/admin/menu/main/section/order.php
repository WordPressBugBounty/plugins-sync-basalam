<?php
if (! defined('ABSPATH')) exit;
?>
<div class="basalam-action-card" style="position: relative;">
    <div class="basalam-info-icon" style="position: absolute;top: 10px;left: 10px;border: 1px solid;border-radius: 70%;width: 22px;height: 22px;">
        <a href="https://www.aparat.com/v/qbf0kw7?playlist=20857018" target="_blank">
            <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . "/icons/info-black.svg"); ?>" alt="اطلاعات" style="width: 20px; height: 20px; cursor: pointer;">
        </a>
    </div>

    <h3 class="basalam-h">سفارشات</h3>
    <p class="basalam-p basalam-p__small">مدیریت سفارشات دریافتی از باسلام</p>
    <div class="basalam-action-buttons" style="flex-direction: column;">

        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form" style="margin: 0;">
            <input type="hidden" name="action" value="basalam_update_setting">
            <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
            <?php sync_basalam_Admin_UI::sync_status_order(); ?>
            <?php if ($sync_status_order == true): ?>
                <button type="submit" class="basalam-danger-button basalam-p" style="width: -webkit-fill-available;">
                    <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/unsync.svg'); ?>">
                    توقف همگام‌سازی سفارشات
                </button>
            <?php else: ?>
                <button type="submit" class="basalam-primary-button basalam-p" style="width: -webkit-fill-available;">
                    <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/sync.svg'); ?>">
                    همگام‌سازی سفارشات
                </button>
            <?php endif; ?>
        </form>

        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form" style="margin: 0;">
            <input type="hidden" name="action" value="basalam_update_setting">
            <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
            <?php sync_basalam_Admin_UI::render_auto_confirm_order_button(); ?>
            <?php if ($auto_confirm_order == true): ?>
                <button type="submit" class="basalam-danger-button basalam-p" style="width: -webkit-fill-available;">
                    <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/unsync.svg'); ?>">
                    توقف تایید اتوماتیک سفارشات باسلام
                </button>
            <?php else: ?>
                <button type="submit" class="basalam-primary-button basalam-p" style="width: -webkit-fill-available;">
                    <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/order.svg'); ?>">
                    تایید اتوماتیک سفارشات باسلام
                </button>
            <?php endif; ?>
        </form>
    </div>
</div>