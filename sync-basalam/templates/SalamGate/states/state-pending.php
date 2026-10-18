<?php

if (! defined('ABSPATH')) exit;

/** @var array $data */

/** @var array<int, array> $data['shops'] غرفه‌های در انتظار تأیید */
$shops = is_array($data['shops'] ?? null) ? $data['shops'] : [];
$registrationMessage = $shops === [] && is_string($data['message'] ?? null) ? $data['message'] : '';
?>
<div class="sg-state sg-state--pending" data-state="pending">
    <div class="sg-card">
        <h3 class="sg-card__title"><?php echo esc_html($registrationMessage !== '' ? 'نتیجه فعال‌سازی در حال بررسی است' : 'حساب سلام‌گیت در انتظار تأیید است'); ?></h3>
        <p class="sg-card__text">
            <?php if ($registrationMessage !== '') : ?>
                <?php echo esc_html($registrationMessage); ?>
            <?php else : ?>
            آخرین وضعیت دریافت‌شده از سلام‌گیت «در انتظار تأیید» است. این وضعیت ممکن است پس از ثبت یا ویرایش اطلاعات غرفه نمایش داده شود.
            اگر حساب در پنل باسلام فعال شده است، با دکمه زیر آخرین وضعیت را دوباره دریافت کنید؛ نیازی به ثبت مجدد غرفه نیست.
            <?php endif; ?>
        </p>
        <?php foreach ($shops as $shop) : ?>
            <?php if ((int) ($shop['status_id'] ?? 0) === \SyncBasalam\SalamGate\Contracts\ShopStatuses::PENDING_APPROVAL) : ?>
                <p class="sg-card__detail">
                    غرفه <?php echo esc_html((string) ($shop['identifier'] ?? '—')); ?>
                    — <?php echo esc_html((string) ($shop['status_title'] ?? 'در انتظار تأیید')); ?>
                </p>
            <?php endif; ?>
        <?php endforeach; ?>
        <div class="sg-card__actions">
            <button type="button" class="sg-button sg-button--primary sg-refresh-state" data-sg-refresh aria-controls="sg-state-container">
                <span class="sg-loading-spinner sg-refresh-state__spinner" aria-hidden="true" style="display:none;"></span>
                بررسی مجدد وضعیت
            </button>
        </div>
        <?php if ($registrationMessage !== '') : ?>
            <p class="sg-field__hint">اگر نتیجه همچنان مشخص نشد، پیش از ثبت مجدد با <a href="https://hamsalam.ir" target="_blank" rel="noopener noreferrer">پشتیبانی همسلام</a> هماهنگ کنید.</p>
        <?php endif; ?>
    </div>
</div>
