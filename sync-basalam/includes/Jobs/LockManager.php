<?php

namespace SyncBasalam\Jobs;

defined('ABSPATH') || exit;

class LockManager
{
    public function acquire(string $jobType, int $timeout = 0): bool
    {
        return $this->acquireNamedLock('sync_basalam_job_' . $jobType, $timeout);
    }

    public function release(string $jobType): bool
    {
        return $this->releaseNamedLock('sync_basalam_job_' . $jobType);
    }

    public function acquireGlobalJobsLock(int $timeout = 0): bool
    {
        return $this->acquireNamedLock('sync_basalam_jobs_runner', $timeout);
    }

    public function releaseGlobalJobsLock(): bool
    {
        return $this->releaseNamedLock('sync_basalam_jobs_runner');
    }

    private function acquireNamedLock(string $lockName, int $timeout = 0): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named MySQL advisory lock; no object cache applicable.
        $result = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lockName, $timeout));

        return $result === '1';
    }

    private function releaseNamedLock(string $lockName): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named MySQL advisory lock; no object cache applicable.
        $result = $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));

        return $result === '1';
    }
}
