<?php
namespace SyncBasalam\SalamGate\Domain;

use SyncBasalam\SalamGate\Order\OrderContext;

defined('ABSPATH') || exit;

final class OrderShippingPolicy
{
    public const ACTIONABLE_STATUSES = ['bslm-wait-vendor', 'bslm-preparation', 'processing'];
    public const WEBSITE_ACTIONABLE_STATUSES = ['on-hold', 'processing', 'completed'];

    public static function isActionableStatus(string $market, string $status): bool
    {
        $statuses = $market === OrderContext::MARKET_WEBSITE
            ? self::WEBSITE_ACTIONABLE_STATUSES
            : self::ACTIONABLE_STATUSES;

        return in_array($status, $statuses, true);
    }
}
