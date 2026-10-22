<?php

namespace SyncBasalam\Jobs;

defined('ABSPATH') || exit;

class LockManager
{
    /** MySQL advisory names are shared by every database on the server. */
    public static function name(string $purpose, ?string $queueTable = null): string
    {
        global $wpdb;

        $database = (string) ($wpdb->dbname ?? (defined('DB_NAME') ? DB_NAME : ''));
        $queueTable = $queueTable ?? $wpdb->prefix . 'sync_basalam_job_manager';

        // Keep the complete identity within MySQL's 64-byte lock-name limit.
        return 'sync_basalam_' . md5($database . "\0" . $queueTable . "\0" . $purpose);
    }

    public function acquire(string $jobType, int $timeout = 0): bool
    {
        return $this->acquireNamedLock(self::name('job:' . $jobType), $timeout);
    }

    public function release(string $jobType): bool
    {
        return $this->releaseNamedLock(self::name('job:' . $jobType));
    }

    public function acquireGlobalJobsLock(int $timeout = 0): bool
    {
        return $this->acquireNamedLock(self::name('runner'), $timeout);
    }

    public function releaseGlobalJobsLock(): bool
    {
        return $this->releaseNamedLock(self::name('runner'));
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
