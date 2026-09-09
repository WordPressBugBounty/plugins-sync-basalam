<?php

namespace SyncBasalam;

use SyncBasalam\Admin\Settings;
use SyncBasalam\Services\Api\CircuitBreaker;

defined('ABSPATH') || exit;

class JobsRunner
{
    private const ASYNC_ACTION = 'sync_basalam_run_jobs_async';
    private const ASYNC_DISPATCH_LOCK_TRANSIENT = 'sync_basalam_jobs_runner_async_dispatch_lock';
    // Keep the dispatch lease longer than a normal async batch. Without this,
    // every frontend request can boot another full WordPress AJAX worker while
    // a large product queue is active.
    private const ASYNC_DISPATCH_LOCK_SECONDS = 25;
    private const ASYNC_TIME_LIMIT_SECONDS = 20;
    private const GLOBAL_RUNNER_LAST_RUN_OPTION = 'sync_basalam_jobs_runner_last_run';
    private const STALE_PROCESSING_TIMEOUT_SECONDS = 120;

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
        add_action('wp_ajax_' . self::ASYNC_ACTION, [$this, 'handleAsyncRequest']);
        add_action('wp_ajax_nopriv_' . self::ASYNC_ACTION, [$this, 'handleAsyncRequest']);
        // Probe and dispatch after the response path so normal storefront
        // requests never pay for the queue query or loopback HTTP request.
        add_action('shutdown', [$this, 'maybeDispatchAsyncRequest'], PHP_INT_MAX);
        add_action('sync_basalam_job_created', [$this, 'maybeDispatchAsyncRequest'], 10, 0);

        $this->jobManager = $jobManager;
        $this->jobExecutor = $jobExecutor;
        $this->discountScheduler = $discountScheduler;
        $this->CheckHttpBlockService = $CheckHttpBlockService;
    }

    public function maybeDispatchAsyncRequest(): void
    {
        if ($this->isCurrentAsyncRequest()) return;
        if ($this->CheckHttpBlockService->SyncBasalamHttpBlock()) return;
        if (get_transient(self::ASYNC_DISPATCH_LOCK_TRANSIENT)) return;

        // Reserve the dispatch lease before probing the queue. This keeps
        // concurrent shutdown callbacks from all running the queue query and
        // dispatching duplicate async workers.
        set_transient(
            self::ASYNC_DISPATCH_LOCK_TRANSIENT,
            1,
            self::ASYNC_DISPATCH_LOCK_SECONDS
        );

        if (!$this->jobManager->hasPendingOrStaleProcessingJobs(self::STALE_PROCESSING_TIMEOUT_SECONDS)) {
            return;
        }

        $this->dispatchAsyncRequest();
    }

    public function handleAsyncRequest(): void
    {
        if (!check_ajax_referer(self::ASYNC_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid async jobs runner nonce.'], 403);
        }

        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        if (function_exists('session_write_close')) {
            session_write_close();
        }

        $processed = $this->runAsyncBatch();

        wp_send_json_success(['processed' => $processed]);
    }

    public function checkAndRunJobs(): bool
    {
        if ($this->CheckHttpBlockService->SyncBasalamHttpBlock()) return false;
        if (!$this->jobExecutor->acquireGlobalJobsLock(0)) return false;

        try {
            return $this->runEligibleJobs();
        } finally {
            $this->jobExecutor->releaseGlobalJobsLock();
        }
    }

    private function runAsyncBatch(): int
    {
        if ($this->CheckHttpBlockService->SyncBasalamHttpBlock()) return 0;

        // Hold the advisory lock for the whole batch, including rate-limit
        // waits. Previously it was released after every job, so duplicate
        // async requests could pile up and sleep in parallel until the next
        // job became eligible, exhausting the site's PHP workers.
        if (!$this->jobExecutor->acquireGlobalJobsLock(0)) return 0;

        $processed = 0;
        $deadline = microtime(true) + (float) apply_filters(
            'sync_basalam_jobs_runner_async_time_limit',
            self::ASYNC_TIME_LIMIT_SECONDS
        );

        try {
            while (microtime(true) < $deadline) {
                if (!$this->jobManager->hasPendingOrStaleProcessingJobs(self::STALE_PROCESSING_TIMEOUT_SECONDS)) {
                    break;
                }

                $ranJob = $this->runEligibleJobs();

                if ($ranJob) {
                    $processed++;
                }

                $delay = $this->secondsUntilNextAllowedRun();
                if ($delay <= 0.0) {
                    if (!$ranJob) break;
                    continue;
                }

                if ((microtime(true) + $delay) >= $deadline) {
                    break;
                }

                usleep((int) ($delay * 1000000));
            }
        } finally {
            $this->jobExecutor->releaseGlobalJobsLock();
        }

        return $processed;
    }

    private function runEligibleJobs(): bool
    {
        $this->jobManager->ConvertStaleProcessingJobs(self::STALE_PROCESSING_TIMEOUT_SECONDS);
        $this->discountScheduler->process();

        $circuitBreaker = new CircuitBreaker();
        if ($circuitBreaker->getState() === CircuitBreaker::STATE_OPEN) {
            return false;
        }

        if ($this->jobManager->hasAnyProcessingJob()) {
            return false;
        }

        $lastRun = floatval(get_option(self::GLOBAL_RUNNER_LAST_RUN_OPTION, 0));
        $now = microtime(true);

        if (($now - $lastRun) < $this->getRunThresholdSeconds()) {
            return false;
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
            return true;
        }

        return false;
    }

    private function executeJob(object $job): void
    {
        $jobType = $job->job_type;
        $this->jobExecutor->execute($jobType, $job);
    }

    private function dispatchAsyncRequest(): void
    {
        $url = add_query_arg('action', self::ASYNC_ACTION, admin_url('admin-ajax.php'));

        wp_remote_post(esc_url_raw($url), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'body'      => [
                'action' => self::ASYNC_ACTION,
                'nonce'  => wp_create_nonce(self::ASYNC_ACTION),
            ],
            'cookies'   => $_COOKIE,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => [
                'X-WP-Async-Request' => self::ASYNC_ACTION,
            ],
        ]);
    }

    private function isCurrentAsyncRequest(): bool
    {
        if (!wp_doing_ajax()) return false;

        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

        return $action === self::ASYNC_ACTION;
    }

    private function secondsUntilNextAllowedRun(): float
    {
        $lastRun = floatval(get_option(self::GLOBAL_RUNNER_LAST_RUN_OPTION, 0));
        $elapsed = microtime(true) - $lastRun;

        return max(0.0, $this->getRunThresholdSeconds() - $elapsed);
    }

    private function getRunThresholdSeconds(): float
    {
        $tasksPerMinute = max(1, intval(Settings::getEffectiveTasksPerMinute()));

        return 60.0 / $tasksPerMinute;
    }
}
