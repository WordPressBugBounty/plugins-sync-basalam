<?php

namespace SyncBasalam\Jobs;

use SyncBasalam\JobManager;

defined('ABSPATH') || exit;

abstract class AbstractJobType implements JobType
{
    protected $jobManager;

    public function __construct()
    {
        $this->jobManager = JobManager::getInstance();
    }

    abstract public function getType(): string;

    abstract public function getPriority(): int;

    abstract public function execute(array $payload): void;

    public function canRun(): bool
    {
        return true;
    }

    protected function hasProductJobInProgress(int $productId, string $jobType): bool
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'sync_basalam_job_manager';

        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT payload FROM {$tableName}
            WHERE job_type = %s
            AND (status = %s OR status = %s)",
            $jobType,
            'pending',
            'processing'
        ));

        if (empty($jobs)) return false;

        foreach ($jobs as $job) {
            $payload = json_decode($job->payload, true);
            $jobProductId = $payload['product_id'] ?? $payload;

            if (intval($jobProductId) === intval($productId)) return true;
        }

        return false;
    }

    protected function areAllSingleJobsCompleted(string $jobType): bool
    {
        $pendingJob = $this->jobManager->getJob(['job_type' => $jobType, 'status' => 'pending']);

        return $pendingJob === null;
    }
}
