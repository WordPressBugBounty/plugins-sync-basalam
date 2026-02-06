<?php

use SyncBasalam\Admin\Components;

defined('ABSPATH') || exit;
?>
<div class="basalam-status-card">
    <div class="basalam-status-header">
        <h2 class="basalam-h">وضعیت اتصال</h2>
        <div class="basalam_status_data_container">
            <div class="basalam_status_data_item">
                <p class="basalam-p basalam-font-12 basalam-text-justify">محصولات منتشر شـده ووکامرس :</p> <?php echo '<p class="basalam_status_data_number basalam-p">' . esc_html($count_of_published_woocommerce_products) . '</p>' ?>
            </div>
            <div class="basalam_status_data_item">
                <p class="basalam-p basalam-font-12 basalam-text-justify">مـحصولات سیـنک شــده با باســلام :</p> <?php echo '<p class="basalam_status_data_number basalam-p">' . esc_html($count_of_synced_basalam_products) . '</p>' ?>
            </div>
        </div>
        <span class="basalam-badge basalam-badge-success basalam-p">متصل</span>
    </div>
    <div class="basalam-sync-status">
        <p class="basalam-p basalam-status-info">با فعال کردن همگام‌سازی خودکار، تغییرات محصولات شما به صورت خودکار در باسلام نیز اعمال می‌شود.</p>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form basalam-form-margin-0">
            <input type="hidden" name="action" value="basalam_update_setting">
            <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
            <?php Components::syncStatusProduct(); ?>
            <?php if ($syncStatusProduct == true): ?>
                <button type="submit" class="basalam-danger-button basalam-p">
                    <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/unsync.svg'); ?>">
                    توقف همگام‌سازی محصولات
                </button>
            <?php else: ?>
                <button type="submit" class="basalam-primary-button basalam-p basalam-btn-small">
                    <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/sync.svg'); ?>">
                    همگام‌سازی محصولات
                </button>
            <?php endif; ?>
        </form>
        <div class="basalam-info-icon basalam-info-icon-small">
            <a href="https://www.aparat.com/v/vja08ql" target="_blank">
                <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/icons/info-black.svg"); ?>" alt="اطلاعات" class="basalam-img-22 basalam-cursor-pointer">
            </a>
        </div>

    </div>
</div>