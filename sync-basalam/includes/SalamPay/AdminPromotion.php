<?php

namespace SyncBasalam\SalamPay;

use SyncBasalam\Admin\PromotionBanner;

defined('ABSPATH') || exit;

final class AdminPromotion
{
    private const ACTIVATION_URL = 'https://bslm.ir/XoRk9RrO';
    private const DISMISSED_META = 'sync_basalam_salampay_promotion_dismissed';
    private const DISMISS_ACTION = 'sync_basalam_dismiss_salampay_promotion';

    public static function register(): void
    {
        PromotionBanner::register('salampay', 10, [self::class, 'slide']);
        add_action('admin_post_' . self::DISMISS_ACTION, [self::class, 'dismiss']);
    }

    private static function eligible(): bool
    {
        // Older versions stored "1" here; it is treated as an expired dismissal.
        return current_user_can('manage_woocommerce')
            && (int) get_user_meta(get_current_user_id(), self::DISMISSED_META, true) <= time();
    }

    public static function isPaymentScreen(): bool
    {
        return PromotionBanner::isPaymentScreen();
    }

    public static function slide(): ?array
    {
        if (!self::eligible()) return null;
        return [
            'name' => 'سلام‌پی',
            'logo' => Module::assetUrl('logo.svg'),
            'text' => 'می‌توانید درگاه پرداخت سلام‌پی را برای فروشگاه خود در ووسلام فعال کنید. برای شروع، از طریق لینک فعال‌سازی اقدام کنید.',
            'url' => self::ACTIVATION_URL,
            'cta' => 'درخواست فعال‌سازی سلام‌پی',
            'external' => true,
            'dismiss_action' => self::DISMISS_ACTION,
            'dismiss_hint' => 'این اعلان برای ۱۰ روز پنهان می‌شود.',
        ];
    }

    public static function dismiss(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('اجازهٔ بستن این پیام را ندارید.', 'sync-basalam'), '', ['response' => 403]);
        }
        check_admin_referer(self::DISMISS_ACTION);
        update_user_meta(get_current_user_id(), self::DISMISSED_META, time() + 10 * DAY_IN_SECONDS);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
