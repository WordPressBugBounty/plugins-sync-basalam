<?php

namespace SyncBasalam\Jobs;

use SyncBasalam\JobManager;
use SyncBasalam\Jobs\Exceptions\JobException;

defined('ABSPATH') || exit;

class JobExecutor
{
    private $jobManager;
    private $lockManager;
    private $jobRegistry;

    public function __construct(JobManager $jobManager, LockManager $lockManager, JobRegistry $jobRegistry)
    {
        $this->jobManager = $jobManager;
        $this->lockManager = $lockManager;
        $this->jobRegistry = $jobRegistry;
    }

    public function execute(string $jobType, object $job): bool
    {
        $jobExecutor = $this->jobRegistry->get($jobType);

        if (!$jobExecutor) return false;

        $payload = json_decode($job->payload, true);

        if (!is_array($payload)) {
            $payload = $this->normalizeLegacyPayload($jobType, $payload);
        }

        try {
            $result = $jobExecutor->execute($payload);

            if ($result instanceof JobResult) return $this->handleJobResult($job, $result);
            $this->jobManager->deleteJob(['id' => $job->id]);
            return true;
        } catch (JobException $e) {
            return $this->handleJobException($job, $e);
        } catch (\Exception $e) {
            $this->jobManager->failJob($job->id, $e->getMessage());
            return false;
        }
    }

    private function handleJobResult(object $job, JobResult $result): bool
    {
        if ($result->isSuccessful()) {
            $this->jobManager->deleteJob(['id' => $job->id]);
            return true;
        }

        if ($result->shouldRetry()) {
            $retried = $this->jobManager->retryJob($job->id, $result->getErrorMessage());

            return $retried;
        }

        $this->jobManager->failJob($job->id, $result->getErrorMessage());
        return false;
    }

    private function handleJobException(object $job, JobException $exception): bool
    {
        if ($exception->shouldRetry()) {
            $retried = $this->jobManager->retryJob($job->id, $exception->getMessage());

            return $retried;
        }

        $this->jobManager->failJob($job->id, $exception->getMessage());
        return false;
    }

    private function normalizeLegacyPayload(string $jobType, $legacyPayload): array
    {
        switch ($jobType) {
            case 'sync_basalam_update_single_product':
            case 'sync_basalam_create_single_product':
                return ['product_id' => $legacyPayload];

            case 'sync_basalam_bulk_update_products':
            case 'sync_basalam_update_all_products':
                return ['last_updatable_product_id' => $legacyPayload];

            case 'sync_basalam_create_all_products':
                return ['include_out_of_stock' => false, 'posts_per_page' => 100];

            default:
                return [];
        }
    }

    public function getSortedJobTypes(): array
    {
        return $this->jobRegistry->getSortedByPriority();
    }

    public function canRun(string $jobType): bool
    {
        $jobExecutor = $this->jobRegistry->get($jobType);

        if (!$jobExecutor) return false;

        return $jobExecutor->canRun();
    }

    public function acquireLock(string $jobType, int $timeout = 0): bool
    {
        return $this->lockManager->acquire($jobType, $timeout);
    }

    public function releaseLock(string $jobType): bool
    {
        return $this->lockManager->release($jobType);
    }
}
