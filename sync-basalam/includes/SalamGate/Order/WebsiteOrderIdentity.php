<?php

namespace SyncBasalam\SalamGate\Order;

defined('ABSPATH') || exit;

require_once __DIR__ . '/NumericIdentity.php';

/** A site's order number is not globally unique in the website market. */
final class WebsiteOrderIdentity
{
    public static function forOrder(int $orderId): ?int
    {
        if ($orderId <= 0 || !function_exists('wc_get_order')) {
            return null;
        }
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return null;
        }

        $stored = $order->get_meta('_salam_gate_external_id');
        if ($stored !== '') {
            return self::positiveId($stored);
        }
        // Existing drafts predate the namespace. Updating them uses the known
        // SalamGate id; do not switch their local external identity on upgrade.
        if (self::positiveId($order->get_meta('_salam_gate_parcel_id')) !== null) {
            return $orderId;
        }

        $key = 'salam_gate_website_order_identity_' . $orderId;
        $saved = get_option($key, null);
        if ($saved !== null) {
            return self::positiveId($saved);
        }
        $candidate = NumericIdentity::forKey('woo-order:' . $orderId);
        if ($candidate === null) {
            return null;
        }

        global $wpdb;
        // Persist before the first remote write, including timeout/retry and
        // subsequent domain changes. A competing request cannot replace it.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $key,
            (string) $candidate,
            'no'
        ));
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        if ($inserted === false) {
            return null;
        }

        return self::positiveId(get_option($key, null));
    }

    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}
