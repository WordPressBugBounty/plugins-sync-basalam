<?php

namespace SyncBasalam\Admin\Product\elements\SingleProduct;

defined('ABSPATH') || exit;

class PriceIncreaseField
{
    public const META_KEY = '_sync_basalam_increase_price_value';

    public static function renderField()
    {
        $value = get_post_meta(get_the_ID(), self::META_KEY, true);
        $isCommission = $value === '-1';
        $displayValue = '';

        if (!$isCommission && $value !== '' && is_numeric($value)) {
            $displayValue = number_format((int) $value, 0);
        }

        $unit = (!$isCommission && is_numeric($value) && (int) $value > 100) ? 'تومان' : 'درصد';
        $tooltip = 'اگر این فیلد را پر کنید، در ساخت payload به جای افزایش قیمت اصلی تنظیمات استفاده می‌شود. خالی بگذارید تا همان تنظیم اصلی اعمال شود. مقدار ۱ تا ۱۰۰ درصد، بیشتر از ۱۰۰ مبلغ ثابت تومانی، و گزینه «کارمزد دسته‌بندی» معادل -۱ است.';
?>
        <p class="form-field _sync_basalam_increase_price_value_field basalam-form-group-full basalam-p basalam-increase-price-field" style="padding:12px !important;">
            <span class="basalam-increase-price-field__label-section">
                <span class="basalam-increase-price-field__header">
                    <label for="<?php echo esc_attr(self::META_KEY . '_display'); ?>">افزایش قیمت اختصاصی</label>
                    <span class="woocommerce-help-tip" tabindex="0" aria-label="<?php echo esc_attr($tooltip); ?>"></span>
                </span>
            </span>

            <span class="basalam-increase-price-field__control-section">
                <span class="basalam-input-container basalam-increase-price-field__input-row">
                    <input type="text"
                           id="<?php echo esc_attr(self::META_KEY . '_display'); ?>"
                           class="short basalam-input basalam-p percentage-input basalam-font-pelak-12"
                           data-role="increase-price-input"
                           value="<?php echo esc_attr($displayValue); ?>"
                           inputmode="numeric"
                           autocomplete="off"
                           style="flex:0.1 !important;"
                           <?php echo $isCommission ? 'disabled' : ''; ?>
                    >
                    <span class="percentage-unit basalam-p basalam-min-width-0 basalam-font-13"><?php echo esc_html($unit); ?></span>
                </span>

                <span class="basalam-increase-price-field__toggle-row">
                    <input type="checkbox"
                           id="<?php echo esc_attr(self::META_KEY . '_toggle'); ?>"
                           class="toggle-percentage"
                           <?php echo checked($isCommission, true, false); ?>
                    >
                    <label class="basalam-font-10" for="<?php echo esc_attr(self::META_KEY . '_toggle'); ?>">کارمزد دسته‌بندی</label>
                </span>
            </span>

            <input type="hidden"
                   id="<?php echo esc_attr(self::META_KEY); ?>"
                   name="<?php echo esc_attr(self::META_KEY); ?>"
                   data-role="increase-price-hidden"
                   value="<?php echo esc_attr($value); ?>"
            >
        </p>
<?php
        wp_nonce_field('sync_basalam_save_price_increase_action', '_sync_basalam_price_increase_nonce');
    }

    public static function saveField($postId)
    {
        if (
            !isset($_POST['_sync_basalam_price_increase_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_sync_basalam_price_increase_nonce'])), 'sync_basalam_save_price_increase_action')
        ) {
            return;
        }

        if (!isset($_POST[self::META_KEY])) {
            delete_post_meta($postId, self::META_KEY);

            return;
        }

        $rawValue = sanitize_text_field(wp_unslash($_POST[self::META_KEY]));

        if ($rawValue === '') {
            delete_post_meta($postId, self::META_KEY);

            return;
        }

        if (!is_numeric($rawValue)) {
            return;
        }

        update_post_meta($postId, self::META_KEY, (string) intval($rawValue));
    }
}
