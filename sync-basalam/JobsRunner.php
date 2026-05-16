<?php

namespace SyncBasalam;

use SyncBasalam\Admin\Settings;
use SyncBasalam\Services\Api\CircuitBreaker;

defined('ABSPATH') || exit;

class JobsRunner
{
    private const GLOBAL_RUNNER_LAST_RUN_OPTION = 'sync_basalam_jobs_runner_last_run';

    private $jobExecutor;
    private $jobManager;
    private $discountScheduler;
    private $CheckHttpBlockService;

    public function __construct(
        $jobManager,
        $jobExecutor,
        $discountScheduler,
        $CheckHttpBlockService
    ) {
        add_action('init', [$this, 'checkAndRunJobs']);
        $this->jobManager = $jobManager;
        $this->jobExecutor = $jobExecutor;
        $this->discountScheduler = $discountScheduler;
        $this->CheckHttpBlockService = $CheckHttpBlockService;
    }

    public function checkAndRunJobs(): void
    {
        if ($this->CheckHttpBlockService->SyncBasalamHttpBlock()) return;
        if (!$this->jobExecutor->acquireGlobalJobsLock(0)) return;

        try {
            $this->runEligibleJobs();
        } finally {
            $this->jobExecutor->releaseGlobalJobsLock();
        }
    }

    private function runEligibleJobs(): void
    {
        $this->jobManager->ConvertStaleProcessingJobs(120);
        $this->discountScheduler->process();

        $circuitBreaker = new CircuitBreaker();
        if ($circuitBreaker->getState() === CircuitBreaker::STATE_OPEN) {
            return;
        }

        if ($this->jobManager->hasAnyProcessingJob()) {
            return;
        }

        $tasksPerMinute = max(1, intval(Settings::getEffectiveTasksPerMinute()));
        $thresholdSeconds = 60.0 / $tasksPerMinute;
        $lastRun = floatval(get_option(self::GLOBAL_RUNNER_LAST_RUN_OPTION, 0));
        $now = microtime(true);

        if (($now - $lastRun) < $thresholdSeconds) {
            return;
        }

        $sortedJobTypes = $this->jobExecutor->getSortedJobTypes();

        foreach ($sortedJobTypes as $jobType => $jobExecutor) {
            if (!$this->jobExecutor->canRun($jobType)) {
                continue;
            }

            $job = $this->jobManager->getNextEligibleJob($jobType);
            $processingJob = $this->jobManager->getJob(['job_type' => $jobType, 'status' => 'processing']);

            if (!$job || $processingJob) {
                continue;
            }

            update_option(self::GLOBAL_RUNNER_LAST_RUN_OPTION, microtime(true), false);

            $this->jobManager->updateJob(
                ['status' => 'processing', 'started_at' => time()],
                ['id' => $job->id]
            );

            $this->executeJob($job);
            break;
        }
    }

    private function executeJob(object $job): void
    {
        $jobType = $job->job_type;
        $this->jobExecutor->execute($jobType, $job);
    }
}
