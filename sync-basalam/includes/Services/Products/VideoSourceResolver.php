<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class VideoSourceResolver
{
    private const AUTO_DETECT_META_KEYS = [
        '_woodmart_product_video',
        '_woodmart_product_video_gallery',
        '_product_video',
        '_product_video_gallery',
        '_yith_wcpv_product_video',
        '_elementor_video_url',
        '_xstore_product_video',
        'featured_video',
        '_featured_video',
    ];

    public static function resolveMetaKey(): ?string
    {
        $settings = syncBasalamSettings();
        $source = $settings->getSettings(SettingsConfig::VIDEO_SOURCE);

        if ($source === 'plugin_box') {
            return ProductMetaKey::basalamProductVideo();
        }

        $mode = $settings->getSettings(SettingsConfig::VIDEO_INHERIT_MODE);

        if ($mode === 'manual') {
            $key = trim((string) $settings->getSettings(SettingsConfig::VIDEO_META_KEY));

            return $key !== '' ? $key : null;
        }

        return null;
    }

    public static function resolveValue(int $productId): ?string
    {
        $settings = syncBasalamSettings();
        $source = $settings->getSettings(SettingsConfig::VIDEO_SOURCE);

        if ($source === 'plugin_box') {
            return self::readMeta($productId, ProductMetaKey::basalamProductVideo());
        }

        $mode = $settings->getSettings(SettingsConfig::VIDEO_INHERIT_MODE);

        if ($mode === 'manual') {
            $key = trim((string) $settings->getSettings(SettingsConfig::VIDEO_META_KEY));

            return $key !== '' ? self::readMeta($productId, $key) : null;
        }

        foreach (self::AUTO_DETECT_META_KEYS as $key) {
            $value = self::readMeta($productId, $key);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    public static function isUsingPluginBox(): bool
    {
        $source = syncBasalamSettings()->getSettings(SettingsConfig::VIDEO_SOURCE);

        return $source === 'plugin_box';
    }

    public static function autoDetectMetaKeys(): array
    {
        return self::AUTO_DETECT_META_KEYS;
    }

    private static function readMeta(int $productId, string $key): ?string
    {
        $value = get_post_meta($productId, $key, true);

        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === '' || $value === null || $value === false) {
            return null;
        }

        return (string) $value;
    }
}
