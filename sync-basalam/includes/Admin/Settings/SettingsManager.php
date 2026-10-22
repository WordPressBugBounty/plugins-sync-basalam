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
        $input[SettingsConfig::DEFAULT_STOCK_QUANTITY] = min(
            SettingsConfig::MAX_STOCK_QUANTITY,
            absint($input[SettingsConfig::DEFAULT_STOCK_QUANTITY])
        );
        $input[SettingsConfig::SAFE_STOCK] = min(
            SettingsConfig::MAX_STOCK_QUANTITY,
            absint($input[SettingsConfig::SAFE_STOCK])
        );
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

    /** Called inside the operation's queue lock, immediately before insertion. */
    public static function allowsProductJob(string $operation, array $payload): bool
    {
        if (empty($payload['automatic']) && empty($payload['scheduled'])) return true;

        // get_option() can still contain the enabled value in an older request.
        $snapshot = self::readProductSyncSettings();
        if ($snapshot === null) return false;
        $settings = $snapshot['settings'];
        $key = $operation === 'create' ? SettingsConfig::SYNC_STATUS_PRODUCT_CREATE : SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE;

        return (empty($payload['automatic']) || $settings[$key])
            && (empty($payload['scheduled']) || ($operation === 'update' && (int) ($settings[SettingsConfig::AUTO_FULL_UPDATE_INTERVAL] ?? 0) > 0));
    }

    /** Stop only this operation's sources; explicit manual runs remain available. */
    public static function stopProductSync(string $operation): bool
    {
        if (!in_array($operation, ['create', 'update'], true)) throw new \InvalidArgumentException('Unknown product operation.');
        global $wpdb;

        // Compare-and-swap preserves the other operation and unrelated settings
        // when create/update stops or another settings save overlap.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $snapshot = self::readProductSyncSettings();
            if ($snapshot === null) return false;
            $settings = $snapshot['settings'];
            $settings[$operation === 'create' ? SettingsConfig::SYNC_STATUS_PRODUCT_CREATE : SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE] = false;
            $settings = self::normalizeProductSyncSettings($settings);
            if ($operation === 'update') $settings[SettingsConfig::AUTO_FULL_UPDATE_INTERVAL] = 0;
            $serialized = maybe_serialize($settings);

            if ($snapshot['raw'] === null) {
                $saved = add_option('sync_basalam_settings', $settings);
            } elseif ($serialized === $snapshot['raw']) {
                $saved = true;
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic conditional option update; cache invalidated below.
                $saved = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
                    $serialized, 'sync_basalam_settings', $snapshot['raw']
                ));
                if ($saved === false) return false;
            }

            if ($saved) {
                wp_cache_delete('sync_basalam_settings', 'options');
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete('notoptions', 'options');
                syncBasalamSettings()->reset();
                return true;
            }
        }
        return false;
    }

    private static function readProductSyncSettings(): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue admission must see a concurrent stop, not a request-local option cache.
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'sync_basalam_settings'));
        if ($wpdb->last_error) return null;
        $settings = $raw === null ? SettingsConfig::getDefaultSettings() : maybe_unserialize($raw);
        if (!is_array($settings)) return null;
        return ['raw' => $raw, 'settings' => self::normalizeProductSyncSettings($settings)];
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

        // Keep legacy installations with an oversized fallback inventory within
        // Basalam's 0..10000 API range. getSettings() persists this repair, so a
        // product that does not manage WooCommerce stock cannot send 1,000,000.
        foreach ([SettingsConfig::DEFAULT_STOCK_QUANTITY, SettingsConfig::SAFE_STOCK] as $key) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = min(SettingsConfig::MAX_STOCK_QUANTITY, max(0, absint($settings[$key])));
            }
        }

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
