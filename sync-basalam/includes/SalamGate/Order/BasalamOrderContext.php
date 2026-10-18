<?php

namespace SyncBasalam\SalamGate\Order;

use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

require_once __DIR__ . '/BasalamParcelReader.php';

/**
 * شناسه سفارش باسلام (invoice_id) و شناسه مستقل مرسوله غرفه هرگز جایگزین هم نمی‌شوند.
 */
class BasalamOrderContext
{
    public static function isBasalamOrder(int $orderId): bool
    {
        // Ordinary API imports do not contain a payment_id. The authenticated
        // original-order mapping, not the presence of a payment identifier,
        // proves the source and keeps these orders off the website payment route.
        return $orderId > 0 && self::getInvoiceId($orderId) !== null;
    }

    /**
     * شناسه سفارش باسلام (invoice_id)، برای خواندن جزئیات سفارش؛ نه ثبت مرسوله.
     */
    public static function getInvoiceId(int $orderId): ?int
    {
        global $wpdb;

        if (class_exists(\SyncBasalam\Utilities\OrderManagerUtilities::class)) {
            $invoiceId = \SyncBasalam\Utilities\OrderManagerUtilities::getInvoiceId($wpdb, $orderId);

            return self::positiveId($invoiceId);
        }

        $table = $wpdb->prefix . 'sync_basalam_payments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, not user input.
        $invoiceId = $wpdb->get_var(
            $wpdb->prepare("SELECT invoice_id FROM {$table} WHERE order_id = %d LIMIT 1", $orderId)
        );

        return self::positiveId($invoiceId);
    }

    /** Cached explicit mapping only: rendering/sync does not make per-order HTTP calls. */
    public static function getParcelId(int $orderId): ?int
    {
        if (!function_exists('wc_get_order')) {
            return null;
        }
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return null;
        }
        $mapping = $order->get_meta('_salam_gate_basalam_mapping');
        $invoiceId = self::getInvoiceId($orderId);
        $vendorId = self::connectedVendorId();
        if (!is_array($mapping)
            || $invoiceId === null || $vendorId === null
            || self::positiveId($mapping['invoice_id'] ?? null) !== $invoiceId
            || self::positiveId($mapping['vendor_id'] ?? null) !== $vendorId
            || self::positiveId($mapping['order_id'] ?? null) !== $orderId) {
            return null;
        }
        return self::positiveId($mapping['parcel_id'] ?? null);
    }

    /** Resolve before sending; no fallback to invoice_id, root id, or the first parcel. */
    public static function resolveParcelId(int $orderId, bool $fresh = false, ?array &$snapshots = null): int
    {
        $snapshots = null;
        $known = self::getParcelId($orderId);
        if (!$fresh && $known !== null) {
            return $known;
        }
        $invoiceId = self::getInvoiceId($orderId);
        $vendorId = self::connectedVendorId();
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if ($invoiceId === null || $vendorId === null || !$order instanceof \WC_Order) {
            throw self::mappingError('ارتباط این سفارش با غرفه باسلام مشخص نیست؛ سفارش را دوباره بررسی کنید.');
        }
        $detail = BasalamParcelReader::order($vendorId, $invoiceId);
        $parcelId = self::positiveId($detail['parcel_detail']['parcel_id'] ?? null);
        if ($parcelId === null
            || (array_key_exists('id', $detail) && self::positiveId($detail['id']) !== $invoiceId)
            || (isset($detail['vendor']['id']) && self::positiveId($detail['vendor']['id']) !== $vendorId)) {
            throw self::mappingError('شناسه مرسوله این سفارش در باسلام مشخص نیست؛ ارسال را از پنل باسلام بررسی کنید.');
        }
        $parcel = BasalamParcelReader::parcel($parcelId);
        if (self::positiveId($parcel['id'] ?? null) !== $parcelId
            || self::positiveId($parcel['vendor']['id'] ?? null) !== $vendorId
            || (isset($parcel['order']['id']) && self::positiveId($parcel['order']['id']) !== $invoiceId)) {
            throw self::mappingError('مرسوله دریافت‌شده با سفارش و غرفه متصل مطابقت ندارد؛ ارسال انجام نشد.');
        }

        // Initially support exactly the imported parcel's complete item set.
        // Missing imports or a multi-parcel subset must not silently ship as one.
        $expected = [];
        foreach ($order->get_items() as $item) {
            $itemId = self::positiveId($order->get_meta('_sync_basalam_item_id_' . $item->get_id()));
            if ($itemId === null || isset($expected[$itemId])) {
                throw self::mappingError('ارتباط بعضی اقلام سفارش با باسلام مشخص نیست؛ ابتدا اقلام سفارش را بررسی کنید.');
            }
            $expected[$itemId] = true;
        }
        $actual = [];
        if (!is_array($parcel['items'] ?? null)) {
            throw self::mappingError('اقلام مرسوله باسلام دریافت نشد؛ دوباره بررسی کنید.');
        }
        foreach ($parcel['items'] as $item) {
            $itemId = is_array($item) ? self::positiveId($item['id'] ?? null) : null;
            if ($itemId === null || isset($actual[$itemId])) {
                throw self::mappingError('شناسه اقلام مرسوله باسلام معتبر نیست؛ دوباره بررسی کنید.');
            }
            $actual[$itemId] = true;
        }
        if ($expected === [] || count($expected) !== count($actual) || array_diff_key($expected, $actual) !== []) {
            throw self::mappingError('اقلام سفارش با یک مرسوله باسلام تطبیق ندارند؛ ارسال این سفارش را از پنل باسلام انجام دهید.');
        }
        $stored = $order->get_meta('_salam_gate_basalam_mapping');
        if ($known !== null && $known !== $parcelId) {
            throw self::mappingError('شناسه مرسوله این سفارش تغییر کرده است؛ پیش از ادامه، ارتباط سفارش را بررسی کنید.');
        }
        $existingParcel = self::positiveId($order->get_meta('_salam_gate_parcel_id'));
        $savedExternal = $order->get_meta('_salam_gate_external_id');
        if ($existingParcel !== null && $savedExternal !== '' && self::positiveId($savedExternal) !== $parcelId) {
            throw self::mappingError('مرسوله قبلی این سفارش با شناسه صحیح باسلام مطابقت ندارد؛ ثبت یا پرداخت مجدد انجام ندهید.');
        }
        $mapping = ['order_id' => $orderId, 'invoice_id' => $invoiceId, 'vendor_id' => $vendorId, 'parcel_id' => $parcelId];
        if ($stored !== $mapping) {
            $order->update_meta_data('_salam_gate_basalam_mapping', $mapping);
            $order->save();
        }
        // Reuse these same freshly verified reads for status/source preflight, not two more HTTP calls.
        $snapshots = ['order' => $detail, 'parcel' => $parcel];
        return $parcelId;
    }

    private static function connectedVendorId(): ?int
    {
        return function_exists('syncBasalamSettings')
            ? self::positiveId(syncBasalamSettings()->getSettings('vendor_id')) : null;
    }

    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function mappingError(string $message): SalamGateException
    {
        return new SalamGateException($message, 422, [], SalamGateException::KIND_VALIDATION);
    }

    /**
     * شناسه شهر گیرنده ذخیره‌شده پلاگین پایه (هم‌راستا با شناسه‌های شهر بسالم).
     */
    public static function getReceiverCityId(int $orderId): ?int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sync_basalam_payments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; identifier from $wpdb->prefix, not user input.
        $cityId = $wpdb->get_var(
            $wpdb->prepare("SELECT city_id FROM {$table} WHERE order_id = %d AND city_id > 0 LIMIT 1", $orderId)
        );

        return $cityId !== null && $cityId !== '' ? (int) $cityId : null;
    }
}
