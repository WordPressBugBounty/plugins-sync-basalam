<?php

namespace SyncBasalam\Jobs;

use SyncBasalam\Jobs\Types\ArchiveUnmappedProductsJob;
use SyncBasalam\Jobs\Types\FindUnmappedProductsJob;

defined('ABSPATH') || exit;

/**
 * Orders first, then four ordinary starts and one maintenance start (2 of 10).
 * Selection and commit must both run under the runner's global database lock.
 */
class FairJobScheduler
{
    public const STATE_OPTION = 'sync_basalam_jobs_runner_fair_schedule';
    private const ORDER_JOB = 'sync_basalam_fetch_orders';
    private const ORDINARY_SLOTS = 4;
    private const MAINTENANCE_JOBS = [FindUnmappedProductsJob::TYPE, ArchiveUnmappedProductsJob::TYPE];

    /**
     * The callback returns a ready job or null, honoring canRun and retry_after.
     * Empty groups lend their slot immediately; unused capacity never accrues debt.
     *
     * @return array{job: object, next_state: ?array}|null
     */
    public function select(array $sortedJobTypes, callable $eligibleJob): ?array
    {
        if (isset($sortedJobTypes[self::ORDER_JOB])) {
            $order = $eligibleJob(self::ORDER_JOB);
            if ($order) return ['job' => $order, 'next_state' => null];
        }

        // An older worker may have cached this option before waiting for the lock.
        wp_cache_delete(self::STATE_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        $stored = get_option(self::STATE_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $slot = $stored['slot'] ?? 0;
        $slot = is_int($slot) && $slot >= 0 && $slot <= self::ORDINARY_SLOTS ? $slot : 0;
        $lastMaintenance = $stored['last_maintenance'] ?? '';
        if (!in_array($lastMaintenance, self::MAINTENANCE_JOBS, true)) $lastMaintenance = '';

        $ordinary = array_values(array_filter(array_keys($sortedJobTypes), static function ($type) {
            return $type !== self::ORDER_JOB && !in_array($type, self::MAINTENANCE_JOBS, true);
        }));
        $maintenance = $lastMaintenance === FindUnmappedProductsJob::TYPE
            ? array_reverse(self::MAINTENANCE_JOBS)
            : self::MAINTENANCE_JOBS;
        $groups = $slot === self::ORDINARY_SLOTS ? [$maintenance, $ordinary] : [$ordinary, $maintenance];

        foreach ($groups as $types) {
            foreach ($types as $type) {
                if (!isset($sortedJobTypes[$type])) continue;
                $job = $eligibleJob($type);
                if (!$job) continue;

                return [
                    'job' => $job,
                    'next_state' => [
                        'slot' => ($slot + 1) % (self::ORDINARY_SLOTS + 1),
                        'last_maintenance' => in_array($type, self::MAINTENANCE_JOBS, true) ? $type : $lastMaintenance,
                    ],
                ];
            }
        }

        return null;
    }

    /** Count claimed attempts, including retries, rather than successful API responses. */
    public function commit(array $selection): bool
    {
        return $selection['next_state'] === null
            || update_option(self::STATE_OPTION, $selection['next_state'], false);
    }
}
