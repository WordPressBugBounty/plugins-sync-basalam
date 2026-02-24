<?php

namespace SyncBasalam\Admin\Components;

defined('ABSPATH') || exit;

class OrderPageComponents
{
    public static function renderCheckOrdersButton()
    {
        $jobManager = \SyncBasalam\JobManager::getInstance();
        $hasRunningJob = $jobManager->getCountJobs([
            'job_type' => 'sync_basalam_fetch_orders',
            'status' => ['pending', 'processing']
        ]) > 0;

        $nonce = wp_create_nonce('add_unsync_orders_from_basalam_nonce');
?>
        <div class="alignleft actions custom basalam-orders-fetch-wrapper">
            <?php if ($hasRunningJob) : ?>
                <div class="basalam-orders-btn-group basalam-orders-running">
                    <button type="button" class="basalam-button basalam-p basalam-height-32 basalam-button-disabled" style="padding: 2px !important;" disabled title="سفارشات در حال همگام سازی هستند.">
                        <span class="basalam-btn-text">در حال همگام‌سازی سفارشات...</span>
                    </button>
                    <button type="button" class="basalam-button basalam-p basalam-height-32 basalam-cancel-orders-btn"
                        title="لغو همگام‌سازی سفارشات"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('cancel_fetch_orders_nonce')); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            <?php else : ?>
                <div class="basalam-orders-btn-group">
                    <button type="button" class="basalam-button basalam-p basalam-height-32 basalam-fetch-orders-btn"
                        title="همگام‌سازی سفارشات ۷ روز اخیر"
                        data-nonce="<?php echo esc_attr($nonce); ?>">
                        <span class="basalam-btn-text">بررسی سفارشات باسلام</span>
                        <span class="basalam-btn-separator"></span>
                    </button>
                    <button type="button" class="basalam-button basalam-p basalam-height-32 basalam-dropdown-arrow-btn"
                        title="انتخاب تعداد روزها">
                        <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/arrow.svg'); ?>" alt="▼" class="basalam-dropdown-arrow-img">
                    </button>
                </div>
                <div class="basalam-orders-fetch-dropdown" style="display: none;">
                    <div class="basalam-dropdown-content">
                        <div class="basalam-dropdown-label-row">
                            <?php echo CommonComponents::renderLabelWithTooltip('تعداد روزها (۱ تا ۳۰)', 'سفارشات باسلام که در ووکامرس ثبت نشده باشند در روزهای مشخص شده به ووکامرس اضافه خواهند شد.'); ?>
                        </div>
                        <input type="number" class="basalam-dropdown-input basalam-input" id="basalam-orders-fetch-days" min="1" max="30" value="7">
                        <button type="button" class="basalam-primary-button basalam-p basalam-dropdown-submit" data-nonce="<?php echo esc_attr($nonce); ?>">
                            بررسی سفارشات
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php
    }

    public static function renderCheckOrdersButtonTraditional()
    {
        $screen = get_current_screen();

        // Only show on shop order list page when HPOS is not enabled
        if (!$screen || $screen->id !== 'edit-shop_order') {
            return;
        }

        // Check if HPOS is enabled - skip if it is
        if (
            function_exists('woocommerce_custom_orders_table_usage_is_enabled') &&
            woocommerce_custom_orders_table_usage_is_enabled()
        ) {
            return;
        }

        // Check post type to ensure we're on the shop order page
        if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'shop_order') {
            return;
        }

        $jobManager = \SyncBasalam\JobManager::getInstance();
        $hasRunningJob = $jobManager->getCountJobs([
            'job_type' => 'sync_basalam_fetch_orders',
            'status' => ['pending', 'processing']
        ]) > 0;

        $nonce = wp_create_nonce('add_unsync_orders_from_basalam_nonce');
    ?>
        <div class="alignleft actions custom basalam-orders-fetch-wrapper">
            <?php if ($hasRunningJob) : ?>
                <div class="basalam-orders-btn-group basalam-orders-running">
                    <button type="button" class="basalam-button basalam-p basalam-height-32 basalam-button-disabled" disabled>
                        <span class="basalam-btn-text">همگام‌سازی سفارشات</span>
                    </button>
                    <button type="button" class="basalam-button basalam-p basalam-height-32 basalam-cancel-orders-btn"
                        title="لغو همگام‌سازی سفارشات"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('cancel_fetch_orders_nonce')); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            <?php else : ?>
                <div class="basalam-orders-btn-group">
                    <button type="button" class="basalam-button basalam-p basalam-height-32 basalam-fetch-orders-btn"
                        title="همگام‌سازی سفارشات ۷ روز اخیر"
                        data-nonce="<?php echo esc_attr($nonce); ?>">
                        <span class="basalam-btn-text">بررسی سفارشات باسلام</span>
                        <span class="basalam-btn-separator"></span>
                    </button>
                    <button type="button" class="basalam-button basalam-p basalam-height-32 basalam-dropdown-arrow-btn"
                        title="انتخاب تعداد روزها">
                        <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/arrow.svg'); ?>" alt="▼" class="basalam-dropdown-arrow-img">
                    </button>
                </div>
                <div class="basalam-orders-fetch-dropdown" style="display: none;">
                    <div class="basalam-dropdown-content">
                        <div class="basalam-dropdown-label-row">
                            <?php echo CommonComponents::renderLabelWithTooltip('تعداد روزها (۱ تا ۳۰)', 'سفارشات باسلام که در ووکامرس ثبت نشده باشند در روزهای مشخص شده به ووکامرس اضافه خواهند شد.'); ?>
                        </div>
                        <input type="number" class="basalam-dropdown-input basalam-input" id="basalam-orders-fetch-days" min="1" max="30" value="7" required>
                        <button type="button" class="basalam-primary-button basalam-p basalam-dropdown-submit" data-nonce="<?php echo esc_attr($nonce); ?>">
                            بررسی سفارشات
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
<?php
    }
}
