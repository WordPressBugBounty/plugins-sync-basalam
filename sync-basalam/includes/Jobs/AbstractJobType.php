<?php

namespace SyncBasalam\Jobs;

use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;

defined('ABSPATH') || exit;

abstract class AbstractJobType implements JobType
{
    protected $jobManager;

    public function __construct($jobManager)
    {
        $this->jobManager = $jobManager;
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
        return $this->jobManager->hasProductJobInProgress($productId, $jobType);
    }

    protected function areAllSingleJobsCompleted(string $jobType): bool
    {
        // A claimed child and a delayed retry both belong to the current page.
        // Only terminal/deleted children allow another page to be expanded.
        return $this->jobManager->getCountJobs([
            'job_type' => $jobType,
            'status' => ['pending', 'processing'],
        ]) === 0;
    }
}
