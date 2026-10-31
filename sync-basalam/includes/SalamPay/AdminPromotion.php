<?php

namespace SyncBasalam\SalamPay;

defined('ABSPATH') || exit;

final class AdminPromotion
{
    private const ACTIVATION_URL = 'https://bslm.ir/XoRk9RrO';
    private const DISMISSED_META = 'sync_basalam_salampay_promotion_dismissed';
    private const DISMISS_ACTION = 'sync_basalam_dismiss_salampay_promotion';

    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'renderAdminNotice'], 0);
        // WooCommerce's payments page suppresses third-party admin notices.
        add_action('woocommerce_settings_checkout', [self::class, 'renderPaymentNotice'], 5);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueStyles']);
        add_action('admin_footer', [self::class, 'renderDismissForm']);
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
        $screen = get_current_screen();
        $tab = $_GET['tab'] ?? '';
        return $screen && $screen->id === 'woocommerce_page_wc-settings'
            && $tab === 'checkout';
    }

    public static function enqueueStyles(): void
    {
        $screen = get_current_screen();
        if (!self::eligible() || !$screen) {
            return;
        }
        wp_enqueue_style('woosalam-salampay-admin', Module::assetUrl('admin.css'), [],
            (string) filemtime(dirname(__DIR__, 2) . '/assets/salam-pay/admin.css'));
    }

    public static function renderAdminNotice(): void
    {
        if (!self::isPaymentScreen()) {
            self::renderNotice();
        }
    }

    public static function renderPaymentNotice(): void
    {
        if (self::isPaymentScreen()) {
            self::renderNotice();
        }
    }

    private static function renderNotice(): void
    {
        if (!self::eligible()) {
            return;
        }
        $activationUrl = self::ACTIVATION_URL;
        require dirname(__DIR__, 2) . '/templates/admin/SalamPayPromotion.php';
    }

    public static function renderDismissForm(): void
    {
        if (!self::eligible()) {
            return;
        }
        // Keep this form outside WooCommerce's settings form.
        ?>
        <form id="woosalam-salampay-dismiss" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" hidden>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::DISMISS_ACTION); ?>">
            <?php wp_nonce_field(self::DISMISS_ACTION); ?>
        </form>
        <?php
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
