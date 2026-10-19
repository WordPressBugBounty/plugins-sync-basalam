<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Paths;

defined('ABSPATH') || exit;

/** Resolve an expired account cache asynchronously, never while rendering Woo orders. */
final class OrderFeatureGate
{
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueueProbe'], 4);
    }

    public static function enqueueProbe(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true)
            || !current_user_can('manage_woocommerce')
            || AccountStateRouter::resolveForPage()['state'] !== AccountStateRouter::STATE_LOADING) {
            return;
        }
        $file = Paths::assetPath('js/salam-gate-order-availability.js');
        wp_enqueue_script('woosalam-sg-order-availability', Paths::assetsUrl('js/salam-gate-order-availability.js'), [], (string) filemtime($file), true);
        wp_localize_script('woosalam-sg-order-availability', 'woosalamOrderAvailability', [
            'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('basalam_pro_nonce'),
        ]);
    }

    public static function availability(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز است.'], 403);
        }
        $nonce = $_POST['_wpnonce'] ?? null;
        if (!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'اعتبار صفحه تمام شده است.'], 403);
        }
        AccountStateRouter::resolveCurrent();
        // A disable/reconnect during the read cannot expose a stale active state.
        wp_send_json_success(['available' => FeatureAvailability::isVisible()]);
    }
}
