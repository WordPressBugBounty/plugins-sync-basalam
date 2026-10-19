<?php

namespace SyncBasalam\SalamGate;

use SyncBasalam\SalamGate\Services\ShopsService;
use SyncBasalam\SalamGate\Services\SiteActivation;
use SyncBasalam\SalamGate\Admin\AccountStateRouter;

defined('ABSPATH') || exit;

/**
 * آیا قابلیت سلام‌گیت برای این سایت فعال است؟ (توکن + غرفه فعال)
 */
class FeatureAvailability
{
    /** Cache-only presentation guard: no OAuth renewal or external HTTP in order rendering. */
    public static function isVisible(): bool
    {
        return AccountStateRouter::resolveForPage()['state'] === AccountStateRouter::STATE_ACTIVE;
    }

    public static function isAvailable(): bool
    {
        return self::activeShop() !== null;
    }

    /**
     * غرفه فعال یا null (توکن نباشد/غرفه فعالی نباشد/خطای API).
     */
    public static function activeShop(): ?array
    {
        if (!SiteActivation::isEnabled()) return null;
        try {
            if (ClientFactory::make()->token() === null) {
                return null;
            }

            return (new ShopsService())->getActiveShop();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public static function activeShopId(): ?int
    {
        $shop = self::activeShop();

        return $shop ? (int) ($shop['id'] ?? 0) ?: null : null;
    }
}
