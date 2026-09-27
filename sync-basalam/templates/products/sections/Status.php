<?php

use SyncBasalam\Admin\Components\SettingPageComponents;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;
$scheduledInterval = (int) ($settings[SettingsConfig::AUTO_FULL_UPDATE_INTERVAL] ?? 48);
?>
<div id="sync-basalam-onboarding-status" class="basalam-status-card">
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
        <?php if ($vendorSyncMode === VendorSyncPolicy::MODE_INACTIVE_LIMITED): ?>
            <span class="basalam-badge basalam-badge-warning basalam-p">غرفه غیرفعال — همگام‌سازی محدود</span>
        <?php elseif ($vendorSyncMode === VendorSyncPolicy::MODE_INACTIVE_SUSPENDED): ?>
            <span class="basalam-badge basalam-badge-danger basalam-p">غرفه غیرفعال — همگام‌سازی متوقف</span>
        <?php else: ?>
            <span class="basalam-badge basalam-badge-success basalam-p">متصل</span>
        <?php endif; ?>
    </div>
    <div class="basalam-sync-status">
        <div class="basalam-sync-intro">
            <p class="basalam-p basalam-status-info">با فعال کردن همگام‌سازی خودکار، تغییرات محصولات شما به صورت خودکار در باسلام نیز اعمال می‌شود.</p>
            <div class="basalam-info-icon basalam-info-icon-small">
                <a href="https://www.aparat.com/v/vja08ql" target="_blank" rel="noopener noreferrer">
                    <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/icons/info-black.svg"); ?>" alt="اطلاعات" class="basalam-img-22 basalam-cursor-pointer">
                </a>
            </div>
        </div>
        <div class="basalam-sync-controls">
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form basalam-form-margin-0">
                <input type="hidden" name="action" value="basalam_update_setting">
                <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
                <?php SettingPageComponents::syncStatusProduct(); ?>
                <?php if ($syncStatusProduct == true): ?>
                    <button type="submit" class="basalam-danger-button basalam-p">
                        <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/unsync.svg'); ?>" alt="">
                        توقف همگام‌سازی محصولات
                    </button>
                <?php else: ?>
                    <button type="submit" class="basalam-primary-button basalam-p basalam-btn-small">
                        <img class="basalam-img-20" src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/sync.svg'); ?>" alt="">
                        همگام‌سازی محصولات
                    </button>
                <?php endif; ?>
            </form>

            <details class="basalam-schedule-control">
                <summary class="basalam-schedule-trigger basalam-p" aria-label="تنظیم به‌روزرسانی دوره‌ای محصولات">
                    <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/update.svg'); ?>" alt="" width="18" height="18">
                    <span>زمان‌بندی</span>
                    <?php if ($scheduledInterval): ?>
                        <span class="basalam-schedule-badge">هر <?php echo esc_html($scheduledInterval === 24 ? '۲۴' : '۴۸'); ?> ساعت</span>
                    <?php else: ?>
                        <span class="basalam-schedule-badge basalam-schedule-badge-off">خاموش</span>
                    <?php endif; ?>
                </summary>
                <div class="basalam-schedule-panel">
                    <h3 class="basalam-p">به‌روزرسانی دوره‌ای همه محصولات</h3>
                    <p class="basalam-p">محصولات متصل به باسلام، در بازه انتخابی دوباره به‌روزرسانی می‌شوند. اولین اجرا پس از گذشت این بازه است.</p>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                        <input type="hidden" name="action" value="basalam_update_setting">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=sync_basalam')); ?>">
                        <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
                        <label class="basalam-schedule-option">
                            <input type="radio" name="sync_basalam_settings[<?php echo esc_attr(SettingsConfig::AUTO_FULL_UPDATE_INTERVAL); ?>]" value="24" <?php checked($scheduledInterval, 24); ?> required>
                            <span><strong>هر ۲۴ ساعت</strong><small>به‌روزرسانی روزانه</small></span>
                        </label>
                        <label class="basalam-schedule-option">
                            <input type="radio" name="sync_basalam_settings[<?php echo esc_attr(SettingsConfig::AUTO_FULL_UPDATE_INTERVAL); ?>]" value="48" <?php checked($scheduledInterval, 48); ?>>
                            <span><strong>هر ۴۸ ساعت</strong><small>یک روز در میان</small></span>
                        </label>
                        <button type="submit" class="basalam-schedule-save basalam-p">ذخیره زمان‌بندی</button>
                    </form>
                    <?php if ($scheduledInterval): ?>
                        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="basalam-schedule-disable-form">
                            <input type="hidden" name="action" value="basalam_update_setting">
                            <input type="hidden" name="redirect_to" value="<?php echo esc_url(admin_url('admin.php?page=sync_basalam')); ?>">
                            <input type="hidden" name="sync_basalam_settings[<?php echo esc_attr(SettingsConfig::AUTO_FULL_UPDATE_INTERVAL); ?>]" value="0">
                            <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
                            <button type="submit" class="basalam-schedule-disable basalam-p">خاموش کردن به‌روزرسانی دوره‌ای</button>
                        </form>
                    <?php endif; ?>
                </div>
            </details>

        </div>
    </div>
</div>
