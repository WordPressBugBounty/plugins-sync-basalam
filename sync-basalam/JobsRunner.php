<?php

namespace SyncBasalam;

use SyncBasalam\Admin\Settings;
use SyncBasalam\Jobs\JobExecutor;
use SyncBasalam\Jobs\LockManager;
use SyncBasalam\Jobs\JobRegistry;
use SyncBasalam\Jobs\DiscountTaskScheduler;

defined('ABSPATH') || exit;

class JobsRunner
{
    private static $instance = null;

    private $jobExecutor;
    private $jobManager;
    private $discountScheduler;

    public static function getInstance(): self
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'checkAndRunJobs']);

        $this->jobManager = JobManager::getInstance();
        $lockManager = LockManager::getInstance();
        $jobRegistry = JobRegistry::getInstance();

        $this->jobExecutor = new JobExecutor($this->jobManager, $lockManager, $jobRegistry);
        $this->discountScheduler = DiscountTaskScheduler::getInstance();
    }

    public function checkAndRunJobs(): void
    {
        $this->jobManager->deleteStaleProcessingJobs(120);
        $this->discountScheduler->process();

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

                    $job = $this->jobManager->getJob(['job_type' => $jobType, 'status' => 'pending']);
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
