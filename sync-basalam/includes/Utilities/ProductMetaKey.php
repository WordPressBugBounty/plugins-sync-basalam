<?php

namespace SyncBasalam\Utilities;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class ProductMetaKey
{
    public const PRODUCT_ID = 'sync_basalam_product_id';
    public const PRODUCT_SYNC_STATUS = 'sync_basalam_product_sync_status';
    public const PRODUCT_STATUS = 'sync_basalam_product_status';
    public const PRODUCT_VIDEO = '_sync_basalam_product_video';

    public static function basalamProductVideo(): string
    {
        return self::PRODUCT_VIDEO;
    }

    public static function basalamProductId($vendorId = null): string
    {
        return self::build(self::PRODUCT_ID, $vendorId);
    }

    public static function basalamProductSyncStatus($vendorId = null): string
    {
        return self::build(self::PRODUCT_SYNC_STATUS, $vendorId);
    }

    public static function basalamProductStatus($vendorId = null): string
    {
        return self::build(self::PRODUCT_STATUS, $vendorId);
    }

    public static function basalamProductMetaKeys($vendorId = null): array
    {
        return [
            self::basalamProductId($vendorId),
            self::basalamProductSyncStatus($vendorId),
            self::basalamProductStatus($vendorId),
        ];
    }

    private static function build(string $baseKey, $vendorId = null): string
    {
        $resolvedVendorId = self::normalizeVendorId($vendorId ?? self::getVendorIdFromSettings());

        if ($resolvedVendorId === '') {
            return $baseKey;
        }

        return "{$baseKey}_{$resolvedVendorId}";
    }

    private static function getVendorIdFromSettings()
    {
        return syncBasalamSettings()->getSettings(SettingsConfig::VENDOR_ID);
    }

    private static function normalizeVendorId($vendorId): string
    {
        if ($vendorId === null || $vendorId === '') {
            return '';
        }

        return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $vendorId);
    }
}
