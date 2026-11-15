<?php

namespace SyncBasalam\SalamGate\Admin;

defined('ABSPATH') || exit;

/** Preserve an administrator's selected Woo order across activation and OAuth. */
final class ShippingIntent
{
    private const PREFERENCE = 'woosalam_sg_shipping_intent';
    private const LIFETIME = 86400;

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'capture']);
    }

    /** Local navigation only; never establishes account readiness or starts a shipment. */
    public static function capture(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || ($_GET['page'] ?? null) !== 'salam_gate'
            || ($_GET['view'] ?? null) !== 'send'
            || !self::canContinue()) return;

        $orderId = self::positiveId($_GET['order_id'] ?? null);
        $nonce = $_GET['sg_order_nonce'] ?? null;
        if ($orderId === null || !is_string($nonce)
            || !wp_verify_nonce(wp_unslash($nonce), 'salam_gate_order_intent_' . $orderId)
            || self::eligibleOrder($orderId) === null) return;

        // A site-scoped user option survives an OAuth token change without
        // assuming that the new credentials have been verified for shipping.
        update_user_option(get_current_user_id(), self::PREFERENCE, [
            'order_id' => $orderId,
            'user_id' => get_current_user_id(),
            'site_id' => get_current_blog_id(),
            'expires_at' => time() + self::LIFETIME,
        ], false);
    }

    public static function pendingOrderId(): ?int
    {
        if (!self::canContinue()) return null;
        $intent = get_user_option(self::PREFERENCE);
        if ($intent === false || $intent === '') return null;

        $orderId = is_array($intent) ? self::positiveId($intent['order_id'] ?? null) : null;
        $expiresAt = is_array($intent) ? self::positiveId($intent['expires_at'] ?? null) : null;
        if ($orderId === null || $expiresAt === null || $expiresAt <= time()
            || $expiresAt > time() + self::LIFETIME
            || ($intent['user_id'] ?? null) !== get_current_user_id()
            || ($intent['site_id'] ?? null) !== get_current_blog_id()
            || self::eligibleOrder($orderId) === null) {
            self::clear();
            return null;
        }

        return $orderId;
    }

    /** WooCommerce supplies its own HPOS or classic editor URL; no callback URL is trusted. */
    public static function continuationUrl(): string
    {
        $orderId = self::pendingOrderId();
        $order = $orderId !== null ? self::eligibleOrder($orderId) : null;
        return $order !== null ? (string) $order->get_edit_order_url() : '';
    }

    public static function clear(): void
    {
        if (get_current_user_id() > 0) delete_user_option(get_current_user_id(), self::PREFERENCE, false);
    }

    private static function canContinue(): bool
    {
        return get_current_user_id() > 0 && current_user_can('manage_woocommerce') && current_user_can('manage_options');
    }

    private static function eligibleOrder(int $orderId): ?\WC_Order
    {
        if (!function_exists('wc_get_order') || !current_user_can('edit_shop_order', $orderId)) return null;
        $order = wc_get_order($orderId);
        return $order instanceof \WC_Order && OrderDiscovery::isEligible($order) ? $order : null;
    }

    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}
