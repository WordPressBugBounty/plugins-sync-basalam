<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\SalamGate\Domain\OrderShippingPolicy;
use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Order\BasalamOrderState;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Paths;

defined('ABSPATH') || exit;

/** Local-only discovery on Woo's order lists; it never initializes the shipping client. */
final class OrderDiscovery
{
    public const ACTION = 'salam_gate_order_discovery_preference';
    public const PREFERENCE = 'woosalam_sg_order_discovery_hidden';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'savePreference']);
        add_action('admin_notices', [self::class, 'renderPreference']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function isHidden(): bool
    {
        return get_user_option(self::PREFERENCE) === '1';
    }

    public static function shouldShow(): bool
    {
        return current_user_can('manage_woocommerce') && !FeatureAvailability::isVisible() && !self::isHidden();
    }

    /** Respect locally known order state without refreshing source orders or allocating parcel IDs. */
    public static function isEligible(\WC_Order $order): bool
    {
        if ($order->get_id() < 1 || $order->get_type() !== 'shop_order') return false;
        $market = OrderContext::marketFor((int) $order->get_id());
        if (!OrderShippingPolicy::isActionableStatus($market, $order->get_status())) return false;
        if ($market === OrderContext::MARKET_BASALAM) {
            $source = BasalamOrderState::cached($order);
            if ($source !== null && ($source['cancelled'] || in_array($source['status_id'] ?? null, BasalamOrderState::SENT, true))) {
                return false;
            }
        }
        // A known completed shipment should not be advertised as a new one.
        foreach (['_salam_gate_tracking_code', '_basalam_order_tracking_code', '_salam_gate_paid_at', '_salam_gate_invoice_id'] as $key) {
            if (!in_array($order->get_meta($key), ['', null, false, 0, '0'], true)) return false;
        }
        return true;
    }

    public static function renderOrder(\WC_Order $order): void
    {
        if (!self::shouldShow() || !self::isEligible($order)) { echo '&mdash;'; return; }
        if (!current_user_can('manage_options')) {
            echo '<span class="sg-order-discovery-help">فعال‌سازی توسط مدیر سایت</span>';
            return;
        }
        $id = (int) $order->get_id();
        $url = add_query_arg(['page' => 'salam_gate', 'view' => 'send', 'order_id' => $id,
            'sg_order_nonce' => wp_create_nonce('salam_gate_order_intent_' . $id)], admin_url('admin.php'));
        // Deliberately omit workflow/modal attributes: this is ordinary navigation.
        echo '<a class="sg-order-discovery-link" href="' . esc_url($url) . '" aria-label="'
            . esc_attr('ارسال مرسوله، سفارش ' . $order->get_order_number() . '؛ راه‌اندازی سلام‌گیت') . '">'
            . '<span class="sg-order-discovery-link__icon" aria-hidden="true"></span><span>ارسال مرسوله</span></a>';
    }

    public static function enqueue(): void
    {
        if (self::listKind() === '' || !current_user_can('manage_woocommerce') || FeatureAvailability::isVisible()) return;
        $path = 'styles/salam-gate-order-discovery.css';
        Assets\AssetRegistrar::base('basalam-admin-font-style', 'assets/css/font.css');
        wp_enqueue_style('woosalam-salam-gate-order-discovery', Paths::assetsUrl($path), ['basalam-admin-font-style'], (string) filemtime(Paths::assetPath($path)));
    }

    /** A separate form above Woo's table avoids nesting inside its bulk-action form. */
    public static function renderPreference(): void
    {
        $list = self::listKind();
        if ($list === '' || !current_user_can('manage_woocommerce') || FeatureAvailability::isVisible()) return;
        $hidden = self::isHidden();
        echo '<div class="sg-order-discovery-preference' . ($hidden ? ' is-hidden' : '') . '">';
        $description = current_user_can('manage_options')
            ? 'دکمهٔ «ارسال مرسوله» شما را به راه‌اندازی سلام‌گیت می‌برد.' : 'مدیر سایت می‌تواند ارسال با سلام‌گیت را فعال کند.';
        echo '<p>' . esc_html($hidden ? 'دکمه‌های ارسال سلام‌گیت برای شما پنهان‌اند.' : $description) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="visibility" value="' . ($hidden ? 'show' : 'hide') . '">';
        echo '<input type="hidden" name="order_list" value="' . esc_attr($list) . '">';
        wp_nonce_field(self::ACTION);
        echo '<button type="submit" class="button-link">' . esc_html($hidden ? 'نمایش دکمه‌های ارسال' : 'پنهان‌کردن دکمه‌ها برای من') . '</button>';
        echo '</form></div>';
    }

    public static function savePreference(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('اجازه تغییر این تنظیم را ندارید.', '', ['response' => 403]);
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('درخواست معتبر نیست.', '', ['response' => 405]);
            return;
        }
        check_admin_referer(self::ACTION);
        $visibility = $_POST['visibility'] ?? null;
        if (!is_string($visibility) || !in_array($visibility, ['show', 'hide'], true)) {
            wp_die('گزینه نمایش معتبر نیست.', '', ['response' => 400]);
            return;
        }
        // Site-scoped user preference; it never changes this site's activation or other administrators.
        update_user_option(get_current_user_id(), self::PREFERENCE, $visibility === 'hide' ? '1' : '0', false);
        $url = ($_POST['order_list'] ?? '') === 'legacy'
            ? admin_url('edit.php?post_type=shop_order') : admin_url('admin.php?page=wc-orders');
        wp_safe_redirect($url);
        exit;
    }

    private static function listKind(): string
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return '';
        if ($screen->id === 'edit-shop_order') return 'legacy';
        if ($screen->id === 'woocommerce_page_wc-orders' && empty($_GET['action'])) return 'hpos';
        return '';
    }
}
