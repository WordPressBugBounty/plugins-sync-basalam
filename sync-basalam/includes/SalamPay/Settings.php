<?php

namespace SyncBasalam\SalamPay;

defined('ABSPATH') || exit;

final class Settings
{
    // Keep the original option and gateway ID so installed stores need no migration.
    public static function all(): array
    {
        $settings = get_option('woocommerce_salampay_settings', []);
        return is_array($settings) ? $settings : [];
    }

    public static function configured(): bool
    {
        $settings = self::all();
        return ($settings['enabled'] ?? 'no') === 'yes' && trim((string) ($settings['client_secret'] ?? '')) !== '';
    }

    public static function client(): ApiClient
    {
        $settings = self::all();
        return new ApiClient((string) ($settings['client_secret'] ?? ''), ($settings['debug_log'] ?? 'no') === 'yes');
    }
}
