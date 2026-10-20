<?php

require __DIR__ . '/bootstrap.php';

use SyncBasalam\Services\Api\OpenApiConnectionFallback;
use SyncBasalam\Services\Api\OpenApiFallbackPolicy;

$assertions = 0;
function check($condition, string $description): void
{
    global $assertions;
    if (!$condition) throw new RuntimeException($description);
    $assertions++;
}

$now = 2000000000;
$policy = new OpenApiFallbackPolicy(static function () use (&$now) { return $now; });
resetFallbackPolicy();
for ($i = 0; $i < 9; $i++) $policy->recordConnectionFailure();
$now += 601;
$policy->recordConnectionFailure();
check(!$policy->isActive() && readFallbackPolicy()['failures'] === [$now], 'Failures older than ten minutes expire.');

seedFallbackPolicy(['failures' => array_fill(0, 9, $now - 600), 'fallback_until' => 0]);
$policy->recordConnectionFailure();
check($policy->isActive(), 'The exact ten-minute boundary is included.');
$until = readFallbackPolicy()['fallback_until'];
$now += 1;
$policy->recordConnectionFailure();
check(readFallbackPolicy()['fallback_until'] === $until, 'An in-flight failure cannot extend active routing.');
$now = $until;
check(!$policy->isActive(), 'The exact 48-hour boundary is expired.');
$policy->recordConnectionFailure();
check(readFallbackPolicy()['failures'] === [$now], 'An expired period starts a fresh rolling window.');

resetFallbackPolicy();
$fallback = new OpenApiConnectionFallback($policy);
foreach ([
    new WP_Error('http_request_failed', 'cURL error 28: timeout'),
    new WP_Error('http_request_failed', 'cURL error 60: certificate'),
    new WP_Error('http_request_failed', 'cURL error 56: disconnected'),
    new WP_Error('http_request_not_executed', 'cURL error 7'),
    new WP_Error('http_request_failed', 'cURL error 67'),
    ['response' => ['code' => 503]],
] as $error) {
    $calls = 0;
    $fallback->request('https://openapi.basalam.com/v1/categories', static function () use ($error, &$calls) {
        $calls++; return $error;
    });
    check($calls === 1 && readFallbackPolicy() === [], 'Only pre-send OpenAPI cURL 6/7 contributes.');
}
foreach ([
    'https://core.basalam.com/v3/categories',
    'https://order-processing.basalam.com/v3/vendor-parcels',
    'https://webhook.basalam.com/v1/webhooks',
    'https://openapi.basalam.com/v1/unmapped',
    'http://openapi.basalam.com/v1/categories',
    'https://openapi.basalam.com:8443/v1/categories',
    'https://user:pass@openapi.basalam.com/v1/categories',
] as $url) {
    $calls = 0;
    $fallback->request($url, static function () use (&$calls) {
        $calls++; return new WP_Error('http_request_failed', 'cURL error 7: refused');
    });
    check($calls === 1 && readFallbackPolicy() === [], 'Unmapped/foreign authorities cannot contribute: ' . $url);
}
$fallback->request('https://openapi.basalam.com/v1/categories', static function () {
    do_action('requests-requests.before_redirect');
    return new WP_Error('http_request_failed', 'cURL error 7: refused redirect target');
});
check(readFallbackPolicy() === [], 'A redirect target failure cannot contribute to the primary outage window.');

// Advisory lock unavailability must not prevent the existing immediate fallback.
$other = new wpdb('root', '', DB_NAME, '127.0.0.1:' . getenv('TEST_DB_PORT'));
$lock = 'sync_basalam_openapi_' . md5(DB_NAME . ':' . $wpdb->options);
$other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lock));
$calls = [];
$response = $fallback->request('https://openapi.basalam.com/v1/categories', static function ($url) use (&$calls) {
    $calls[] = $url;
    return count($calls) === 1 ? new WP_Error('http_request_failed', 'cURL error 7: refused') : ['response' => ['code' => 200]];
});
$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lock));
check(count($calls) === 2 && $response['response']['code'] === 200 && readFallbackPolicy() === [],
    'A bounded lock timeout retains immediate fallback.');

// Cross-process concurrency and restart persistence, with a real database.
function concurrentFailures(int $count): void
{
    $marker = tempnam(sys_get_temp_dir(), 'openapi-policy-');
    unlink($marker);
    $workers = [];
    try {
        for ($i = 0; $i < $count; $i++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __DIR__ . '/policy-worker.php', $marker],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $workers[] = [$process, $pipes];
        }
        touch($marker);
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            check(proc_close($process) === 0 && $output === 'ok', 'Concurrent worker completed: ' . $error);
        }
    } finally {
        if (is_file($marker)) unlink($marker);
    }
}

resetFallbackPolicy();
concurrentFailures(9);
check(count(readFallbackPolicy()['failures']) === 9 && !(new OpenApiFallbackPolicy())->isActive(),
    'Nine concurrent workers lose no failures and do not activate early.');
(new OpenApiFallbackPolicy())->recordConnectionFailure();
check((new OpenApiFallbackPolicy())->isActive(), 'A separate tenth worker activates persisted routing.');
$until = readFallbackPolicy()['fallback_until'];
concurrentFailures(20);
check(readFallbackPolicy()['fallback_until'] === $until, 'Twenty late workers cannot extend the deadline.');
$pipes = [];
$process = proc_open([PHP_BINARY, __DIR__ . '/policy-worker.php', 'read'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$output = stream_get_contents($pipes[1]); fclose($pipes[1]);
$error = stream_get_contents($pipes[2]); fclose($pipes[2]);
check(proc_close($process) === 0 && $output === 'true', 'A fresh PHP process sees the active state: ' . $error);

resetFallbackPolicy();
concurrentFailures(20);
check((new OpenApiFallbackPolicy())->isActive() && readFallbackPolicy()['failures'] === [],
    'A simultaneous burst crossing the threshold activates one bounded cooldown.');
check($wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
    OpenApiFallbackPolicy::OPTION_KEY)) === 'no', 'Policy storage does not autoload with every WordPress page.');

$previousTable = $wpdb->options;
$wpdb->options = 'wp_2_options';
$wpdb->query("CREATE TABLE {$wpdb->options} LIKE {$previousTable}");
check(!(new OpenApiFallbackPolicy())->isActive(), 'A different multisite options table has its own state.');
$wpdb->options = $previousTable;
check((new OpenApiFallbackPolicy())->isActive(), 'Returning to the first site restores its independent cooldown.');
resetFallbackPolicy();
echo json_encode(['policy_success' => true, 'assertions' => $assertions, 'real_database' => true,
    'concurrent_workers' => 49]) . "\n";
