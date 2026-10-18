<?php

defined('ABSPATH') || exit;

/**
 * Decorative preview only: never include authenticated templates or real data
 * behind this overlay. Server-side API permissions remain the security boundary.
 * @var array{surface?: string, state?: string} $connectionLock
 */
$lockSurface = ($connectionLock['surface'] ?? '') === 'woosalam' ? 'woosalam' : 'salam-gate';
$lockState = in_array($connectionLock['state'] ?? '', ['error_auth', 'error_forbidden'], true)
    ? $connectionLock['state'] : 'not_connected';
$lockHeadingId = 'sg-connection-lock-' . $lockSurface . '-title';
$lockMessageId = 'sg-connection-lock-' . $lockSurface . '-message';
$lockCopy = [
    'not_connected' => [
        'title' => 'ابتدا به باسلام متصل شوید',
        'message' => 'برای دسترسی به این بخش، ابتدا اتصال ووسلام به حساب باسلام خود را برقرار کنید.',
        'button' => 'رفتن به صفحه ووسلام و اتصال',
    ],
    'error_auth' => [
        'title' => 'اتصال شما نیاز به بررسی دارد',
        'message' => 'احراز هویت اتصال فعلی تأیید نشد. برای ادامه، اتصال حساب باسلام خود را دوباره بررسی کنید.',
        'button' => 'اتصال مجدد از صفحه ووسلام',
    ],
    'error_forbidden' => [
        'title' => 'مجوز دسترسی به سلام‌گیت کافی نیست',
        'message' => 'دسترسی این عملیات تأیید نشد. مجوزهای خواندن و ثبت مرسوله را با اتصال مجدد تأیید کنید. اگر برنامه این مجوزها را نداد یا مشکل ادامه داشت، با پشتیبانی سلام‌گیت تماس بگیرید.',
        'button' => 'تأیید مجوزهای سلام‌گیت',
    ],
];
$lockTitle = $lockCopy[$lockState]['title'];
$lockMessage = $lockCopy[$lockState]['message'];
$lockSubmitLabel = $lockState === 'error_forbidden'
    ? $lockCopy[$lockState]['button']
    : ($lockState === 'error_auth' ? 'اتصال مجدد به باسلام' : 'اتصال به باسلام');
$previewLabels = $lockSurface === 'woosalam'
    ? ['مدیریت محصولات', 'مدیریت سفارش‌ها', 'تنظیمات فروشگاه']
    : ['ارسال سفارش‌های فروشگاه', 'گزارش مرسوله‌ها', 'پیگیری ارسال'];
?>
<section class="sg-connection-lock" data-sg-connection-lock data-connection-state="<?php echo esc_attr($lockState); ?>" data-connection-surface="<?php echo esc_attr($lockSurface); ?>" aria-labelledby="<?php echo esc_attr($lockHeadingId); ?>" aria-describedby="<?php echo esc_attr($lockMessageId); ?>">
    <div class="sg-connection-lock__preview" aria-hidden="true" inert>
        <div class="sg-connection-lock__preview-header"><span></span><span></span></div>
        <div class="sg-connection-lock__preview-cards">
            <?php foreach ($previewLabels as $previewLabel) : ?>
                <div class="sg-connection-lock__preview-card">
                    <span class="sg-connection-lock__preview-icon"></span>
                    <span class="sg-connection-lock__preview-label"><?php echo esc_html($previewLabel); ?></span>
                    <span class="sg-connection-lock__preview-line"></span>
                    <span class="sg-connection-lock__preview-line sg-connection-lock__preview-line--short"></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="sg-connection-lock__overlay">
        <div class="sg-connection-lock__panel">
            <span class="sg-connection-lock__icon dashicons dashicons-lock" aria-hidden="true"></span>
            <h3 class="sg-connection-lock__title" id="<?php echo esc_attr($lockHeadingId); ?>"><?php echo esc_html($lockTitle); ?></h3>
            <p class="sg-connection-lock__message" id="<?php echo esc_attr($lockMessageId); ?>"><?php echo esc_html($lockMessage); ?></p>
            <?php if ($lockSurface === 'woosalam' || $lockState === 'error_forbidden') : ?>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="sg-connection-lock__action">
                    <input type="hidden" name="action" value="basalam_update_setting">
                    <input type="hidden" name="get_token" value="1">
                    <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
                    <button type="submit" class="sg-connection-lock__button"><?php echo esc_html($lockSubmitLabel); ?></button>
                </form>
            <?php else : ?>
                <a class="sg-connection-lock__button" href="<?php echo esc_url(admin_url('admin.php?page=sync_basalam')); ?>"><?php echo esc_html($lockCopy[$lockState]['button']); ?></a>
            <?php endif; ?>
        </div>
    </div>
</section>
