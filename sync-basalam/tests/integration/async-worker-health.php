<?php

// Local WordPress + real MySQL queue/advisory locks. One child is killed mid-job.
// docker exec -w /var/www/html/wp-content/plugins/sync-basalam \
//   basalam-wordpress-local-wordpress-1 php tests/integration/async-worker-health.php
defined('DISABLE_WP_CRON') || define('DISABLE_WP_CRON', true);
require getenv('WORDPRESS_BOOTSTRAP') ?: dirname(__DIR__, 5) . '/wp-load.php';

use SyncBasalam\JobManager;
use SyncBasalam\Jobs\JobExecutor;
use SyncBasalam\Jobs\JobRegistry;
use SyncBasalam\Jobs\JobResult;
use SyncBasalam\Jobs\JobType;
use SyncBasalam\Jobs\LockManager;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\JobsRunner;
use SyncBasalam\Services\AsyncWorkerHealth;
use SyncBasalam\Services\CheckHttpBlockService;

if (wp_get_environment_type() !== 'local') throw new RuntimeException('Local environment only.');

$installedRunner = syncBasalamContainer()->get(JobsRunner::class);
remove_action('shutdown', [$installedRunner, 'maybeDispatchAsyncRequest'], 2);
remove_action('sync_basalam_job_created', [$installedRunner, 'maybeDispatchAsyncRequest'], 10);
remove_action('admin_notices', [$installedRunner, 'renderWorkerHealthNotice']);

class WorkerHealthFixture implements JobType
{
    public function getType(): string { return 'sync_basalam_health_integration'; }
    public function getPriority(): int { return 0; }
    public function canRun(): bool { return true; }
    public function execute(array $payload)
    {
        if (($payload['mode'] ?? '') === 'hang') sleep(30);
        if (($payload['mode'] ?? '') === 'reject') {
            return JobResult::failed(new NonRetryableException('fixture API rejection (422)', 422));
        }
        return JobResult::success();
    }
}

class FixtureQueue extends JobManager
{
    // Leave other local-development queues alone.
    public function hasPendingOrStaleProcessingJobs(int $timeout = 120): bool
    {
        return $this->getCountJobs(['job_type' => 'sync_basalam_health_integration', 'status' => ['pending', 'processing']]) > 0;
    }
}

class LiteSpeedHealthFixture extends AsyncWorkerHealth
{
    protected function isLiteSpeed(): bool { return true; }
}

$queue = new FixtureQueue();
$lock = new LockManager();
$health = new LiteSpeedHealthFixture();
$runner = new JobsRunner($queue, new JobExecutor($queue, $lock, new JobRegistry([new WorkerHealthFixture()])), new class {
    public function process(): void {}
}, new CheckHttpBlockService(), $health);
remove_action('shutdown', [$runner, 'maybeDispatchAsyncRequest'], 2);
remove_action('sync_basalam_job_created', [$runner, 'maybeDispatchAsyncRequest'], 10);
$run = new ReflectionMethod($runner, 'runAsyncBatch');
$run->setAccessible(true);
add_filter('sync_basalam_jobs_runner_async_time_limit', static function () { return 0.15; });

if (($argv[1] ?? '') === 'worker') {
    $_SERVER['noabort'] = '1';
    $run->invoke($runner);
    exit;
}

$table = $wpdb->prefix . 'sync_basalam_job_manager';
$snapshots = [];
foreach ([AsyncWorkerHealth::OPTION_KEY, 'sync_basalam_settings', 'sync_basalam_jobs_runner_last_run'] as $name) {
    $snapshots[$name] = get_option($name, null);
}
$originalGet = $_GET;
$originalServer = $_SERVER;
$originalUser = get_current_user_id();
$jobs = [];
$assertions = 0;
$scenarios = [];
$child = null;
$assert = static function (bool $ok, string $message) use (&$assertions): void {
    if (!$ok) throw new RuntimeException($message);
    $assertions++;
};
$create = static function (string $mode) use ($queue, $wpdb, &$jobs): int {
    $queue->createJob('sync_basalam_health_integration', 'pending', wp_json_encode(['mode' => $mode]));
    $id = (int) $wpdb->insert_id;
    $jobs[] = $id;
    update_option('sync_basalam_jobs_runner_last_run', 0, false);
    return $id;
};
$notice = static function () use ($runner): string {
    ob_start();
    $runner->renderWorkerHealthNotice();
    return ob_get_clean();
};

try {
    $assert($lock->acquireGlobalJobsLock(), 'Local runner must be idle before testing.');
    $lock->releaseGlobalJobsLock();
    $assert($queue->getCountJobs(['status' => 'processing']) === 0, 'Other local jobs must not be processing.');
    $assert($queue->getCountJobs(['status' => 'pending']) === 0, 'Other local jobs must not be pending during HTTP tests.');

    $response = wp_remote_post(admin_url('admin-ajax.php?action=sync_basalam_run_jobs_async'), [
        'timeout' => 10,
        'body' => ['action' => 'sync_basalam_run_jobs_async', 'nonce' => 'invalid-fixture-nonce'],
    ]);
    $assert(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 403, 'Actual AJAX endpoint must reject an invalid nonce.');
    $response = wp_remote_post(admin_url('admin-ajax.php?action=sync_basalam_run_jobs_async'), [
        'timeout' => 10,
        'body' => ['action' => 'sync_basalam_run_jobs_async', 'nonce' => wp_create_nonce('sync_basalam_run_jobs_async')],
    ]);
    $json = json_decode(wp_remote_retrieve_body($response), true);
    $assert(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200, 'Actual AJAX endpoint must accept a valid nonce.');
    $assert(($json['success'] ?? false) && ($json['data']['processed'] ?? null) === 0, 'Empty AJAX worker must report zero processed jobs.');
    $scenarios[] = 'actual HTTP AJAX nonce validation and empty response';
    delete_option(AsyncWorkerHealth::OPTION_KEY);
    $settings = get_option('sync_basalam_settings', []);
    $settings['tasks_per_minute'] = 600;
    $settings['tasks_per_minute_auto'] = false;
    update_option('sync_basalam_settings', $settings);

    $_SERVER['noabort'] = '1';
    $id = $create('success');
    $assert($run->invoke($runner) === 1, 'Protected real runner must execute a job.');
    $assert($queue->getJob(['id' => $id]) === null, 'Successful job must be removed normally.');
    $assert($health->getNotice() === null, 'Healthy worker must not alert.');
    $scenarios[] = 'actual queue success and normal cleanup';

    $id = $create('reject');
    $run->invoke($runner);
    $job = $queue->getJob(['id' => $id]);
    $assert($job->status === 'failed' && strpos($job->error_message, '422') !== false, 'API rejection must be preserved.');
    $assert($health->getNotice() === null, 'API rejection must not be a host alert.');
    $scenarios[] = 'explicit API rejection remains separate';

    unset($_SERVER['noabort']);
    $create('success');
    $run->invoke($runner);
    $assert($health->getNotice()['class'] === 'notice-warning', 'Missing noabort must warn even when a quick job returns.');
    $scenarios[] = 'missing protection persists after fast completion';

    $_GET['page'] = 'sync_basalam';
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    $assert(!empty($admins), 'A local administrator must exist.');
    wp_set_current_user($admins[0]->ID);
    $html = $notice();
    $assert(strpos($html, 'role="alert"') !== false && strpos($html, 'noabort') !== false, 'Authorized admin sees actionable RTL alert.');
    $assert(strpos($html, '&lt;IfModule') !== false, 'Technical rule must be escaped.');
    file_put_contents('/tmp/sync-basalam-health-warning.html', '<!doctype html><meta charset="utf-8"><body dir="rtl">' . $html);
    wp_set_current_user(0);
    $assert($notice() === '', 'Anonymous users cannot see admin notice.');
    wp_set_current_user($admins[0]->ID);
    $scenarios[] = 'real WordPress permissions and rendered notice';

    $_SERVER['noabort'] = '1';
    $id = $create('hang');
    $child = proc_open([PHP_BINARY, __FILE__, 'worker'], [0 => ['pipe', 'r'], 1 => ['file', '/tmp/sync-basalam-health-child.log', 'w'], 2 => ['file', '/tmp/sync-basalam-health-child.log', 'a']], $pipes);
    $assert(is_resource($child), 'Child worker must start.');
    $deadline = microtime(true) + 8;
    do {
        usleep(100000);
        $job = $queue->getJob(['id' => $id]);
    } while ($job->status !== 'processing' && microtime(true) < $deadline);
    $assert($job->status === 'processing', 'Child must reach execution under real MySQL lock.');
    $queue->updateJob(['started_at' => time() - 121], ['id' => $id]);
    $assert(!$lock->acquireGlobalJobsLock(), 'Concurrent request must not acquire live worker lock.');
    $assert($run->invoke($runner) === 0, 'Concurrent runner must not duplicate execution.');
    $assert(strpos($notice(), 'اجرای همگام‌سازی نیمه‌کاره متوقف شده است.') === false, 'A slow live worker must not be called interrupted.');
    $scenarios[] = 'real concurrent MySQL lock excludes slow active worker';

    proc_terminate($child, 9);
    proc_close($child);
    $child = null;
    $deadline = microtime(true) + 5;
    do {
        usleep(100000);
        $free = $lock->acquireGlobalJobsLock();
    } while (!$free && microtime(true) < $deadline);
    $assert($free, 'Killed worker must release MySQL lock on disconnect.');
    $lock->releaseGlobalJobsLock();
    $html = $notice();
    $assert(strpos($html, 'اجرای همگام‌سازی نیمه‌کاره متوقف شده است.') !== false, 'Next admin request must discover interruption without relying on dead worker.');
    $assert($health->getNotice()['job_id'] === $id, 'Alert must identify interrupted job.');
    $scenarios[] = 'SIGKILL interruption detected independently';

    $queue->updateJob(['retry_after' => time() + 60], ['id' => $id]);
    $assert($run->invoke($runner) === 0, 'Batch without eligible jobs must not claim recovery.');
    $assert($health->getNotice() !== null, 'Empty worker must not clear interruption warning.');
    $queue->updateJob(['payload' => wp_json_encode(['mode' => 'success']), 'retry_after' => 0], ['id' => $id]);
    update_option('sync_basalam_jobs_runner_last_run', 0, false);
    $assert($run->invoke($runner) === 1, 'Stale job must be retried by normal runner.');
    $assert($queue->getJob(['id' => $id]) === null, 'Retried job must complete normally.');
    $assert($health->getNotice() === null, 'Protected real execution must clear notice.');
    $scenarios[] = 'stale recovery followed by protected execution clears warning';

    echo wp_json_encode(['success' => true, 'scenarios' => $scenarios, 'assertions' => $assertions], JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    if (is_resource($child)) { proc_terminate($child, 9); proc_close($child); }
    foreach ($jobs as $id) $queue->deleteJob(['id' => $id]);
    foreach ($snapshots as $name => $value) {
        if ($value === null) delete_option($name);
        else update_option($name, $value, false);
    }
    $_GET = $originalGet;
    $_SERVER = $originalServer;
    wp_set_current_user($originalUser);
    $lock->releaseGlobalJobsLock();
}
