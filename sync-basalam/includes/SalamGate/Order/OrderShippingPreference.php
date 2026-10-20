<?php

namespace SyncBasalam\SalamGate\Order;

defined('ABSPATH') || exit;

/** Read-only presentation of the method saved on the source order, never a SalamGate quote. */
final class OrderShippingPreference
{
    /** @param \WC_Order|BasalamParcelOrder $order Basalam snapshots must be authenticated. */
    public static function forOrder(object $order, string $market, ?array $snapshots = null): ?string
    {
        OrderContext::assertSupportedOrder($order);
        if ($market === OrderContext::MARKET_BASALAM) {
            if (!is_array($snapshots['order'] ?? null) || !is_array($snapshots['parcel'] ?? null)) {
                return null;
            }
            return self::fromBasalamSnapshots($snapshots['order'], $snapshots['parcel']);
        }
        if (!$order instanceof \WC_Order) {
            return null;
        }
        $titles = [];
        foreach ($order->get_shipping_methods() as $method) {
            if (!$method instanceof \WC_Order_Item_Shipping) { continue; }
            $title = self::title($method->get_method_title());
            if ($title !== null && !in_array($title, $titles, true)) { $titles[] = $title; }
        }
        return $titles !== [] ? implode('، ', $titles) : null;
    }

    public static function fromBasalamSnapshots(array $orderDetail, array $parcel): ?string
    {
        // The native order-processing response retains default alongside current.
        // SalamGate can change current on set-posted; it must never become this hint.
        if (array_key_exists('shipping_method', $parcel)) {
            $method = $parcel['shipping_method'];
            return is_array($method) && is_array($method['default'] ?? null)
                ? self::sourceTitle($method['default']) : null;
        }

        // Older primary-order responses have only parcel_detail.shipping_method.
        // A different native child must never borrow the primary parcel's method.
        $detail = is_array($orderDetail['parcel_detail'] ?? null) ? $orderDetail['parcel_detail'] : [];
        $parcelId = self::positiveId($parcel['id'] ?? null);
        if ($parcelId === null || self::positiveId($detail['parcel_id'] ?? null) !== $parcelId) {
            return null;
        }
        return is_array($detail['shipping_method'] ?? null) ? self::sourceTitle($detail['shipping_method']) : null;
    }

    private static function sourceTitle(array $method): ?string
    {
        // 7062 is the fulfillment override written by SalamGate itself.
        if (self::positiveId($method['id'] ?? null) === 7062) { return null; }
        return self::title($method['title'] ?? null);
    }

    private static function title($value): ?string
    {
        if (!is_string($value)) { return null; }
        $value = wp_strip_all_tags($value);
        $value = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value);
        $value = is_string($value) ? preg_replace('/\s+/u', ' ', $value) : null;
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : null;
    }

    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}
