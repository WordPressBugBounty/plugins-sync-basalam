<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\SalamGate\FeatureAvailability;

defined('ABSPATH') || exit;

/** Compatibility endpoint for old open tabs. Reading Woo orders must never probe SalamGate. */
final class OrderFeatureGate
{
    public static function availability(): void
    {
        Ajax\ActionGuard::forController(self::class);
        // Even an older browser script cannot trigger a remote account read.
        \SyncBasalam\SalamGate\Admin\Ajax\ResponseBoundary::success(['available' => FeatureAvailability::isVisible()]);
    }
}
