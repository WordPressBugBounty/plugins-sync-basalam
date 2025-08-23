<?php
if (! defined('ABSPATH')) exit;
?>
<div class="basalam-status-card">
    <div class="basalam-status-header">
        <h2 class="basalam-h">وضعیت اتصال</h2>
        <div class="basalam_status_data_container">
            <div class="basalam_status_data_item">
                <p class="basalam-p" style='font-size: 12px;text-align:justify;'>تعداد محصولات منتشر شده ووکامرس :</p> <?php echo '<p class="basalam_status_data_number basalam-p">' . esc_html($count_of_published_woocamerce_products) . '</p>' ?>
            </div>
            <div class="basalam_status_data_item">
                <p class="basalam-p" style='font-size: 12px;text-align:justify;'>تعداد محصولات سینک شده با باسلام :</p> <?php echo '<p class="basalam_status_data_number basalam-p">' . esc_html($count_of_synced_basalam_products) . '</p>' ?>
            </div>
        </div>
        <span class="basalam-badge basalam-badge-success basalam-p">متصل</span>
    </div>
    <div class="basalam-sync-status">
        <p class="basalam-p" style="padding:0;font-size:15px">با فعال کردن همگام‌سازی خودکار، تغییرات محصولات شما به صورت خودکار در باسلام نیز اعمال می‌شود.</p>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form" style="margin: 0;">
            <input type="hidden" name="action" value="basalam_update_setting">
            <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
            <?php sync_basalam_Admin_UI::sync_status_product(); ?>
            <?php if ($sync_status_product == true): ?>
                <button type="submit" class="basalam-danger-button basalam-p">
                    <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/unsync.svg'); ?>">
                    توقف همگام‌سازی محصولات
                </button>
            <?php else: ?>
                <button type="submit" class="basalam-primary-button basalam-p">
                    <img style="width: 20px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/sync.svg'); ?>">
                    همگام‌سازی محصولات
                </button>
            <?php endif; ?>
        </form>
        <div class="basalam-info-icon" style="border: 1px solid;border-radius: 70%;width: 22px;height: 22px;">
            <a href="https://www.aparat.com/v/vja08ql" target="_blank">
                <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . "/icons/info-black.svg"); ?>" alt="اطلاعات" style="width: 22px; height: 20px; cursor: pointer;">
            </a>
        </div>

    </div>
</div>