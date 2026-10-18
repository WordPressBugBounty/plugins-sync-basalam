<?php

namespace SyncBasalam\SalamGate\Settings;

use SyncBasalam\SalamGate\Contracts\PayMethods;
use SyncBasalam\SalamGate\Utils\Digits;

defined('ABSPATH') || exit;

/**
 * تنظیمات قابلیت سلام‌گیت افزونه پرو (option مستقل از تنظیمات پلاگین پایه).
 */
class SalamGateSettings
{
    public const OPTION_KEY = 'sync_basalam_salam_gate_settings';

    public const KEY_PAY_METHOD_DEFAULT       = 'pay_method_default';
    public const KEY_LABEL_TEMPLATE_DEFAULT   = 'label_template_default';
    public const KEY_WEIGHT_PADDING_GRAMS     = 'weight_padding_grams';
    public const KEY_DEFAULT_NET_WEIGHT_GRAMS = 'default_net_weight_grams';
    public const KEY_DEFAULT_BOX_TYPE_ID      = 'default_box_type_id';
    public const KEY_CURRENCY_UNIT            = 'currency_unit';
    public const KEY_AUTO_SET_SHIPPED         = 'auto_set_shipped';
    public const KEY_STATUS_SYNC_ENABLED      = 'status_sync_enabled';
    public const KEY_STATUS_SYNC_INTERVAL     = 'status_sync_interval';

    public const PAY_METHOD_ORDER_BALANCE  = PayMethods::PARCEL_ORDER_BALANCE; // فقط بازار بسالم (کسر از مبلغ سفارش)
    public const PAY_METHOD_VENDOR_BALANCE = PayMethods::PARCEL_VENDOR_BALANCE;
    public const PAY_METHOD_WALLET         = PayMethods::PARCEL_WALLET;

    public const LABEL_TEMPLATES = ['invoice', 'invoice_new', 'summary', 'label_10x15'];
    public const CURRENCY_UNITS = ['toman', 'rial'];
    public const SYNC_INTERVALS = ['hourly', 'twicedaily'];

    public const DEFAULTS = [
        self::KEY_PAY_METHOD_DEFAULT       => self::PAY_METHOD_VENDOR_BALANCE,
        self::KEY_LABEL_TEMPLATE_DEFAULT   => 'label_10x15',
        self::KEY_WEIGHT_PADDING_GRAMS     => 100,
        self::KEY_DEFAULT_NET_WEIGHT_GRAMS => 100,
        self::KEY_DEFAULT_BOX_TYPE_ID      => null,
        self::KEY_CURRENCY_UNIT            => 'toman',
        self::KEY_AUTO_SET_SHIPPED         => 1,
        self::KEY_STATUS_SYNC_ENABLED      => 1,
        self::KEY_STATUS_SYNC_INTERVAL     => 'hourly',
    ];

    private static ?SalamGateSettings $instance = null;

    private array $settings;

    private function __construct()
    {
        $stored = get_option(self::OPTION_KEY, []);

        $this->settings = self::mergeDefaults(is_array($stored) ? $stored : []);
    }

    public static function instance(): SalamGateSettings
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * فقط برای تست‌ها: بازسازی singleton بعد از تغییر option.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * @return mixed مقدار تنظیم یا پیش‌فرض آن
     */
    public function get(string $key)
    {
        return $this->settings[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    public function all(): array
    {
        return $this->settings;
    }

    /**
     * ذخیره جزئی: merge روی مقادیر فعلی + sanitize مقادیر ورودی.
     */
    public function save(array $partial): void
    {
        $partial = $this->sanitize($partial);

        $this->settings = self::mergeDefaults(array_merge($this->settings, $partial));

        update_option(self::OPTION_KEY, $this->settings, false);
    }

    private static function mergeDefaults(array $settings): array
    {
        $merged = self::DEFAULTS;

        foreach ($merged as $key => $default) {
            if (array_key_exists($key, $settings) && $settings[$key] !== null) {
                $merged[$key] = $settings[$key];
            }
        }

        return $merged;
    }

    /**
     * فقط کلیدهای معتبر؛ مقادیر به نوع درست تبدیل و enumهای نامعتبر به پیش‌فرض برمی‌گردند.
     */
    private function sanitize(array $partial): array
    {
        $clean = [];

        foreach ($partial as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            if ($value !== null && !is_scalar($value)) {
                continue;
            }

            if (is_bool($value)) {
                $value = (int) $value;
            }

            switch ($key) {
                case self::KEY_WEIGHT_PADDING_GRAMS:
                case self::KEY_DEFAULT_NET_WEIGHT_GRAMS:
                    $value = max(0, (int) Digits::toEnglish((string) $value));
                    break;

                case self::KEY_PAY_METHOD_DEFAULT:
                    $intValue = (int) Digits::toEnglish((string) $value);
                    $value = in_array($intValue, [self::PAY_METHOD_VENDOR_BALANCE, self::PAY_METHOD_WALLET], true)
                        ? $intValue
                        : self::DEFAULTS[$key];
                    break;

                case self::KEY_AUTO_SET_SHIPPED:
                case self::KEY_STATUS_SYNC_ENABLED:
                    $value = (int) (bool) $value;
                    break;

                case self::KEY_DEFAULT_BOX_TYPE_ID:
                    $value = ($value === null || $value === '') ? null : (int) Digits::toEnglish((string) $value);
                    $value = $value !== null && $value > 0 ? $value : null;
                    break;

                case self::KEY_LABEL_TEMPLATE_DEFAULT:
                    $value = in_array($value, self::LABEL_TEMPLATES, true) ? $value : self::DEFAULTS[$key];
                    break;

                case self::KEY_CURRENCY_UNIT:
                    $value = in_array($value, self::CURRENCY_UNITS, true) ? $value : self::DEFAULTS[$key];
                    break;

                case self::KEY_STATUS_SYNC_INTERVAL:
                    $value = in_array($value, self::SYNC_INTERVALS, true) ? $value : self::DEFAULTS[$key];
                    break;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
