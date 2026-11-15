<?php

namespace SyncBasalam\Services\Products\UnmappedProducts;

use SyncBasalam\Jobs\LockManager;

defined('ABSPATH') || exit;

/**
 * Enqueue daily scans through the WooSalam runner, including when its queue is empty.
 * Only the next due time is stored here; product work belongs to JobManager.
 */
class ScheduledUnmappedScan
{
    public const NEXT_RUN_OPTION = 'sync_basalam_unmapped_scan_next_run';
    public const LEGACY_CRON_HOOK = 'sync_basalam_unmapped_scan_check';
    private const LOCK = 'sync_basalam_unmapped_scan_schedule';

    private $manager;
    private $lockManager;

    public function __construct($manager = null, $lockManager = null)
    {
        $this->manager = $manager;
        $this->lockManager = $lockManager;
    }

    public function registerHooks(): void
    {
        add_action('init', [$this, 'ensureScheduled']);
    }

    public function ensureScheduled(): void
    {
        $legacyNextRun = wp_next_scheduled(self::LEGACY_CRON_HOOK);
        if (get_option(self::NEXT_RUN_OPTION, false) === false) {
            // Preserve an existing due time when upgrading; fresh installs wait one day.
            add_option(self::NEXT_RUN_OPTION, $legacyNextRun !== false ? $legacyNextRun : time() + DAY_IN_SECONDS, '', false);
        }
        if ($legacyNextRun !== false) wp_clear_scheduled_hook(self::LEGACY_CRON_HOOK);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::LEGACY_CRON_HOOK);
        delete_option(self::NEXT_RUN_OPTION);
    }

    public function process(): bool
    {
        $nextRun = (int) get_option(self::NEXT_RUN_OPTION, 0);
        if ($nextRun <= 0 || $nextRun > time()) return false;

        $lockManager = $this->lockManager ?: syncBasalamContainer()->get(LockManager::class);
        if (!$lockManager->acquire(self::LOCK, 0)) return false;

        try {
            // Another request may have advanced the schedule while we acquired the lock.
            wp_cache_delete(self::NEXT_RUN_OPTION, 'options');
            $nextRun = (int) get_option(self::NEXT_RUN_OPTION, 0);
            $now = time();
            if ($nextRun <= 0 || $nextRun > $now) return false;

            // Persist before createJob dispatches its synchronous job-created hook.
            if (!update_option(self::NEXT_RUN_OPTION, $now + DAY_IN_SECONDS, false)) return false;

            try {
                $result = ($this->manager ?: new UnmappedProductsManager())->startScan();
            } catch (\Throwable $e) {
                update_option(self::NEXT_RUN_OPTION, $now + MINUTE_IN_SECONDS, false);
                throw $e;
            }

            if ($result['status_code'] >= 500) {
                update_option(self::NEXT_RUN_OPTION, $now + MINUTE_IN_SECONDS, false);
            }
            return $result['success'];
        } finally {
            $lockManager->release(self::LOCK);
        }
    }
}
