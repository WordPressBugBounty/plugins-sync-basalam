<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\SalamGate\Settings\SalamGateSettings;

if (! defined('ABSPATH')) exit;

/**
 * ذخیره فرم تنظیمات سلام‌گیت (admin-post).
 */
class SettingsHandler
{
    public const ACTION = 'salam_gate_save_settings';
    public const NONCE_FIELD = '_salam_gate_nonce';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('دسترسی غیرمجاز!');
        }

        check_admin_referer(self::ACTION, self::NONCE_FIELD);

        $raw = isset($_POST['salam_gate']) && is_array($_POST['salam_gate'])
            ? (array) wp_unslash($_POST['salam_gate'])
            : [];

        SalamGateSettings::instance()->save(self::sanitizeForSave($raw));

        // هندلر در production ثبت نمی‌شود؛ تزریق تنظیمات به مودال ووسلام حذف شده است.
        // مسیر canonical برای سازگاری احتمالی ابزارهای قدیمی نگه داشته می‌شود.
        wp_safe_redirect(add_query_arg(
            ['page' => Menus::MAIN_SLUG, 'salam_gate_updated' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * نرمال‌سازی فرم HTML (رشته/چک‌باکس) به ساختار تنظیمات.
     * اعتبارسنجی نهایی نوع‌ها/enumها در SalamGateSettings::save انجام می‌شود.
     *
     * @return array<string, mixed>
     */
    public static function sanitizeForSave(array $raw): array
    {
        $settings = SalamGateSettings::instance();

        $value = static fn (string $key, mixed $default = '') => isset($raw[$key]) && is_scalar($raw[$key])
            ? trim((string) $raw[$key])
            : $default;

        return [
            SalamGateSettings::KEY_PAY_METHOD_DEFAULT => $value(SalamGateSettings::KEY_PAY_METHOD_DEFAULT, (string) $settings->get(SalamGateSettings::KEY_PAY_METHOD_DEFAULT)),
            SalamGateSettings::KEY_LABEL_TEMPLATE_DEFAULT => $value(SalamGateSettings::KEY_LABEL_TEMPLATE_DEFAULT),
            SalamGateSettings::KEY_CURRENCY_UNIT => $value(SalamGateSettings::KEY_CURRENCY_UNIT),
            SalamGateSettings::KEY_WEIGHT_PADDING_GRAMS => $value(SalamGateSettings::KEY_WEIGHT_PADDING_GRAMS),
            SalamGateSettings::KEY_DEFAULT_NET_WEIGHT_GRAMS => $value(SalamGateSettings::KEY_DEFAULT_NET_WEIGHT_GRAMS),
            SalamGateSettings::KEY_DEFAULT_BOX_TYPE_ID => self::normalizeBoxType($value(SalamGateSettings::KEY_DEFAULT_BOX_TYPE_ID)),
            // چک‌باکس تیک‌نخورده ارسال نمی‌شود → 0
            SalamGateSettings::KEY_AUTO_SET_SHIPPED => ($raw[SalamGateSettings::KEY_AUTO_SET_SHIPPED] ?? '') !== '' ? 1 : 0,
            SalamGateSettings::KEY_STATUS_SYNC_ENABLED => ($raw[SalamGateSettings::KEY_STATUS_SYNC_ENABLED] ?? '') !== '' ? 1 : 0,
            SalamGateSettings::KEY_STATUS_SYNC_INTERVAL => $value(SalamGateSettings::KEY_STATUS_SYNC_INTERVAL),
        ];
    }

    /**
     * مقدار خالی یا «خودکار» → null (تشخیص خودکار از ابعاد).
     */
    private static function normalizeBoxType(string $value): ?string
    {
        return ($value === '' || $value === 'auto') ? null : $value;
    }
}
