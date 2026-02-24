<?php

namespace SyncBasalam\Jobs;

use SyncBasalam\JobManager;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;

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

    abstract public function execute(array $payload);

    public function canRun(): bool
    {
        return true;
    }

    protected function success(array $data = []): JobResult
    {
        return JobResult::success($data);
    }

    protected function retryable(string $message, int $code = 0, array $data = []): JobResult
    {
        return JobResult::failed(new RetryableException($message, $code), $data);
    }

    protected function nonRetryable(string $message, int $code = 0, array $data = []): JobResult
    {
        return JobResult::failed(new NonRetryableException($message, $code), $data);
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
