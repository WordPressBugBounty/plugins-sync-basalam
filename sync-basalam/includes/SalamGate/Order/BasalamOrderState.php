<?php

namespace SyncBasalam\SalamGate\Order;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ConnectionCache;

defined('ABSPATH') || exit;

require_once __DIR__ . '/BasalamOrderContext.php';

/** Original order state, distinct from a postal receipt or Woo's local financial state. */
final class BasalamOrderState
{
    public const META_KEY = '_salam_gate_basalam_order_state';
    public const PREPARING = 3237;
    public const WAITING_VENDOR = 3739;
    public const SENT = [3238, 3195];
    public const CANCELLED = [3067, 3233];

    /** Authenticated GET only. Never changes Woo status, stock, refunds, or the native order. */
    public static function refresh(\WC_Order $order): array
    {
        $invoice = BasalamOrderContext::getInvoiceId((int) $order->get_id());
        $vendor = self::vendorId();
        if ($invoice === null || $vendor === null) { throw self::error('ارتباط سفارش با غرفه باسلام مشخص نیست؛ اتصال ووسلام را بررسی کنید.'); }
        $connection = ConnectionCache::identityHash();
        $detail = BasalamParcelReader::order($vendor, $invoice);
        ConnectionCache::assertCurrentIdentity($connection);
        if (self::vendorId() !== $vendor || BasalamOrderContext::getInvoiceId((int) $order->get_id()) !== $invoice) {
            throw self::error('ارتباط سفارش یا غرفه هنگام بررسی وضعیت تغییر کرد؛ دوباره تلاش کنید.');
        }
        return self::remember($order, $detail, $vendor, $invoice);
    }

    public static function fromSnapshot(array $detail, int $vendor, int $invoice): array
    {
        if ($vendor < 1 || $invoice < 1 || self::id($detail['id'] ?? null) !== $invoice
            || (isset($detail['vendor']['id']) && self::id($detail['vendor']['id']) !== $vendor)) {
            throw self::error('وضعیت دریافت‌شده با سفارش و غرفه متصل مطابقت ندارد؛ ارسال انجام نشد.');
        }
        $status = self::id($detail['status']['id'] ?? null);
        $titles = [3067 => 'ردشده', 3233 => 'لغوشده', 3739 => 'در انتظار تأیید غرفه‌دار',
            3237 => 'در حال آماده‌سازی', 3238 => 'ارسال‌شده', 3195 => 'تکمیل‌شده'];
        $title = is_string($detail['status']['title'] ?? null) ? trim($detail['status']['title']) : '';
        if ($title === '' || strlen($title) > 400 || preg_match('/[\x00-\x1f\x7f]/', $title)) {
            $title = $titles[$status] ?? 'وضعیت نامشخص';
        }
        $cancelled = in_array($status, self::CANCELLED, true);
        $allowed = $status === self::PREPARING || in_array($status, self::SENT, true);
        $reason = $cancelled ? 'این سفارش در باسلام لغو یا رد شده است؛ مرسوله جدید ثبت نکنید و هزینه ارسال را پرداخت نکنید.'
            : ($status === self::WAITING_VENDOR ? 'ابتدا سفارش را در پنل باسلام تأیید کنید؛ سپس وضعیت را دوباره بررسی کنید.'
                : (!$allowed ? 'وضعیت قابل ارسال سفارش باسلام تأیید نشد؛ قبل از ثبت یا پرداخت، وضعیت را در پنل باسلام بررسی کنید.' : null));
        return ['basalam_order_id' => $invoice, 'vendor_id' => $vendor, 'status_id' => $status,
            'status_title' => $title, 'cancelled' => $cancelled, 'shipping_allowed' => $allowed,
            'blocked_reason' => $reason, 'checked_at' => time()];
    }

    /** @param \WC_Order|BasalamParcelOrder $order Cache only display data, never permission to pay. */
    public static function remember(object $order, array $detail, int $vendor, int $invoice): array
    {
        OrderContext::assertSupportedOrder($order);
        $root = $order instanceof BasalamParcelOrder ? $order->getRoot() : $order;
        if (BasalamOrderContext::getInvoiceId((int) $root->get_id()) !== $invoice || self::vendorId() !== $vendor) {
            throw self::error('ارتباط سفارش باسلام تغییر کرده است؛ وضعیت را دوباره بررسی کنید.');
        }
        $state = self::fromSnapshot($detail, $vendor, $invoice);
        $root->update_meta_data(self::META_KEY, $state);
        // Metadata only: no order status/item/stock saves or cancellation callbacks.
        $root->save_meta_data();
        return $state;
    }

    public static function cached(\WC_Order $order): ?array
    {
        $state = $order->get_meta(self::META_KEY);
        if (!is_array($state)) { return null; }
        $invoice = BasalamOrderContext::getInvoiceId((int) $order->get_id());
        $vendor = self::vendorId();
        if (!is_array($state) || $invoice === null || $vendor === null
            || self::id($state['basalam_order_id'] ?? null) !== $invoice
            || self::id($state['vendor_id'] ?? null) !== $vendor
            || !is_int($state['checked_at'] ?? null) || $state['checked_at'] > time()
            || !is_bool($state['cancelled'] ?? null) || !is_bool($state['shipping_allowed'] ?? null)
            || !is_string($state['status_title'] ?? null)) { return null; }
        return $state;
    }

    /** Shared policy for discovery, drafts, stateful rate calls, and financial preflight. */
    public static function blockedReason(array $parent, array $parcel, bool $scoped = false): ?string
    {
        $parentStatus = self::id($parent['status']['id'] ?? null);
        $parcelStatus = self::id($parcel['status']['id'] ?? null);
        if (in_array($parentStatus, self::CANCELLED, true)) {
            return 'سفارش اصلی باسلام لغو یا رد شده است؛ ثبت مرسوله و پرداخت هزینه ارسال مجاز نیست.';
        }
        if (in_array($parcelStatus, self::CANCELLED, true)) {
            return 'این مرسوله در باسلام لغو یا رد شده است؛ برای آن ثبت یا پرداخت انجام ندهید.';
        }
        if ($parentStatus === self::WAITING_VENDOR || (array_key_exists('is_confirmed', $parcel) && $parcel['is_confirmed'] !== true)) {
            return 'قبل از ارسال، سفارش باسلام را تأیید کنید؛ سپس دوباره وضعیت سفارش را بررسی کنید.';
        }
        if (!array_key_exists('is_send_date', $parcel) || !is_bool($parcel['is_send_date'])) {
            return 'وضعیت ارسال مرسوله باسلام مشخص نیست؛ دوباره سفارش را بررسی کنید.';
        }
        if ($parcel['is_send_date'] || !empty($parcel['is_delivered']) || !empty($parcel['post_receipt'])) {
            return 'این مرسوله قبلاً در باسلام ارسال شده است؛ ثبت یا پرداخت مجدد انجام ندهید.';
        }
        if (!$scoped && in_array($parentStatus, self::SENT, true)) {
            return 'این سفارش در باسلام ارسال یا تکمیل شده است؛ ثبت یا پرداخت مجدد انجام ندهید.';
        }
        if ($parentStatus !== self::PREPARING && !($scoped && in_array($parentStatus, self::SENT, true))) {
            return 'سفارش باسلام در وضعیت قابل ارسال نیست؛ وضعیت آن را در پنل باسلام بررسی کنید.';
        }
        if (($scoped || isset($parcel['status']['id'])) && $parcelStatus !== self::PREPARING) {
            return 'وضعیت مرسوله باسلام قابل ارسال نیست؛ دوباره مشخصات سفارش را دریافت کنید.';
        }
        return null;
    }

    private static function vendorId(): ?int
    {
        return function_exists('syncBasalamSettings') ? self::id(syncBasalamSettings()->getSettings('vendor_id')) : null;
    }

    private static function id($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function error(string $message): SalamGateException
    {
        return new SalamGateException($message, 422, [], SalamGateException::KIND_VALIDATION);
    }
}
