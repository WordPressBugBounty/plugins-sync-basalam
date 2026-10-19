<?php

namespace SyncBasalam\SalamPay;

use SyncBasalam\SalamGate\Utils\OptionLock;

defined('ABSPATH') || exit;

/** Share the existing database lease primitive, independently of shipping ownership. */
final class OrderLock
{
    public static function acquire(int $orderId): ?string
    {
        return OptionLock::acquire('sync_basalam_salampay_order_' . $orderId, 180);
    }

    public static function release(int $orderId, string $lease): void
    {
        OptionLock::release('sync_basalam_salampay_order_' . $orderId, $lease);
    }
}
