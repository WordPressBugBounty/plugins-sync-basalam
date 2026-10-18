<?php

if (! defined('ABSPATH')) exit;

/** @var array $data */

/**
 * خطای ارتباط: وضعیت واقعی حساب نامعلوم است — کاربر تازه‌وارد فرض نمی‌شود
 * و فرم ثبت/معرفی فعال‌سازی در این حالت نشان داده نمی‌شود.
 */
?>
<div class="sg-state sg-state--error-network" data-state="error_network">
    <div class="sg-card sg-card--error">
        <h3 class="sg-card__title">وضعیت سلام‌گیت در دسترس نیست</h3>
        <p class="sg-card__text">
            ارتباط با سلام‌گیت برقرار نشد؛ وضعیت واقعی حساب شما نامعلوم است و فرم ثبت تکراری نمایش داده نمی‌شود.
            چند لحظه بعد صفحه را دوباره بارگذاری کنید یا از «تلاش مجدد» استفاده کنید.
        </p>
        <?php if (!empty($data['message'])) : ?>
            <p class="sg-card__detail" dir="auto"><?php echo esc_html($data['message']); ?></p>
        <?php endif; ?>
        <button type="button" class="sg-button sg-button--secondary" data-sg-refresh>تلاش مجدد</button>
    </div>
</div>
