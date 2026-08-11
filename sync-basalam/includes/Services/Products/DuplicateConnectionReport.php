<?php

namespace SyncBasalam\Services\Products;

defined('ABSPATH') || exit;

/**
 * گزارش اتصال‌های تکراری برای نمایش به مدیر سایت.
 *
 * هم اصلاح خودکار (هنگام بروزرسانی افزونه) و هم تشخیص لحظه‌ای (هنگام همگام‌سازی)
 * نتیجه را اینجا ذخیره می‌کنند تا در پیشخوان وردپرس دیده شود.
 */
class DuplicateConnectionReport
{
    public const OPTION = 'sync_basalam_duplicate_connection_report';
    public const DISMISS_QUERY_ARG = 'sync_basalam_dismiss_duplicate_report';
    public const DISMISS_NONCE = 'sync_basalam_dismiss_duplicate_report_nonce';

    private const MAX_ITEMS = 50;

    public static function registerHooks(): void
    {
        \add_action('admin_init', [self::class, 'handleDismiss']);
        \add_action('admin_notices', [self::class, 'render']);
    }

    /**
     * ثبت یک اتصال مشترک تشخیص داده شده.
     *
     * @return bool آیا این مورد تازه ثبت شد؟
     */
    public static function recordConflict(int $productId, array $conflictingIds): bool
    {
        $ids = array_values(array_unique(array_merge([$productId], array_map('intval', $conflictingIds))));
        sort($ids);

        return self::add([
            'type'         => 'conflict',
            'product_ids'  => $ids,
            'kept'         => 0,
            'disconnected' => [],
        ]);
    }

    /**
     * ثبت اتصال‌های تکراری که به صورت خودکار اصلاح شده‌اند.
     */
    public static function recordRepairs(array $repairs): void
    {
        foreach ($repairs as $repair) {
            $ids = array_values(array_unique(array_merge([(int) $repair['kept']], array_map('intval', $repair['disconnected']))));
            sort($ids);

            self::add([
                'type'         => 'repaired',
                'product_ids'  => $ids,
                'kept'         => (int) $repair['kept'],
                'disconnected' => array_map('intval', $repair['disconnected']),
            ]);
        }
    }

    public static function all(): array
    {
        $report = \get_option(self::OPTION, []);

        return is_array($report) ? $report : [];
    }

    public static function forget(): void
    {
        \delete_option(self::OPTION);
    }

    public static function handleDismiss(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce itself is verified on the next line.
        if (empty($_GET[self::DISMISS_QUERY_ARG])) return;

        if (!\current_user_can('manage_woocommerce')) return;

        $nonce = isset($_GET['_wpnonce']) ? \sanitize_text_field(\wp_unslash($_GET['_wpnonce'])) : '';

        if (!\wp_verify_nonce($nonce, self::DISMISS_NONCE)) return;

        self::forget();

        \wp_safe_redirect(\remove_query_arg([self::DISMISS_QUERY_ARG, '_wpnonce']));
        exit;
    }

    public static function render(): void
    {
        if (!\current_user_can('manage_woocommerce')) return;

        if (!self::all()) return;

        require syncBasalamPlugin()->templatePath('notifications/DuplicateConnectionAlert.php');
    }

    public static function dismissUrl(): string
    {
        return \wp_nonce_url(
            \add_query_arg(self::DISMISS_QUERY_ARG, 1),
            self::DISMISS_NONCE
        );
    }

    private static function add(array $item): bool
    {
        $report = self::all();
        $key = $item['type'] . ':' . implode('-', $item['product_ids']);

        if (isset($report[$key])) return false;

        if (count($report) >= self::MAX_ITEMS) array_shift($report);

        $item['recorded_at'] = \current_time('mysql');
        $report[$key] = $item;

        \update_option(self::OPTION, $report, false);

        return true;
    }
}
