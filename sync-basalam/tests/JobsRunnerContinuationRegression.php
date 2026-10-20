<?php
namespace SyncBasalam\Admin {
    class Settings { public static function getEffectiveTasksPerMinute() { return $GLOBALS['fixture_tasks_per_minute'] ?? 600; } }
}
namespace {
    $root = dirname(__DIR__);
    define('ABSPATH', $root . '/');
    require $root . '/tests/Support/JobsRunnerWordPressStubs.php';
    require $root . '/includes/Services/Api/CircuitBreaker.php';
    require $root . '/includes/Services/AsyncWorkerHealth.php';
    require $root . '/includes/Services/AsyncWorkerEnvironment.php';
    require $root . '/JobsRunner.php';
    $GLOBALS['sync_basalam_test_options'] = [];
    $GLOBALS['sync_basalam_jobs_runner_test_state'] = [
        'doing_ajax' => true, 'transients' => [], 'events' => [],
        'remote_requests' => [], 'filter_values' => ['sync_basalam_jobs_runner_async_time_limit' => 0.16],
    ];
    $_REQUEST['action'] = 'sync_basalam_run_jobs_async';
    $_COOKIE = [];
    $queue = new class {
        public array $jobs = [];
        public function __construct() { for ($id = 1; $id <= 5; $id++) $this->jobs[$id] = (object) ['id' => $id, 'job_type' => 'local_no_network_fixture']; }
        public function hasPendingOrStaleProcessingJobs($timeout) { return count($this->jobs) > 0; }
        public function getStaleProcessingJob($timeout) { return null; }
        public function ConvertStaleProcessingJobs($timeout) {}
        public function hasAnyProcessingJob() { return false; }
        public function getNextEligibleJob($type) { return reset($this->jobs) ?: null; }
        public function getJob($where) { return null; }
        public function updateJob($values, $where) {}
    };
    $executor = new class($queue) {
        private $queue;
        public bool $locked = false;
        public bool $runnable = true;
        public function __construct($queue) { $this->queue = $queue; }
        public function acquireGlobalJobsLock($timeout) { if ($this->locked) return false; return $this->locked = true; }
        public function releaseGlobalJobsLock() { $GLOBALS['sync_basalam_jobs_runner_test_state']['events'][] = 'release_global_lock'; $this->locked = false; return true; }
        public function getSortedJobTypes() { return ['local_no_network_fixture' => $this]; }
        public function canRun($type) { return $this->runnable; }
        public function execute($type, $job) { unset($this->queue->jobs[$job->id]); }
    };
    $runner = new \SyncBasalam\JobsRunner($queue, $executor, new class { public function process() {} }, new class { public function SyncBasalamHttpBlock() { return false; } });
    $batch = new \ReflectionMethod($runner, 'runAsyncBatch');
    $batch->setAccessible(true);
    $check = static function ($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
    };
    $processed = $batch->invoke($runner);
    $check($processed > 0 && count($queue->jobs) > 0, 'Fixture must cross a batch boundary.');
    $events = $GLOBALS['sync_basalam_jobs_runner_test_state']['events'];
    $check(array_search('release_global_lock', $events, true) < array_search('remote_post', $events, true), 'Successor must dispatch after releasing the global lock.');
    $batches = 1;
    while ($queue->jobs && $batches < 6) {
        $check(count($GLOBALS['sync_basalam_jobs_runner_test_state']['remote_requests']) === $batches, 'Each unfinished batch must dispatch exactly one successor despite the self-request guard.');
        $processed += $batch->invoke($runner);
        $batches++;
    }
    $check($processed === 5 && !$queue->jobs && !$executor->locked, 'All five jobs must drain exactly once.');
    $check(count($GLOBALS['sync_basalam_jobs_runner_test_state']['remote_requests']) === $batches - 1, 'Empty queue must not dispatch another worker.');
    echo "PASS: multi-batch continuation, exactly one successor, lock release before dispatch, empty-queue stop\n";

    // A temporarily unrunnable queue must wait for recovery, not spin requests.
    $queue->jobs[6] = (object) ['id' => 6, 'job_type' => 'local_no_network_fixture'];
    $executor->runnable = false;
    update_option('sync_basalam_jobs_runner_last_run', 0);
    $requests = count($GLOBALS['sync_basalam_jobs_runner_test_state']['remote_requests']);
    $check($batch->invoke($runner) === 0, 'Blocked job must not execute.');
    $check(count($GLOBALS['sync_basalam_jobs_runner_test_state']['remote_requests']) === $requests, 'No-progress batch must not dispatch a successor.');
    echo "PASS: no-progress queue does not spin requests\n";

    // With the next rate slot beyond the batch deadline, handoff is bounded.
    $executor->runnable = true;
    $GLOBALS['fixture_tasks_per_minute'] = 1;
    $GLOBALS['sync_basalam_jobs_runner_test_state']['filter_values']['sync_basalam_jobs_runner_async_time_limit'] = .04;
    update_option('sync_basalam_jobs_runner_last_run', microtime(true));
    $start = microtime(true);
    $check($batch->invoke($runner) === 0, 'Rate-limited job must not execute early.');
    $check(microtime(true) - $start >= .035, 'A rate-limited handoff must consume the batch window rather than spin empty workers.');
    $check(count($GLOBALS['sync_basalam_jobs_runner_test_state']['remote_requests']) === $requests + 1, 'Rate-limited batch must dispatch exactly one successor.');
    $check(!$executor->locked, 'Rate-limited batch must release the lock.');
    echo "PASS: low-rate handoff waits, preserves rate limit and releases lock\n";
}
