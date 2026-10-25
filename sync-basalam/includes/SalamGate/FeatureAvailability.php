<?php

namespace SyncBasalam\SalamGate;

use SyncBasalam\SalamGate\Services\ShopsService;
use SyncBasalam\SalamGate\Services\SiteActivation;
use SyncBasalam\SalamGate\Services\ConnectionCache;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\SalamGate\Admin\AccountStateRouter;

defined('ABSPATH') || exit;

/**
 * آیا قابلیت سلام‌گیت برای این سایت فعال است؟ (توکن + غرفه فعال)
 */
class FeatureAvailability
{
    /** Last verified presentation state; cache expiry never starts an order-page probe.
     * Domain operations MUST continue through activeShop() and its fresh-cache guard. */
    public static function isVisible(): bool
    {
        if (!SiteActivation::isEnabled()) return false;
        $token = syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);
        if (!is_string($token) || trim($token) === '') return false;
        $cached = ConnectionCache::lastForTokenForDisplay(ShopsService::CACHE_NAME, $token);
        $shops = $cached['data']['shops'] ?? null;
        return is_array($shops) && AccountStateRouter::stateForShops($shops) === AccountStateRouter::STATE_ACTIVE;
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
