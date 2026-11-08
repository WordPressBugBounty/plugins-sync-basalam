<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Application\BulkShipping;

defined('ABSPATH') || exit;

/** WordPress HTTP adapter; public action names remain backward compatible. */
class BulkShippingActions
{
    public const MAX_BATCH = 20;
    public const SESSION_TTL = 1800;
    private const SESSION_PREFIX = 'salam_gate_bulk_shipping_';

    public static function bootstrap(): void
    {
        self::guard();
        $workflow = new BulkShipping($_POST);
        ActionResponder::run(static fn (): array => $workflow->bootstrap());
    }

    public static function item(): void
    {
        self::guard();
        $workflow = new BulkShipping($_POST);
        ActionResponder::run(static fn (): array => $workflow->item());
    }

    private static function guard(): void
    {
        ActionGuard::forController(self::class, null);
    }
}
