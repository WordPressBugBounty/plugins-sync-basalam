<?php

namespace SyncBasalam\SalamGate\Order;

defined('ABSPATH') || exit;

/** IDs-only reverse lookup in WooCommerce's authoritative metadata store. */
final class OrderParcelMapping
{
    public static function orderIdForParcel(int $parcelId): ?int
    {
        if ($parcelId < 1) { return null; }
        return self::uniqueRoot(
            self::ordersForMeta('_salam_gate_parcel_id', (string) $parcelId),
            self::ordersForMeta('_salam_gate_native_link_' . $parcelId, null, $parcelId)
        );
    }

    public static function orderIdForExternalId(int $externalId): ?int
    {
        if ($externalId < 1) { return null; }
        return self::uniqueRoot(
            self::ordersForMeta('_salam_gate_external_id', (string) $externalId),
            self::ordersForMeta('_salam_gate_native_origin_' . $externalId, null, null, $externalId)
        );
    }

    /** Payment-only website lookup; never interprets an external ID as a Woo order number. */
    public static function websiteOrdersForParcel(int $parcelId): ?array
    {
        return $parcelId > 0 ? self::ordersForMeta('_salam_gate_parcel_id', (string) $parcelId) : [];
    }

    /** Includes a persisted creation identity when the metadata save did not complete. */
    public static function websiteOrdersForExternalId(int $externalId): ?array
    {
        if ($externalId < 1) { return []; }
        $ids = self::ordersForMeta('_salam_gate_external_id', (string) $externalId);
        if ($ids === null) { return null; }
        global $wpdb;
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM `{$wpdb->options}` WHERE option_name REGEXP %s AND option_value = %s LIMIT 2",
            '^salam_gate_website_order_identity_[1-9][0-9]*$', (string) $externalId
        ));
        if (!is_array($names) || ($wpdb->last_error ?? '') !== '' || count($names) > 1) { return null; }
        foreach ($names as $name) {
            if (!is_string($name) || !preg_match('/^salam_gate_website_order_identity_([1-9][0-9]*)$/D', $name, $match)
                || ($id = self::positiveId($match[1])) === null) { return null; }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        return count($ids) > 1 ? null : $ids;
    }

    /** Raw evidence is not a valid link, but must prevent an unsafe unlinked fallback. */
    public static function hasEvidence(int $parcelId, int $nativeId): bool
    {
        global $wpdb;
        if ($parcelId < 1 || $nativeId < 1 || !is_object($wpdb)
            || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) { return true; }
        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $meta = $hpos ? $wpdb->prefix . 'wc_orders_meta' : ($wpdb->postmeta ?? null);
        if (!is_string($meta) || $meta === '') { return true; }
        // Inspect metadata directly, including orphan/damaged rows. In particular, a
        // missing reciprocal key or identity cannot hide a scoped unknown-payment marker.
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM `{$meta}` m WHERE
             (m.meta_key = %s AND m.meta_value = %s) OR
             (m.meta_key = %s AND m.meta_value = %s) OR
             m.meta_key = %s OR m.meta_key = %s OR m.meta_key REGEXP %s OR
             (m.meta_key REGEXP %s AND m.meta_value = %s) OR
             (m.meta_key = %s AND m.meta_value REGEXP %s) LIMIT 1",
            '_salam_gate_parcel_id', (string) $parcelId,
            '_salam_gate_external_id', (string) $nativeId,
            '_salam_gate_native_link_' . $parcelId,
            '_salam_gate_native_origin_' . $nativeId,
            '^_salam_gate_bp_' . $nativeId . '_',
            '^_salam_gate_bp_[1-9][0-9]*_salam_gate_parcel_id$', (string) $parcelId,
            '_salam_gate_basalam_mapping',
            's:9:"parcel_id";(i:' . $nativeId . ';|s:[0-9]+:"' . $nativeId . '";)'
        ));
        return ($wpdb->last_error ?? '') !== '' || $value !== null;
    }

    /** null means an invalid/ambiguous datastore read; [] means no candidate. */
    private static function ordersForMeta(string $key, ?string $value, ?int $parcelId = null, ?int $nativeId = null): ?array
    {
        global $wpdb;
        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $orders = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
        $meta = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
        $id = $hpos ? 'id' : 'ID';
        $metaId = $hpos ? 'order_id' : 'post_id';
        $type = $hpos ? 'type' : 'post_type';
        // Scoped keys contain the exact requested identity; inspect any stored
        // value so malformed scoped records cannot disappear behind legacy data.
        $comparison = $value === null ? 'm.meta_value REGEXP %s' : 'm.meta_value = %s';
        $query = $wpdb->prepare(
            "SELECT DISTINCT o.{$id} FROM `{$orders}` o JOIN `{$meta}` m ON m.{$metaId} = o.{$id}
             WHERE o.{$type} = 'shop_order' AND m.meta_key = %s AND {$comparison}
             ORDER BY o.{$id} ASC LIMIT 2",
            $key,
            $value ?? '.*'
        );
        $ids = $wpdb->get_col($query);
        if (!is_array($ids) || ($wpdb->last_error ?? '') !== '' || count($ids) > 1) { return null; }
        $result = [];
        foreach ($ids as $candidate) {
            $candidate = self::positiveId($candidate);
            if ($candidate === null) { return null; }
            if (function_exists('wc_get_order')) {
                $order = wc_get_order($candidate);
                if (!$order instanceof \WC_Order) { return null; }
                $stored = self::metaId($order, $key);
                if ($stored === null || ($value !== null && (string) $stored !== $value)) { return null; }
                if ($value === null) {
                    $scopeNative = $nativeId ?? $stored;
                    $scopeParcel = $parcelId ?? $stored;
                    if (!self::validScope($order, $scopeNative, $scopeParcel)) { return null; }
                }
            } elseif ($value === null) { return null; }
            $result[] = $candidate;
        }
        return $result;
    }

    private static function uniqueRoot(?array $legacy, ?array $scoped): ?int
    {
        if ($legacy === null || $scoped === null) { return null; }
        $ids = array_values(array_unique(array_merge($legacy, $scoped)));
        return count($ids) === 1 ? $ids[0] : null;
    }

    private static function validScope(\WC_Order $root, int $nativeId, int $parcelId): bool
    {
        require_once __DIR__ . '/BasalamOrderContext.php';
        require_once __DIR__ . '/BasalamParcelOrder.php';
        if (self::metaId($root, '_salam_gate_native_link_' . $parcelId) !== $nativeId
            || self::metaId($root, '_salam_gate_native_origin_' . $nativeId) !== $parcelId) { return false; }
        $scope = BasalamParcelOrder::stored($root, $nativeId);
        if ($scope === null || self::positiveId($scope->get_meta('_salam_gate_parcel_id')) !== $parcelId
            || self::positiveId($scope->get_meta('_salam_gate_external_id')) !== $nativeId
            || $scope->get_meta('_salam_gate_market') !== 'basalam') { return false; }
        // Two valid or damaged scoped parcel identities may not claim the same SalamGate ID.
        if (method_exists($root, 'get_meta_data')) {
            foreach ($root->get_meta_data() as $meta) {
                $data = $meta->get_data();
                if (preg_match('/^_salam_gate_bp_([1-9][0-9]*)_salam_gate_parcel_id$/D', (string) ($data['key'] ?? ''), $match)
                    && self::positiveId($data['value'] ?? null) === $parcelId && self::positiveId($match[1]) !== $nativeId) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function metaId(\WC_Order $root, string $key): ?int
    {
        if (method_exists($root, 'get_meta_data')) {
            $count = 0;
            foreach ($root->get_meta_data() as $meta) {
                if (($meta->get_data()['key'] ?? null) === $key && ++$count > 1) { return null; }
            }
        }
        return self::positiveId($root->get_meta($key));
    }

    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}
