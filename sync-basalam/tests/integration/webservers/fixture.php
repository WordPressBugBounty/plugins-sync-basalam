<?php
/** Local-only HTTP fixture; mounted exclusively by the webserver test stack. */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local' || !getenv('MATRIX_TOKEN')) return;

// Keep loopbacks real; refuse remote APIs, email and update checks.
add_filter('pre_http_request', static function ($preempt, $args, $url) {
    if (wp_parse_url($url, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST)) return $preempt;
    return new WP_Error('matrix_external_http_blocked', 'Only local matrix loopbacks are allowed.');
}, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', '__return_false');
add_filter('sync_basalam_jobs_runner_async_time_limit', static function () { return 1.0; });

add_action('plugins_loaded', static function () {
    if (!function_exists('syncBasalamContainer')) return;

    $fixture = new class implements \SyncBasalam\Jobs\JobType {
        public function getType(): string { return 'sync_basalam_matrix'; }
        public function getPriority(): int { return -100; }
        public function canRun(): bool { return true; }
        public function execute(array $payload) {
            $key = $payload['key'];
            $events = get_option('sync_basalam_matrix_events', []);
            $event = $events[$key] ?? ['starts' => 0, 'returns' => 0];
            $event['starts']++;
            $event['pid'] = getmypid();
            $event['sapi'] = PHP_SAPI;
            $event['noabort'] = $_SERVER['noabort'] ?? $_SERVER['REDIRECT_noabort'] ?? getenv('noabort') ?: null;
            $environment = new \SyncBasalam\Services\AsyncWorkerEnvironment();
            $event['litespeed_module'] = $environment->hasFlag('SYNCBASALAM_LITESPEED');
            $event['other_module'] = $environment->hasFlag('SYNCBASALAM_NOT_LITESPEED');
            $event['started_at'] = microtime(true);
            $job = syncBasalamContainer()->get(\SyncBasalam\JobManager::class)->getJob(['job_type' => 'sync_basalam_matrix', 'status' => 'processing']);
            $event['history'][] = ['pid' => getmypid(), 'time' => $event['started_at'], 'job_id' => (int) $job->id, 'attempts' => (int) $job->attempts];
            $events[$key] = $event;
            update_option('sync_basalam_matrix_events', $events, false);
            usleep((int) ($payload['seconds'] * 1000000));
            if ($payload['mode'] === 'fatal') trigger_error('Intentional matrix worker fatal', E_USER_ERROR);
            $events = get_option('sync_basalam_matrix_events', []);
            $events[$key]['returns']++;
            $events[$key]['returned_at'] = microtime(true);
            update_option('sync_basalam_matrix_events', $events, false);
            if ($payload['mode'] === 'reject') {
                return \SyncBasalam\Jobs\JobResult::failed(new \SyncBasalam\Jobs\Exceptions\NonRetryableException('matrix API rejection (422)', 422));
            }
            return \SyncBasalam\Jobs\JobResult::success();
        }
    };
    syncBasalamContainer()->get(\SyncBasalam\Jobs\JobRegistry::class)->register($fixture);

    // Control requests inspect/prepare evidence without accidentally restarting a worker.
    if (($_REQUEST['action'] ?? '') === 'sync_basalam_matrix_control' || defined('WP_INSTALLING')) {
        $runner = syncBasalamContainer()->get(\SyncBasalam\JobsRunner::class);
        remove_action('shutdown', [$runner, 'maybeDispatchAsyncRequest'], 2);
        remove_action('sync_basalam_job_created', [$runner, 'maybeDispatchAsyncRequest'], 10);
    }
});

$control = static function () {
    if (!hash_equals(getenv('MATRIX_TOKEN'), $_SERVER['HTTP_X_MATRIX_TOKEN'] ?? '')) {
        wp_send_json_error(['message' => 'Invalid matrix token'], 403);
    }
    global $wpdb;
    $queue = syncBasalamContainer()->get(\SyncBasalam\JobManager::class);
    $runner = syncBasalamContainer()->get(\SyncBasalam\JobsRunner::class);
    $lock = syncBasalamContainer()->get(\SyncBasalam\Jobs\LockManager::class);
    $table = $wpdb->prefix . 'sync_basalam_job_manager';
    $op = $_POST['op'] ?? 'state';
    if ($op === 'reset') {
        if (!$lock->acquireGlobalJobsLock()) wp_send_json_error(['message' => 'Worker still active'], 409);
        $wpdb->delete($table, ['job_type' => 'sync_basalam_matrix']);
        foreach (['sync_basalam_async_worker_health', 'sync_basalam_matrix_events', 'sync_basalam_jobs_runner_last_run', 'sync_basalam_circuit_breaker'] as $name) delete_option($name);
        foreach (['sync_basalam_jobs_runner_async_dispatch_lock', 'sync_basalam_jobs_runner_idle_probe_lock'] as $name) delete_transient($name);
        wp_clear_scheduled_hook('sync_basalam_jobs_runner_recovery');
        $lock->releaseGlobalJobsLock();
    } elseif ($op === 'enqueue') {
        $key = sanitize_key($_POST['key'] ?? 'job');
        $mode = $_POST['mode'] ?? 'success';
        if (!in_array($mode, ['success', 'reject', 'fatal'], true)) wp_send_json_error([], 400);
        $seconds = max(0.0, min(60.0, (float) ($_POST['seconds'] ?? 0)));
        $queue->createJob('sync_basalam_matrix', 'pending', wp_json_encode(compact('key', 'mode', 'seconds')));
        $id = (int) $wpdb->insert_id;
        update_option('sync_basalam_jobs_runner_last_run', 0, false);
        if (!empty($_POST['dispatch'])) $runner->maybeDispatchAsyncRequest();
        wp_send_json_success(['id' => $id, 'dispatch_returned_at' => microtime(true)]);
    } elseif (in_array($op, ['age', 'retry'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        $job = $queue->getJob(['id' => $id, 'job_type' => 'sync_basalam_matrix']);
        if (!$job) wp_send_json_error([], 404);
        $values = ['started_at' => time() - 121];
        if ($op === 'retry') {
            $payload = json_decode($job->payload, true);
            $payload['mode'] = 'success';
            $payload['seconds'] = 0;
            $values['payload'] = wp_json_encode($payload);
            $values['retry_after'] = 0;
            update_option('sync_basalam_jobs_runner_last_run', 0, false);
        }
        $queue->updateJob($values, ['id' => $id]);
    } elseif ($op === 'lease') {
        $name = 'sync_basalam_jobs_runner_async_dispatch_lock';
        delete_transient($name);
        add_option('_transient_' . $name, 1, '', false);
        delete_option('_transient_timeout_' . $name);
    } elseif ($op === 'dispatch') {
        $runner->maybeDispatchAsyncRequest();
    } elseif ($op === 'recover') {
        // WordPress removes a single event before invoking its callback.
        wp_clear_scheduled_hook('sync_basalam_jobs_runner_recovery');
        do_action('sync_basalam_jobs_runner_recovery');
    } elseif ($op === 'rules') {
        wp_send_json_success(['text' => \SyncBasalam\Services\AsyncWorkerProtection::rules()]);
    } elseif ($op === 'notice' || $op === 'protect') {
        $_GET['page'] = 'sync_basalam';
        if (($_POST['role'] ?? 'administrator') === 'anonymous') wp_set_current_user(0);
        else {
            $users = get_users(['role' => sanitize_key($_POST['role'] ?? 'administrator'), 'number' => 1]);
            if (!$users) wp_send_json_error([], 404);
            wp_set_current_user($users[0]->ID);
        }
        if ($op === 'protect') {
            if (!empty($_POST['force']) && current_user_can('manage_options')) {
                delete_option(\SyncBasalam\Services\AsyncWorkerProtection::OPTION_KEY);
            }
            (new \SyncBasalam\Services\AsyncWorkerProtection())->ensureInstalled();
        }
        ob_start();
        $runner->inspectWorkerHealth();
        wp_send_json_success([
            'html' => ob_get_clean(),
            'protection' => get_option(\SyncBasalam\Services\AsyncWorkerProtection::OPTION_KEY, []),
        ]);
    } elseif ($op !== 'state') {
        wp_send_json_error([], 400);
    }
    $free = $lock->acquireGlobalJobsLock();
    if ($free) $lock->releaseGlobalJobsLock();
    $health = new \SyncBasalam\Services\AsyncWorkerHealth();
    wp_send_json_success([
        'nonce' => wp_create_nonce('sync_basalam_run_jobs_async'),
        'sapi' => PHP_SAPI,
        'php' => PHP_VERSION,
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? '',
        'protection' => get_option(\SyncBasalam\Services\AsyncWorkerProtection::OPTION_KEY, []),
        'backup_sha256' => hash('sha256', base64_decode((get_option(\SyncBasalam\Services\AsyncWorkerProtection::BACKUP_OPTION_KEY, [])['content_base64'] ?? ''))),
        'backup_autoload' => $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", \SyncBasalam\Services\AsyncWorkerProtection::BACKUP_OPTION_KEY)),
        'effective_noabort' => (new \SyncBasalam\Services\AsyncWorkerEnvironment())->hasFlag('noabort'),
        'jobs' => $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE job_type = %s ORDER BY id", 'sync_basalam_matrix')),
        'events' => (object) get_option('sync_basalam_matrix_events', []),
        'health' => get_option(\SyncBasalam\Services\AsyncWorkerHealth::OPTION_KEY, []),
        'notice' => $health->getNotice(),
        'lock_free' => $free,
        'recovery_at' => wp_next_scheduled('sync_basalam_jobs_runner_recovery'),
        'autoload' => $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", \SyncBasalam\Services\AsyncWorkerHealth::OPTION_KEY)),
    ]);
};
add_action('wp_ajax_sync_basalam_matrix_control', $control);
add_action('wp_ajax_nopriv_sync_basalam_matrix_control', $control);
