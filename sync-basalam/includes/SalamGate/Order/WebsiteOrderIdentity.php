<?php

namespace SyncBasalam\SalamGate\Order;

defined('ABSPATH') || exit;

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

        // Persist identity before the first remote write; a competitor cannot replace it.
        $inserted = \SyncBasalam\SalamGate\Infrastructure\AtomicOptionStore::exchange($key, null, (string) $candidate);
        if ($inserted === \SyncBasalam\SalamGate\Infrastructure\AtomicOptionStore::STORAGE_ERROR) { return null; }

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
