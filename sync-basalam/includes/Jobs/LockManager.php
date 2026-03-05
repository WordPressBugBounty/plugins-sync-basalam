<?php

namespace SyncBasalam\Jobs;

defined('ABSPATH') || exit;

class LockManager
{
    public function acquire(string $jobType, int $timeout = 0): bool
    {
        global $wpdb;

        $lockName = 'sync_basalam_job_' . $jobType;
        $result = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lockName, $timeout));

        return $result === '1';
    }

    public function release(string $jobType): bool
    {
        global $wpdb;

        $lockName = 'sync_basalam_job_' . $jobType;
        $result = $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));

        return $result === '1';
    }
}
