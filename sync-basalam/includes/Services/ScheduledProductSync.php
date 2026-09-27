<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\JobManager;

defined('ABSPATH') || exit;

class ScheduledProductSync
{
    public const CRON_HOOK = 'sync_basalam_scheduled_full_product_update';
    private const FORTY_EIGHT_HOURS = 'sync_basalam_every_48_hours';
    private const FULL_UPDATE_JOB = 'sync_basalam_update_all_products';

    public function registerHooks(): void
    {
        add_filter('cron_schedules', [$this, 'addCronSchedule']);
        add_action('init', [$this, 'ensureScheduled']);
        add_action(self::CRON_HOOK, [$this, 'enqueueFullUpdate']);
    }

    public function addCronSchedule(array $schedules): array
    {
        $schedules[self::FORTY_EIGHT_HOURS] = [
            'interval' => 48 * HOUR_IN_SECONDS,
            'display' => __('Every 48 hours', 'sync-basalam'),
        ];

        return $schedules;
    }

    public function ensureScheduled(): void
    {
        $interval = (int) SettingsManager::getSettings(SettingsConfig::AUTO_FULL_UPDATE_INTERVAL);
        $recurrence = $interval === 24 ? 'daily' : ($interval === 48 ? self::FORTY_EIGHT_HOURS : null);
        $nextRun = wp_next_scheduled(self::CRON_HOOK);

        if ($recurrence === null) {
            if ($nextRun !== false) self::unschedule();
            return;
        }

        if ($nextRun !== false && wp_get_schedule(self::CRON_HOOK) === $recurrence) return;

        if ($nextRun !== false) self::unschedule();

        wp_schedule_event(time() + ($interval * HOUR_IN_SECONDS), $recurrence, self::CRON_HOOK);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function enqueueFullUpdate(): void
    {
        if ((int) SettingsManager::getSettings(SettingsConfig::AUTO_FULL_UPDATE_INTERVAL) === 0) return;
        if (!SettingsManager::getSettings(SettingsConfig::TOKEN)) return;

        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canUpdate() || !SettingsManager::isProductUpdateSelectionValid()) return;

        $jobManager = new JobManager();
        $activeStatuses = ['pending', 'processing'];
        foreach ([self::FULL_UPDATE_JOB, 'sync_basalam_bulk_update_products'] as $jobType) {
            if ($jobManager->getCountJobs(['job_type' => $jobType, 'status' => $activeStatuses]) > 0) return;
        }

        $jobManager->createJob(self::FULL_UPDATE_JOB, 'pending', wp_json_encode(['last_updatable_product_id' => 0]));
    }
}
