<?php
define('ABSPATH', __DIR__);
$state = [];
function add_action(...$args) {}
function current_filter() { return $GLOBALS['state']['filter'] ?? ''; }
function wp_doing_ajax() { return $GLOBALS['state']['ajax'] ?? false; }
function get_transient($name) { return $GLOBALS['state']['leases'][$name] ?? false; }
function set_transient($name, $value, $ttl = 0) {
    $GLOBALS['state']['leases'][$name] = $value;
    $GLOBALS['state']['writes'][] = [$name, $value, $ttl];
    return true;
}
function get_option($name, $default = false) { return $GLOBALS['state']['options'][$name] ?? $default; }
function wp_using_ext_object_cache() { return $GLOBALS['state']['external_cache'] ?? false; }
function wp_installing() { return false; }
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return $value; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($name, $value, $url) { return $url . '?' . $name . '=' . $value; }
function esc_url_raw($url) { return $url; }
function wp_create_nonce($action) { return 'test-nonce'; }
function apply_filters($name, $value) { return $value; }
function wp_remote_post($url, $args) { $GLOBALS['state']['requests'][] = [$url, $args]; return []; }

// Run in its own PHP process: php tests/JobsRunnerLeaseRegression.php
require_once dirname(__DIR__) . '/includes/Services/AsyncWorkerHealth.php';
require $argv[1] ?? dirname(__DIR__) . '/JobsRunner.php';

class QueueForLeaseTest {
    public $checks = 0;
    public $pending = true;
    public $onCheck;
    public function hasPendingOrStaleProcessingJobs($timeout) {
        $this->checks++;
        if ($this->onCheck) ($this->onCheck)();
        return $this->pending;
    }
}
class HttpForLeaseTest {
    public function SyncBasalamHttpBlock() { return false; }
}
const DISPATCH = 'sync_basalam_jobs_runner_async_dispatch_lock';
const IDLE = 'sync_basalam_jobs_runner_idle_probe_lock';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function resetRunner() {
    $GLOBALS['state'] = ['leases' => [], 'options' => [], 'writes' => [], 'requests' => []];
    $_REQUEST = [];
    $_COOKIE = [];
    $queue = new QueueForLeaseTest();
    return [new SyncBasalam\JobsRunner($queue, new stdClass(), new stdClass(), new HttpForLeaseTest()), $queue];
}
$cases = [
    'orphaned legacy dispatch lock resumes work' => function () {
        [$runner, $queue] = resetRunner();
        $GLOBALS['state']['leases'][DISPATCH] = 1;
        $runner->maybeDispatchAsyncRequest();
        check(count($GLOBALS['state']['requests']) === 1, 'legacy orphan blocked dispatch');
    },
    'legacy lock with a valid expiry still blocks duplicate dispatch' => function () {
        [$runner, $queue] = resetRunner();
        $GLOBALS['state']['leases'][DISPATCH] = 1;
        $GLOBALS['state']['options']['_transient_timeout_' . DISPATCH] = time() + 25;
        $runner->maybeDispatchAsyncRequest();
        check($queue->checks === 0 && !$GLOBALS['state']['requests'], 'valid legacy lease ignored');
    },
    'new lock stores a bounded deadline and preserves async arguments' => function () {
        [$runner, $queue] = resetRunner();
        $_COOKIE = ['test_cookie' => 'value'];
        $before = time();
        $runner->maybeDispatchAsyncRequest();
        [$name, $value, $ttl] = $GLOBALS['state']['writes'][0];
        check($name === DISPATCH && $ttl === 25 && $value >= $before + 25 && $value <= time() + 25, 'lease deadline missing');
        $args = $GLOBALS['state']['requests'][0][1];
        check($args['blocking'] === false && $args['timeout'] === 0.01 && $args['cookies'] === $_COOKIE, 'async contract changed');
    },
    'active timestamp still blocks when the timeout option disappears' => function () {
        [$runner, $queue] = resetRunner();
        $GLOBALS['state']['leases'][DISPATCH] = time() + 25;
        $runner->maybeDispatchAsyncRequest();
        check($queue->checks === 0 && !$GLOBALS['state']['requests'], 'active timestamp lease ignored');
    },
    'expired timestamp resumes work even when its timeout option is absent' => function () {
        [$runner, $queue] = resetRunner();
        $GLOBALS['state']['leases'][DISPATCH] = time() - 1;
        $runner->maybeDispatchAsyncRequest();
        check(count($GLOBALS['state']['requests']) === 1, 'expired orphan timestamp blocked work');
    },
    'expired timestamp resumes despite a stale future timeout option' => function () {
        [$runner, $queue] = resetRunner();
        $GLOBALS['state']['leases'][DISPATCH] = time() - 1;
        $GLOBALS['state']['options']['_transient_timeout_' . DISPATCH] = time() + 999;
        $runner->maybeDispatchAsyncRequest();
        check(count($GLOBALS['state']['requests']) === 1, 'value deadline was not authoritative');
    },
    'external object cache legacy lease keeps its cache TTL semantics' => function () {
        [$runner, $queue] = resetRunner();
        $GLOBALS['state']['external_cache'] = true;
        $GLOBALS['state']['leases'][DISPATCH] = 1;
        $runner->maybeDispatchAsyncRequest();
        check($queue->checks === 0 && !$GLOBALS['state']['requests'], 'external cache legacy lease ignored');
    },
    'orphaned idle probe does not permanently block queued work' => function () {
        [$runner, $queue] = resetRunner();
        $GLOBALS['state']['leases'][IDLE] = 1;
        $runner->maybeDispatchAsyncRequest();
        check(count($GLOBALS['state']['requests']) === 1, 'orphan idle probe blocked work');
    },
    'empty queue stores a five second idle deadline without dispatch' => function () {
        [$runner, $queue] = resetRunner();
        $queue->pending = false;
        $before = time();
        $runner->maybeDispatchAsyncRequest();
        [$name, $value, $ttl] = $GLOBALS['state']['writes'][0];
        check($name === IDLE && $ttl === 5 && $value >= $before + 5 && $value <= time() + 5, 'idle deadline missing');
        check(!$GLOBALS['state']['requests'], 'empty queue dispatched');
    },
    'new job bypasses an active idle probe' => function () {
        [$runner, $queue] = resetRunner();
        $GLOBALS['state']['leases'][IDLE] = time() + 5;
        $GLOBALS['state']['filter'] = 'sync_basalam_job_created';
        $runner->maybeDispatchAsyncRequest();
        check(count($GLOBALS['state']['requests']) === 1, 'new job was blocked by idle probe');
    },
    'second lease check respects a lease acquired during the queue probe' => function () {
        [$runner, $queue] = resetRunner();
        $queue->onCheck = function () { $GLOBALS['state']['leases'][DISPATCH] = time() + 25; };
        $runner->maybeDispatchAsyncRequest();
        check($queue->checks === 1 && !$GLOBALS['state']['requests'], 'second lease check ignored');
    },
    'async worker never recursively dispatches itself' => function () {
        [$runner, $queue] = resetRunner();
        $GLOBALS['state']['ajax'] = true;
        $_REQUEST['action'] = 'sync_basalam_run_jobs_async';
        $runner->maybeDispatchAsyncRequest();
        check($queue->checks === 0 && !$GLOBALS['state']['requests'], 'recursive async dispatch');
    },
];
foreach ($cases as $name => $run) {
    $run();
    echo "PASS: $name\n";
}
echo count($cases) . " regression cases passed.\n";
