<?php

if (! defined('ABSPATH')) exit;

/** @var array $data */

/** @var array<int, array> $data['shops'] غرفه‌های رد شده */
$shops = is_array($data['shops'] ?? null) ? $data['shops'] : [];
?>
<div class="sg-state sg-state--rejected" data-state="rejected">
    <div class="sg-card sg-card--error">
        <h3 class="sg-card__title">درخواست سلام‌گیت تأیید نشد</h3>
        <p class="sg-card__text">
            ثبت این حساب توسط باسلام رد شده است. برای پیگیری و رفع مشکل با پشتیبانی همسلام در ارتباط باشید.
        </p>
        <?php foreach ($shops as $shop) : ?>
            <?php if ((int) ($shop['status_id'] ?? 0) === \SyncBasalam\SalamGate\Contracts\ShopStatuses::REJECTED) : ?>
                <p class="sg-card__detail">
                    غرفه <?php echo esc_html((string) ($shop['identifier'] ?? '—')); ?>
                    — <?php echo esc_html((string) ($shop['status_title'] ?? 'رد شده')); ?>
                </p>
            <?php endif; ?>
        <?php endforeach; ?>
        <div class="sg-card__actions">
            <a class="sg-button sg-button--secondary" href="https://hamsalam.ir" target="_blank" rel="noopener noreferrer">ارتباط با پشتیبانی همسلام</a>
        </div>
    </div>
</div>
