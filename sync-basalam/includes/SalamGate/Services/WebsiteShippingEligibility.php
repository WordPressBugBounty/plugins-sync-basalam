<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Admin\OrderMetabox;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\SalamGateException;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined('ABSPATH') || exit;

/** Mutation-only eligibility; paid receipts, labels and reconciliation do not use this fence. */
final class WebsiteShippingEligibility
{
    /** Read the primary persisted status under the lease; Woo's order factory can return a cached object. */
    public static function assertForOrder($order): void
    {
        if (!$order instanceof \WC_Order || OrderContext::marketFor((int) $order->get_id()) !== 'website') { return; }
        $status = self::persistedStatus((int) $order->get_id());
        if ($status === null || !OrderMetabox::isActionableStatus('website', $status)) {
            throw new SalamGateException('وضعیت این سفارش اجازه ارسال ندارد؛ سفارش‌های لغوشده، ناموفق یا مستردشده قابل ارسال نیستند.',
                422, [['location' => 'order.status', 'message' => 'order-not-actionable']]);
        }
    }

    /**
     * wpdb performs a bounded SQL read rather than consulting WC_Order_Factory/OrderCache.
     * The HPOS primary table is authoritative even when its CPT synchronization lags.
     * Read-only: no cache eviction, order/meta write or fallback to a stale object.
     */
    private static function persistedStatus(int $orderId): ?string
    {
        global $wpdb;
        if ($orderId < 1 || !is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_row')) { return null; }
        try {
            $hpos = class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
            $table = $hpos ? (is_string($wpdb->prefix ?? null) ? $wpdb->prefix . 'wc_orders' : null) : ($wpdb->posts ?? null);
            // Identifiers are from the trusted WordPress database object, never request input.
            if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) { return null; }
            $idColumn = $hpos ? 'id' : 'ID';
            $statusColumn = $hpos ? 'status' : 'post_status';
            $typeColumn = $hpos ? 'type' : 'post_type';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Validated authoritative table/columns; bounded fresh mutation fence.
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT {$idColumn} AS order_id, {$statusColumn} AS order_status, {$typeColumn} AS order_type FROM `{$table}` WHERE {$idColumn} = %d LIMIT 1",
                $orderId
            ));
            if (($wpdb->last_error ?? '') !== '' || (!$row instanceof \stdClass && !is_array($row))) { return null; }
            $row = (array) $row;
            if (ParcelService::positiveId($row['order_id'] ?? null) !== $orderId || ($row['order_type'] ?? null) !== 'shop_order'
                || !is_string($row['order_status'] ?? null)) { return null; }
            $status = $row['order_status'];
            return str_starts_with($status, 'wc-') ? substr($status, 3) : $status;
        } catch (\Throwable $exception) {
            // A missing primary datastore cannot authorize an external mutation.
            return null;
        }
    }

    public static function assertForParcel(array $parcel): void
    {
        if (($parcel['market'] ?? '') !== 'website') { return; }
        $order = WebsitePaymentGuard::linkedOrderForParcel($parcel);
        if ($order !== null) { self::assertForOrder($order); }
    }

    public static function blockForOrder($order): ?array
    {
        try { self::assertForOrder($order); }
        catch (SalamGateException $exception) {
            return ['ok' => false, 'payload' => ['message' => $exception->firstMessage(),
                'code' => 'order_not_actionable', 'kind' => $exception->getKind(), 'errors' => $exception->getErrors(),
                'request_dispatched' => false]];
        }
        return null;
    }
}
