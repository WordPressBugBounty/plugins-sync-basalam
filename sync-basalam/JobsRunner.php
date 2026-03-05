<?php

namespace SyncBasalam;

use SyncBasalam\Admin\Settings;
use SyncBasalam\Services\Api\CircuitBreaker;

defined('ABSPATH') || exit;

class JobsRunner
{
    private $jobExecutor;
    private $jobManager;
    private $discountScheduler;

    public function __construct(
        $jobManager,
        $jobExecutor,
        $discountScheduler
    )
    {
        add_action('init', [$this, 'checkAndRunJobs']);
        $this->jobManager = $jobManager;
        $this->jobExecutor = $jobExecutor;
        $this->discountScheduler = $discountScheduler;
    }

    public function checkAndRunJobs(): void
    {
        $this->jobManager->deleteStaleProcessingJobs(120);
        $this->discountScheduler->process();

        $circuitBreaker = new CircuitBreaker();
        if ($circuitBreaker->getState() === CircuitBreaker::STATE_OPEN) {
            return;
        }

        $tasksPerMinute = max(1, intval(Settings::getEffectiveTasksPerMinute()));
        $thresholdSeconds = 60.0 / $tasksPerMinute;

        $sortedJobTypes = $this->jobExecutor->getSortedJobTypes();

        foreach ($sortedJobTypes as $jobType => $jobExecutor) {
            if (!$this->jobExecutor->acquireLock($jobType, 0)) continue;
            try {
                $lastRun = floatval(get_option($jobType . '_last_run', 0));
                $now = microtime(true);

                if (($now - $lastRun) >= $thresholdSeconds) {
                    if (!$this->jobExecutor->canRun($jobType)) {
                        continue;
                    }

                    $job = $this->jobManager->getNextEligibleJob($jobType);
                    $processingJob = $this->jobManager->getJob(['job_type' => $jobType, 'status' => 'processing']);

                    if ($job && !$processingJob) {
                        update_option($jobType . '_last_run', microtime(true), false);

                        $this->jobManager->updateJob(
                            ['status' => 'processing', 'started_at' => time()],
                            ['id' => $job->id]
                        );

                        $this->jobExecutor->releaseLock($jobType);

                        $this->executeJob($job);

                        break;
                    }
                }
            } finally {
                $this->jobExecutor->releaseLock($jobType);
            }
        }
    }

    private function executeJob(object $job): void
    {
        $jobType = $job->job_type;
        $this->jobExecutor->execute($jobType, $job);
    }
}
