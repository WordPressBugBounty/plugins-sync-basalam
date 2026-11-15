<?php

namespace SyncBasalam\Services\Products\UnmappedProducts;

defined('ABSPATH') || exit;

/**
 * اعلان پیشخوان: «n محصول فعال باسلام با ووسلام همگام نیست».
 *
 * بستن اعلان فقط نمایش آن را برای همین گزارش مخفی می‌کند؛ قفل اجرای مجدد بررسی
 * تا تعیین‌تکلیف همهٔ آیتم‌ها سر جایش می‌ماند.
 */
class UnmappedProductsAlert
{
    public const PAGE_SLUG = 'basalam-show-products';
    public const DISMISS_OPTION = 'sync_basalam_unmapped_alert_dismissed_for';
    public const DISMISS_QUERY_ARG = 'sync_basalam_dismiss_unmapped_alert';
    public const DISMISS_NONCE = 'sync_basalam_dismiss_unmapped_alert_nonce';

    public static function registerHooks(): void
    {
        \add_action('admin_init', [self::class, 'handleDismiss']);
        \add_action('admin_notices', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!\current_user_can('manage_woocommerce')) return;

        // کاربر داخل خود صفحهٔ گزارش است؛ اعلان تکراری است.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
        $currentPage = isset($_GET['page']) ? \sanitize_key(\wp_unslash($_GET['page'])) : '';
        if ($currentPage === self::PAGE_SLUG) return;

        // این متد روی هر صفحهٔ پیشخوان اجرا می‌شود؛ تا مطمئن نشده‌ایم گزارش بازی
        // هست، فقط option وضعیت خوانده می‌شود و سراغ جدول/صف نمی‌رویم.
        $state = (new UnmappedScanState())->get();
        if ($state['status'] !== UnmappedScanState::STATUS_AWAITING) return;
        if ((int) \get_option(self::DISMISS_OPTION, 0) === (int) $state['finished_at']) return;

        $manager = new UnmappedProductsManager();
        $counts = $manager->repository()->counts($manager->vendorId());
        if ((int) $counts['unresolved'] <= 0) return;

        $syncBasalamUnmappedSummary = [
            'status' => $state['status'],
            'finished_at' => (int) $state['finished_at'],
            'counts' => $counts,
        ];
        require syncBasalamPlugin()->templatePath('notifications/UnmappedProductsAlert.php');
    }

    public static function handleDismiss(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce itself is verified below.
        if (empty($_GET[self::DISMISS_QUERY_ARG])) return;

        if (!\current_user_can('manage_woocommerce')) return;

        $nonce = isset($_GET['_wpnonce']) ? \sanitize_text_field(\wp_unslash($_GET['_wpnonce'])) : '';
        if (!\wp_verify_nonce($nonce, self::DISMISS_NONCE)) return;

        $state = (new UnmappedScanState())->get();
        \update_option(self::DISMISS_OPTION, (int) $state['finished_at'], false);

        \wp_safe_redirect(\remove_query_arg([self::DISMISS_QUERY_ARG, '_wpnonce']));
        exit;
    }

    /** تعداد آیتم‌های باز برای حباب کنار آیتم منو؛ خارج از حالت گزارشِ باز صفر است. */
    public static function menuUnresolvedCount(): int
    {
        $state = (new UnmappedScanState())->get();
        if ($state['status'] !== UnmappedScanState::STATUS_AWAITING) return 0;

        $manager = new UnmappedProductsManager();
        $vendorId = $manager->vendorId();
        if ($vendorId === '') return 0;

        return (int) $manager->repository()->counts($vendorId)['unresolved'];
    }

    public static function dismissUrl(): string
    {
        return \wp_nonce_url(
            \add_query_arg(self::DISMISS_QUERY_ARG, 1),
            self::DISMISS_NONCE
        );
    }

    public static function pageUrl(): string
    {
        return \admin_url('admin.php?page=' . self::PAGE_SLUG);
    }
}
