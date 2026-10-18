<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Settings\SalamGateSettings;

defined('ABSPATH') || exit;

/** One recurring cron event; saved settings are the source of truth. */
final class StatusSyncScheduler
{
    public const HOOK = 'salam_gate_sync_parcel_statuses';

    public static function register(): void
    {
        add_action('init', [self::class, 'reconcile']);
        add_action(self::HOOK, [self::class, 'runScheduled']);
        add_action('update_option_' . SalamGateSettings::OPTION_KEY, [self::class, 'reconcile'], 10, 0);
        add_action('add_option_' . SalamGateSettings::OPTION_KEY, [self::class, 'reconcile'], 10, 0);
    }

    public static function reconcile(): void
    {
        $settings = self::settings();
        $event = wp_get_scheduled_event(self::HOOK);
        if (!$settings['enabled']) {
            if ($event !== false) {
                wp_clear_scheduled_hook(self::HOOK);
            }
            return;
        }

        if ($event !== false && $event->schedule === $settings['interval']) {
            return;
        }
        if ($event !== false) {
            wp_clear_scheduled_hook(self::HOOK);
        }
        wp_schedule_event(time() + 60, $settings['interval'], self::HOOK);
    }

    public static function runScheduled(): void
    {
        // A previously queued callback must not run after the user disables sync.
        if (!self::settings()['enabled']) {
            return;
        }
        try {
            (new StatusSyncService())->syncBatch();
        } catch (\Throwable $exception) {
            // Includes failures before the service acquires its lease; never break wp-cron.
            update_option(StatusSyncService::LAST_SYNC_KEY, [
                'ran_at' => time(), 'checked' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 'error',
            ], false);
            if (class_exists(\SyncBasalam\Logger\Logger::class)) {
                \SyncBasalam\Logger\Logger::warning('اجرای زمان‌بندی‌شده همگام‌سازی سلام‌گیت ناموفق بود.');
            }
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    private static function settings(): array
    {
        // Avoid the settings singleton: option-update callbacks can run before it refreshes.
        $stored = get_option(SalamGateSettings::OPTION_KEY, []);
        $stored = is_array($stored) ? $stored : [];
        $interval = $stored[SalamGateSettings::KEY_STATUS_SYNC_INTERVAL]
            ?? SalamGateSettings::DEFAULTS[SalamGateSettings::KEY_STATUS_SYNC_INTERVAL];

        return [
            'enabled' => !empty($stored[SalamGateSettings::KEY_STATUS_SYNC_ENABLED]
                ?? SalamGateSettings::DEFAULTS[SalamGateSettings::KEY_STATUS_SYNC_ENABLED]),
            'interval' => in_array($interval, SalamGateSettings::SYNC_INTERVALS, true)
                ? $interval
                : SalamGateSettings::DEFAULTS[SalamGateSettings::KEY_STATUS_SYNC_INTERVAL],
        ];
    }
}
