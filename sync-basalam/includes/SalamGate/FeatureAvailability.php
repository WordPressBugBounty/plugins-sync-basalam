<?php

namespace SyncBasalam\SalamGate;

use SyncBasalam\SalamGate\Services\ShopsService;

defined('ABSPATH') || exit;

/**
 * آیا قابلیت سلام‌گیت برای این سایت فعال است؟ (توکن + غرفه فعال)
 */
class FeatureAvailability
{
    public static function isAvailable(): bool
    {
        return self::activeShop() !== null;
    }

    /**
     * غرفه فعال یا null (توکن نباشد/غرفه فعالی نباشد/خطای API).
     */
    public static function activeShop(): ?array
    {
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
