<?php

namespace SyncBasalam\Admin\Settings;

use SyncBasalam\Services\SystemResourceMonitor;

defined('ABSPATH') || exit;

class SettingsManager
{
    public static function getSettings($setting = null)
    {
        $settings = (array) get_option('sync_basalam_settings', SettingsConfig::getDefaultSettings());
        $savedSettings = $settings;
        $settings = self::normalizeProductSyncSettings($settings);

        if ($setting == null || !array_key_exists($setting, $settings)) {
            $defaultSettings = SettingsConfig::getDefaultSettings();

            foreach ($defaultSettings as $key => $value) {
                if (!array_key_exists($key, $settings)) {
                    $settings[$key] = $value;
                }
            }
        }

        if ($setting === null || $settings !== $savedSettings) update_option('sync_basalam_settings', $settings);

        if ($setting === null) return $settings;

        return $settings[$setting] ?? null;
    }

    public static function updateSettings($data)
    {
        $settings = self::sanitizeSettings($data);
        update_option('sync_basalam_settings', $settings);
    }

    public static function sanitizeSettings($input)
    {
        // Older forms and integrations can still toggle both operations together.
        if (array_key_exists(SettingsConfig::SYNC_STATUS_PRODUCT, $input)) {
            foreach ([SettingsConfig::SYNC_STATUS_PRODUCT_CREATE, SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE] as $key) {
                if (!array_key_exists($key, $input)) $input[$key] = $input[SettingsConfig::SYNC_STATUS_PRODUCT];
            }
        }

        $input = array_merge(self::getSettings() ?: [], $input);
        $input = self::normalizeProductSyncSettings($input);

        $input[SettingsConfig::DEFAULT_WEIGHT] = absint($input[SettingsConfig::DEFAULT_WEIGHT]);
        $input[SettingsConfig::DEFAULT_PREPARATION] = absint($input[SettingsConfig::DEFAULT_PREPARATION]);
        $input[SettingsConfig::DISCOUNT_REDUCTION_PERCENT] = min(100, absint($input[SettingsConfig::DISCOUNT_REDUCTION_PERCENT]));
        $interval = absint($input[SettingsConfig::AUTO_FULL_UPDATE_INTERVAL] ?? 0);
        $input[SettingsConfig::AUTO_FULL_UPDATE_INTERVAL] = in_array($interval, [24, 48], true) ? $interval : 0;

        return $input;
    }

    public static function isProductCreationSyncEnabled(): bool
    {
        return (bool) self::getSettings(SettingsConfig::SYNC_STATUS_PRODUCT_CREATE);
    }

    public static function isProductUpdateSyncEnabled(): bool
    {
        return (bool) self::getSettings(SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE);
    }

    private static function normalizeProductSyncSettings(array $settings): array
    {
        $legacyStatus = $settings[SettingsConfig::SYNC_STATUS_PRODUCT] ?? false;

        foreach ([SettingsConfig::SYNC_STATUS_PRODUCT_CREATE, SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE] as $key) {
            $settings[$key] = in_array($settings[$key] ?? $legacyStatus, [true, 1, '1', 'true', 'yes', 'on'], true);
        }

        // Keep the legacy status available to integrations as the aggregate status.
        $settings[SettingsConfig::SYNC_STATUS_PRODUCT] = $settings[SettingsConfig::SYNC_STATUS_PRODUCT_CREATE]
            || $settings[SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE];

        return $settings;
    }

    public static function isProductUpdateSelectionValid(?array $settings = null): bool
    {
        $settings = array_merge(self::getSettings() ?: [], $settings ?: []);

        if (($settings[SettingsConfig::SYNC_PRODUCT_FIELDS] ?? 'all') !== 'custom') return true;

        foreach (SettingsConfig::CUSTOM_PRODUCT_UPDATE_FIELDS as $field) {
            $value = $settings[$field] ?? null;
            if ($value === true || $value === 1 || $value === '1') return true;
        }

        return false;
    }

    public static function getEffectiveTasksPerMinute()
    {
        $isAuto = self::getSettings(SettingsConfig::TASKS_PER_MINUTE_AUTO) == 'true';

        if ($isAuto) {
            $monitor = syncBasalamContainer()->get(SystemResourceMonitor::class);

            return $monitor->calculateOptimalTasksPerMinute();
        } else {
            return self::getSettings(SettingsConfig::TASKS_PER_MINUTE) ?? 10;
        }
    }
}
