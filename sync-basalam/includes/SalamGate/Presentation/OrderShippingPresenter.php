<?php

namespace SyncBasalam\SalamGate\Presentation;

use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;

defined('ABSPATH') || exit;

final class OrderShippingPresenter
{
    public static function forOrder(\WC_Order $order): array
    {
        // Scoped Basalam parcels can cover only part of a parent order. Never let
        // one child's receipt, or copied legacy metadata, mark the entire order sent.
        if (class_exists(BasalamParcelOrder::class) && BasalamParcelOrder::hasScopes($order)) {
            return self::scopedState($order);
        }
        if (!class_exists(BasalamParcelOrder::class)) {
            foreach ($order->get_meta_data() as $meta) {
                if (preg_match('/^_salam_gate_bp_[1-9][0-9]*_/', (string) ($meta->get_data()['key'] ?? ''))) {
                    return self::review('بررسی مرسوله‌های مستقل این سفارش به نسخه جدید افزونه نیاز دارد.');
                }
            }
        }

        $parcelId = self::positiveId($order->get_meta('_salam_gate_parcel_id'));
        $rawStatus = $order->get_meta('_salam_gate_status');
        $status = self::positiveId($rawStatus);
        if ($parcelId !== null && $status !== null && ParcelStatuses::isValid($status)) {
            return self::parcelState($status);
        }
        if (!self::emptyMeta($rawStatus) && ($parcelId === null || $status === null)) {
            return self::review('ارتباط وضعیت ذخیره‌شده با مرسوله باید بررسی شود.');
        }

        // These are explicit shipment statuses synchronized from the native order,
        // unlike Woo's generic "completed" status (which also covers virtual goods).
        if (in_array($order->get_status(), ['bslm-shipping', 'bslm-completed'], true)) {
            return self::state('ارسال شده', 'sent', 'ارسال این سفارش در وضعیت سفارش باسلام ثبت شده است.');
        }
        if ($parcelId !== null) {
            if ($status !== null || !self::emptyMeta($order->get_meta('_salam_gate_tracking_code'))) {
                return self::review('وضعیت تحویل مرسوله به پست هنوز تأیید نشده است؛ بارکد به‌تنهایی نشانه ارسال نیست.');
            }
            return self::state('ارسال نشده', 'pending', $order->get_meta('_salam_gate_pay_unknown')
                ? 'نتیجه پرداخت این مرسوله در حال بررسی است.'
                : 'مرسوله ثبت شده است؛ تحویل آن به پست هنوز تأیید نشده است.');
        }
        return self::state('ارسال نشده', 'pending', 'ارسالی برای این سفارش ثبت نشده است.');
    }

    private static function parcelState(int $status): array
    {
        switch ($status) {
            case ParcelStatuses::SHIPPED:
            case ParcelStatuses::HANDED_TO_POSTMAN:
                return self::state('ارسال شده', 'sent', ParcelStatuses::title($status));
            case ParcelStatuses::DELIVERED:
                return self::state('تحویل شده', 'delivered', 'مرسوله به گیرنده تحویل داده شده است.');
            case ParcelStatuses::RETURNED_TO_SENDER:
                return self::state('مرجوع شده', 'returned', 'مرسوله ارسال شده و به فرستنده برگشته است.');
            case ParcelStatuses::VOIDED:
                return self::state('ارسال لغو شده', 'cancelled', 'مرسوله ابطال شده است.');
            case ParcelStatuses::POSTAL_BARCODE:
                return self::state('ارسال نشده', 'ready', 'بارکد صادر شده است؛ مرسوله هنوز به پست تحویل نشده است.');
            default:
                return self::state('ارسال نشده', 'pending', 'مرسوله در انتظار تکمیل پرداخت و تحویل به پست است.');
        }
    }

    private static function scopedState(\WC_Order $order): array
    {
        $entries = BasalamParcelOrder::entries($order);
        // A broken or stale child identity cannot disappear behind a valid sibling.
        foreach ($order->get_meta_data() as $meta) {
            $data = $meta->get_data();
            if (preg_match('/^_salam_gate_bp_([1-9][0-9]*)_/', (string) ($data['key'] ?? ''), $match)
                && !isset($entries[$match[1]])) {
                return self::review('ارتباط یکی از مرسوله‌های این سفارش نیاز به بررسی دارد.');
            }
        }
        $expected = [];
        foreach ($order->get_items() as $item) {
            $expected[(int) $item->get_id()] = (int) $item->get_quantity();
        }
        $sent = [];
        $statuses = [];
        $uncertain = false;
        foreach ($entries as $scope) {
            $rawStatus = $scope->get_meta('_salam_gate_status');
            $status = self::positiveId($rawStatus);
            $parcelId = self::positiveId($scope->get_meta('_salam_gate_parcel_id'));
            if ($parcelId !== null && (self::positiveId($order->get_meta('_salam_gate_native_link_' . $parcelId)) !== $scope->getNativeParcelId()
                || self::positiveId($order->get_meta('_salam_gate_native_origin_' . $scope->getNativeParcelId())) !== $parcelId)) {
                return self::review('ارتباط رسید با مرسوله این سفارش نیاز به بررسی دارد.');
            }
            $known = $parcelId !== null && $status !== null && ParcelStatuses::isValid($status);
            $statuses[] = $known ? $status : null;
            $uncertain = $uncertain || (!$known && (!self::emptyMeta($rawStatus)
                || !self::emptyMeta($scope->get_meta('_salam_gate_tracking_code'))));
            if (!$known || !self::wasShipped($status)) { continue; }
            foreach ($scope->getAllocation() as $line) {
                $id = $line['item_id'];
                $sent[$id] = ($sent[$id] ?? 0) + $line['quantity'];
                if (!isset($expected[$id]) || $sent[$id] > $expected[$id]) {
                    return self::review('تعداد اقلام مرسوله‌ها با سفارش مطابقت ندارد.');
                }
            }
        }
        if ($uncertain) { return self::review('وضعیت یکی از مرسوله‌های این سفارش هنوز تأیید نشده است.'); }
        if ($sent !== []) {
            foreach ($expected as $id => $quantity) {
                if (($sent[$id] ?? 0) < $quantity) {
                    return self::state('بخشی ارسال شده', 'partial', 'بخشی از اقلام سفارش ارسال شده‌اند؛ ارسال همه اقلام هنوز تأیید نشده است.');
                }
            }
            if ($expected === []) { return self::review('اقلام سفارش برای بررسی ارسال کامل در دسترس نیست.'); }
            if (count(array_unique($statuses, SORT_REGULAR)) === 1) {
                return self::parcelState($statuses[0]);
            }
            return self::state('ارسال شده', 'sent', 'ارسال همه اقلام سفارش تأیید شده است؛ وضعیت هر مرسوله در صفحه سفارش نمایش داده می‌شود.');
        }
        if ($statuses !== [] && count(array_unique($statuses, SORT_REGULAR)) === 1
            && $statuses[0] !== null) {
            return self::parcelState($statuses[0]);
        }
        return self::state('ارسال نشده', 'pending', 'تحویل اقلام این سفارش به پست هنوز تأیید نشده است.');
    }

    private static function wasShipped(int $status): bool
    {
        return in_array($status, [ParcelStatuses::SHIPPED, ParcelStatuses::DELIVERED,
            ParcelStatuses::HANDED_TO_POSTMAN, ParcelStatuses::RETURNED_TO_SENDER], true);
    }

    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function emptyMeta($value): bool
    {
        return in_array($value, ['', null, false, 0, '0'], true);
    }

    private static function state(string $label, string $tone, string $description): array
    {
        return compact('label', 'tone', 'description');
    }

    private static function review(string $description): array
    {
        return self::state('نیاز به بررسی', 'review', $description);
    }
}
