<?php

namespace SyncBasalam\SalamGate\Order;

defined('ABSPATH') || exit;

require_once __DIR__ . '/WebsiteOrderIdentity.php';

/**
 * کانتکست بازار سفارش: بسالم (از جدول payments) یا وب‌سایت خودت.
 */
class OrderContext
{
    public const MARKET_BASALAM = 'basalam';
    public const MARKET_WEBSITE = 'website';

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
