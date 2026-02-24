<?php

namespace SyncBasalam\Admin\Settings;

use SyncBasalam\Services\SystemResourceMonitor;

defined('ABSPATH') || exit;

class SettingsManager
{
    public static function getSettings($setting = null)
    {
        $settings = (array) get_option('sync_basalam_settings', SettingsConfig::getDefaultSettings());

        if ($setting == null || !array_key_exists($setting, $settings)) {
            $defaultSettings = SettingsConfig::getDefaultSettings();

            foreach ($defaultSettings as $key => $value) {
                if (!array_key_exists($key, $settings)) {
                    $settings[$key] = $value;
                }
            }

            update_option('sync_basalam_settings', $settings);
        }

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
        $input = array_merge(self::getSettings() ?: [], $input);

        $input[SettingsConfig::DEFAULT_WEIGHT] = absint($input[SettingsConfig::DEFAULT_WEIGHT]);
        $input[SettingsConfig::DEFAULT_PREPARATION] = absint($input[SettingsConfig::DEFAULT_PREPARATION]);
        $input[SettingsConfig::DISCOUNT_REDUCTION_PERCENT] = min(100, absint($input[SettingsConfig::DISCOUNT_REDUCTION_PERCENT]));

        return $input;
    }

    public static function getEffectiveTasksPerMinute()
    {
        $isAuto = self::getSettings(SettingsConfig::TASKS_PER_MINUTE_AUTO) == 'true';

        if ($isAuto) {
            $monitor = SystemResourceMonitor::getInstance();

            return $monitor->calculateOptimalTasksPerMinute();
        } else {
            return self::getSettings(SettingsConfig::TASKS_PER_MINUTE) ?? 10;
        }
    }
}
