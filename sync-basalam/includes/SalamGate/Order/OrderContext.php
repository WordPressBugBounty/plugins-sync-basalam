<?php

namespace SyncBasalam\SalamGate\Order;

defined('ABSPATH') || exit;

/**
 * کانتکست بازار سفارش: بسالم (از جدول payments) یا وب‌سایت خودت.
 */
class OrderContext
{
    public const MARKET_BASALAM = 'basalam';
    public const MARKET_WEBSITE = 'website';

    public static function shipment(object $order): ShipmentOrderPort
    {
        if ($order instanceof ShipmentOrderPort) return $order;
        if ($order instanceof BasalamParcelOrder) return new BasalamParcelAdapter($order);
        if ($order instanceof \WC_Order) return new WooOrderAdapter($order);
        throw new \TypeError('Unsupported shipment source.');
    }

    /** Preserve the WC_Order|BasalamParcelOrder contract without PHP 8 union syntax. */
    public static function assertSupportedOrder(object $order): void
    {
        if (!$order instanceof \WC_Order && !$order instanceof BasalamParcelOrder) {
            throw new \TypeError('Shipping requires a WooCommerce order or a native Basalam parcel order.');
        }
    }

    public static function marketFor(int $orderId): string
    {
        return BasalamOrderContext::isBasalamOrder($orderId)
            ? self::MARKET_BASALAM
            : self::MARKET_WEBSITE;
    }

    /**
     * شناسه خارجی مرسوله: باسلام → شناسه تأییدشده مرسوله؛ وب‌سایت → هویت یکتای سایت/سفارش.
     */
    public static function externalIdFor(int $orderId, ?string $market = null): ?int
    {
        $market = $market ?? self::marketFor($orderId);

        if ($market === self::MARKET_BASALAM) {
            return BasalamOrderContext::getParcelId($orderId);
        }

        return $market === self::MARKET_WEBSITE ? WebsiteOrderIdentity::forOrder($orderId) : null;
    }

    public static function externalIdForOrder($order, ?string $market = null): ?int
    {
        return $order instanceof BasalamParcelOrder
            ? $order->getNativeParcelId() : self::externalIdFor((int) $order->get_id(), $market);
    }
}
