<?php

if (! defined('ABSPATH')) exit;

/** @var array $data */

/**
 * غرفه غیرفعال (16) — فعال‌سازی مجدد واقعی: POST /v1/shops/{id}/activate (فقط 16 → 14).
 * shop_id روی خود دکمه است (data-shop-id) — رفع ایراد selector پارت ۱.
 */
$shops = is_array($data['shops'] ?? null) ? $data['shops'] : [];
$inactive = array_values(array_filter($shops, static function (array $shop): bool {
    return (int) ($shop['status_id'] ?? 0) === \SyncBasalam\SalamGate\Contracts\ShopStatuses::INACTIVE;
}));
?>
<div class="sg-state sg-state--inactive" data-state="inactive">
    <div class="sg-card">
        <h3 class="sg-card__title">حساب سلام‌گیت غیرفعال است</h3>
        <p class="sg-card__text">
            ثبت شما تأیید شده اما حساب فعال نیست. با فعال‌سازی مجدد، ارسال مرسوله دوباره امکان‌پذیر می‌شود.
        </p>
        <?php foreach ($inactive as $shop) : ?>
            <div class="sg-shop-row">
                <span class="sg-shop-row__id"><?php echo esc_html((string) ($shop['identifier'] ?? ('#' . ($shop['id'] ?? '')))); ?></span>
                <button type="button"
                        class="sg-button sg-button--primary salam-gate-activate"
                        data-shop-id="<?php echo esc_attr((string) ($shop['id'] ?? '')); ?>">
                    فعال‌سازی مجدد
                </button>
            </div>
        <?php endforeach; ?>
    </div>
</div>
