<?php

namespace SyncBasalam\Admin\Product\elements\SingleProduct;

use SyncBasalam\Utilities\PriceAdjustment;

defined('ABSPATH') || exit;

class PriceChangeField
{
    public const META_KEY = '_sync_basalam_price_change_value';

    public static function renderField()
    {
        $value = (string) get_post_meta(get_the_ID(), self::META_KEY, true);
        $isCommission = PriceAdjustment::isCommission($value);
        $displayValue = !$isCommission && is_numeric($value) ? number_format((int) $value, 0) : '';
        $tooltip = 'اگر این فیلد را پر کنید، به جای تغییر قیمت سراسری تنظیمات برای این محصول استفاده می‌شود. خالی بگذارید تا همان تنظیم سراسری اعمال شود. مقدار درصدی حداکثر ۳۵٪ افزایش یا ۳۵٪ کاهش (منفی یعنی کاهش قیمت)، خارج از بازه -۱۰۰ تا ۱۰۰ مبلغ ثابت تومانی.';
?>
        <p class="form-field _sync_basalam_price_change_value_field basalam-form-group-full basalam-price-change-field">
            <label for="<?php echo esc_attr(self::META_KEY . '_display'); ?>">تغییر قیمت اختصاصی</label>

            <span class="basalam-price-change-field__control">
                <span class="basalam-price-change-field__input">
                    <input type="text"
                           id="<?php echo esc_attr(self::META_KEY . '_display'); ?>"
                           class="short basalam-input percentage-input basalam-font-pelak-12"
                           data-role="price-change-input"
                           value="<?php echo esc_attr($displayValue); ?>"
                           inputmode="text"
                           autocomplete="off"
                           <?php echo $isCommission ? 'disabled' : ''; ?>
                    >
                    <span class="percentage-unit basalam-font-13">کارمزد دسته بندی</span>
                </span>

                <span class="basalam-price-change-field__toggle">
                    <input type="checkbox"
                           id="<?php echo esc_attr(self::META_KEY . '_toggle'); ?>"
                           class="toggle-percentage"
                           <?php echo checked($isCommission, true, false); ?>
                    >
                    <label class="basalam-font-10" for="<?php echo esc_attr(self::META_KEY . '_toggle'); ?>">کارمزد دسته‌بندی</label>
                </span>
            </span>

            <?php echo wc_help_tip($tooltip); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() escapes its output. ?>

            <input type="hidden"
                   id="<?php echo esc_attr(self::META_KEY); ?>"
                   name="<?php echo esc_attr(self::META_KEY); ?>"
                   data-role="price-change-hidden"
                   value="<?php echo esc_attr($value); ?>"
            >
        </p>
<?php
        wp_nonce_field('sync_basalam_save_price_change_action', '_sync_basalam_price_change_nonce');
    }

    public static function saveField($postId)
    {
        if (
            !isset($_POST['_sync_basalam_price_change_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_price_change_nonce'])), 'sync_basalam_save_price_change_action')
        ) {
            return;
        }

        $rawValue = isset($_POST[self::META_KEY]) ? sanitize_text_field(wp_unslash($_POST[self::META_KEY])) : '';
        $value = PriceAdjustment::normalize($rawValue);

        if ($value === null) {
            delete_post_meta($postId, self::META_KEY);

            return;
        }

        update_post_meta($postId, self::META_KEY, $value);
    }
}
