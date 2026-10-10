<?php

namespace SyncBasalam;

use SyncBasalam\Admin\Settings;
use SyncBasalam\Registrar\AdminRegistrar;
use SyncBasalam\Services\Api\CircuitBreaker;
use SyncBasalam\Services\AsyncWorkerHealth;

defined('ABSPATH') || exit;

class JobsRunner
{
    private const ASYNC_ACTION = 'sync_basalam_run_jobs_async';
    private const ASYNC_DISPATCH_LOCK_TRANSIENT = 'sync_basalam_jobs_runner_async_dispatch_lock';
    private const ASYNC_IDLE_PROBE_TRANSIENT = 'sync_basalam_jobs_runner_idle_probe_lock';
    // Keep the dispatch lease longer than a normal async batch. Without this,
    // every frontend request can boot another full WordPress AJAX worker while
    // a large product queue is active.
    private const ASYNC_DISPATCH_LOCK_SECONDS = 25;
    private const ASYNC_IDLE_PROBE_SECONDS = 5;
    private const ASYNC_TIME_LIMIT_SECONDS = 20;
    private const GLOBAL_RUNNER_LAST_RUN_OPTION = 'sync_basalam_jobs_runner_last_run';
    private const STALE_PROCESSING_TIMEOUT_SECONDS = 120;

    private $jobExecutor;
    private $jobManager;
    private $discountScheduler;
    private $CheckHttpBlockService;
    private $workerHealth;
    private bool $isAsyncBatch = false;

    public function __construct(
        $jobManager,
        $jobExecutor,
        $discountScheduler,
        $CheckHttpBlockService,
        ?AsyncWorkerHealth $workerHealth = null
    ) {
        add_action('wp_ajax_' . self::ASYNC_ACTION, [$this, 'handleAsyncRequest']);
        add_action('wp_ajax_nopriv_' . self::ASYNC_ACTION, [$this, 'handleAsyncRequest']);
        // Run before later shutdown callbacks can leave an unread mysqli result.
        add_action('shutdown', [$this, 'maybeDispatchAsyncRequest'], 2);
        add_action('sync_basalam_job_created', [$this, 'maybeDispatchAsyncRequest'], 10, 0);
        add_action('admin_notices', [$this, 'renderWorkerHealthNotice']);

        $this->jobManager = $jobManager;
        $this->jobExecutor = $jobExecutor;
        $this->discountScheduler = $discountScheduler;
        $this->CheckHttpBlockService = $CheckHttpBlockService;
        $this->workerHealth = $workerHealth ?? new AsyncWorkerHealth();
    }

    public function renderWorkerHealthNotice(): void
    {
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) return;
        if (!AdminRegistrar::isWoosalamRelatedAdminScreen()) return;

        // A live worker may legitimately take over two minutes. Its database
        // lock, rather than elapsed time alone, determines whether it was lost.
        if ($this->jobExecutor->acquireGlobalJobsLock(0)) {
            try {
                $this->detectInterruptedWorker();
            } finally {
                $this->jobExecutor->releaseGlobalJobsLock();
            }
        }

        $notice = $this->workerHealth->getNotice();
        if (!$notice) return;
        require __DIR__ . '/templates/notifications/AsyncWorkerAlert.php';
    }

    private function detectInterruptedWorker(): void
    {
        $job = $this->jobManager->getStaleProcessingJob(self::STALE_PROCESSING_TIMEOUT_SECONDS);
        if ($job) $this->workerHealth->recordInterruptedJob($job);
    }

    public function maybeDispatchAsyncRequest(): void
    {
        if ($this->isCurrentAsyncRequest()) return;

        if ($this->isShutdownCallback()) {
            $this->repairDatabaseConnection();
        }

        if ($this->CheckHttpBlockService->SyncBasalamHttpBlock()) return;
        $isNewJob = function_exists('current_filter') && current_filter() === 'sync_basalam_job_created';
        if (!$isNewJob && $this->hasActiveAsyncLease(self::ASYNC_IDLE_PROBE_TRANSIENT)) return;
        if ($this->hasActiveAsyncLease(self::ASYNC_DISPATCH_LOCK_TRANSIENT)) return;

        if (!$this->jobManager->hasPendingOrStaleProcessingJobs(self::STALE_PROCESSING_TIMEOUT_SECONDS)) {
            if (!$isNewJob) {
                set_transient(
                    self::ASYNC_IDLE_PROBE_TRANSIENT,
                    time() + self::ASYNC_IDLE_PROBE_SECONDS,
                    self::ASYNC_IDLE_PROBE_SECONDS
                );
            }
            return;
        }

        // An empty-queue probe must not block a job created moments later.
        // The global database lock still prevents duplicate workers from
        // processing the same queue when requests race here.
        if ($this->hasActiveAsyncLease(self::ASYNC_DISPATCH_LOCK_TRANSIENT)) return;
        set_transient(
            self::ASYNC_DISPATCH_LOCK_TRANSIENT,
            time() + self::ASYNC_DISPATCH_LOCK_SECONDS,
            self::ASYNC_DISPATCH_LOCK_SECONDS
        );

        $this->dispatchAsyncRequest();
    }

    private function hasActiveAsyncLease(string $transient): bool
    {
        $lease = get_transient($transient);
        if (!$lease) return false;

        // Keep the deadline in the value too: concurrent transient expiry can
        // remove the separate timeout option while a new value is being written.
        if (is_numeric($lease) && (int) $lease > 1) {
            return (int) $lease > time();
        }

        // Older versions stored 1. An orphaned database-backed lease must not
        // stop the queue forever. External object caches enforce their own TTL.
        if (!wp_using_ext_object_cache() && !wp_installing()) {
            $expiresAt = (int) get_option('_transient_timeout_' . $transient, 0);
            return $expiresAt > time();
        }

        return true;
    }

    private function isShutdownCallback(): bool
    {
        return function_exists('current_filter') && current_filter() === 'shutdown';
    }

    private function repairDatabaseConnection(): void
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) return;

        if (method_exists($wpdb, 'flush')) {
            $wpdb->flush();
        }

        if (method_exists($wpdb, 'check_connection')) {
            $wpdb->check_connection(false);
        }
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

        $this->isAsyncBatch = true;
        $processed = 0;
        $deadline = microtime(true) + (float) apply_filters(
            'sync_basalam_jobs_runner_async_time_limit',
            self::ASYNC_TIME_LIMIT_SECONDS
        );

        try {
            $this->workerHealth->observeAsyncEnvironment();
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
            $this->isAsyncBatch = false;
            $this->jobExecutor->releaseGlobalJobsLock();
        }

        return $processed;
    }

    private function runEligibleJobs(): bool
    {
        $this->detectInterruptedWorker();
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
        $started = microtime(true);
        $this->jobExecutor->execute($jobType, $job);
        if ($this->isAsyncBatch) {
            $this->workerHealth->recordJobReturned($job, microtime(true) - $started);
        }
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
