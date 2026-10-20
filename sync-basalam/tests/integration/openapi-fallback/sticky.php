<?php

use SyncBasalam\Services\Api\OpenApiConnectionFallback;
use SyncBasalam\Services\Api\OpenApiFallbackPolicy;

resetFallbackPolicy();
$now = 2000000000;
$policy = new OpenApiFallbackPolicy(static function () use (&$now) { return $now; });

function stickyRequest(string $path, array $args = [])
{
    global $attempts, $urls, $policy;
    $attempts = 0; $urls = [];
    return (new OpenApiConnectionFallback($policy))->request('https://openapi.basalam.com' . $path,
        static function ($url) use ($args) {
            return wp_remote_request($url, array_merge([
                'timeout' => 2, 'redirection' => 0,
                'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer local-test-token'],
            ], $args));
        });
}

$counterPaths = ['/v1/categories', '/v1/vendor-parcels', '/v1/webhooks'];
for ($i = 0; $i < 9; $i++) {
    $now += 50;
    $mode = 'openapi_refused';
    $response = stickyRequest($counterPaths[$i % 3] . '?__sticky=count');
    check(wp_remote_retrieve_response_code($response) === 200 && $attempts === 2 && !$policy->isActive(),
        'Failure ' . ($i + 1) . ' still attempts OpenAPI and immediately recovers.');
    if ($i % 3 === 0) {
        $mode = 'healthy';
        $response = stickyRequest('/v1/categories?__sticky=success');
        check(wp_remote_retrieve_response_code($response) === 200 && $attempts === 1
            && count(readFallbackPolicy()['failures']) === $i + 1,
            'Interleaved successes do not erase recent errors.');
    }
}
check(count(readFallbackPolicy()['failures']) === 9, 'Different destinations/instances share one site failure window.');
$mode = 'healthy';
$attempts = 0;
$calls = [];
$response = (new OpenApiConnectionFallback($policy))->request('https://openapi.basalam.com/v1/categories?__sticky=dns',
    static function ($url) use (&$calls) {
        $calls[] = $url;
        if (parse_url($url, PHP_URL_HOST) === 'openapi.basalam.com') {
            return new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host');
        }
        return wp_remote_get($url, ['headers' => ['Authorization' => 'Bearer local-test-token']]);
    });
$until = $now + 48 * 3600;
check(wp_remote_retrieve_response_code($response) === 200 && count($calls) === 2
    && $policy->isActive() && readFallbackPolicy()['fallback_until'] === $until,
    'The tenth mixed cURL 6/7 failure inside ten minutes activates exactly 48 hours.');
check(readFallbackPolicy()['failures'] === [], 'An active cooldown does not keep a growing failure list.');

// All 14 mapped operations execute only the destination, even with a healthy primary.
foreach ([
    ['GET', '/v1/products/123', '/v4/products/123', 'core.basalam.com', null],
    ['PATCH', '/v1/products/123', '/v4/products/123', 'core.basalam.com', ['primary_price' => 78900, 'stock' => 0]],
    ['POST', '/v1/vendors/1/products', '/v4/vendors/1/products', 'core.basalam.com', $create],
    ['PATCH', '/v1/vendors/1/products/batch-updates', '/v4/vendors/1/products', 'core.basalam.com', $batch],
    ['GET', '/v1/categories', '/v3/categories', 'core.basalam.com', null],
    ['GET', '/v1/categories/1/attributes', '/v3/categories/1/attributes', 'core.basalam.com', null],
    ['GET', '/v1/vendors/1', '/v3/vendors/1', 'core.basalam.com', null],
    ['POST', '/v1/vendors/1/discounts', '/v3/vendors/1/discounts', 'core.basalam.com', ['discount' => 10]],
    ['DELETE', '/v1/vendors/1/discounts', '/v3/vendors/1/discounts', 'core.basalam.com', null],
    ['GET', '/v1/vendor-parcels', '/v3/vendor-parcels', 'order-processing.basalam.com', null],
    ['GET', '/v1/webhooks', '/v1/webhooks', 'webhook.basalam.com', null],
    ['POST', '/v1/webhooks', '/v1/webhooks', 'webhook.basalam.com', $webhookData],
    ['PATCH', '/v1/webhooks/701', '/v1/webhooks/701', 'webhook.basalam.com', $webhookData],
    ['DELETE', '/v1/webhooks/701', '/v1/webhooks/701', 'webhook.basalam.com', null],
] as [$method, $primary, $destination, $host, $body]) {
    $query = '?continue_on_error=true&__sticky=direct&__status=200&cursor=opaque%2B%2F%3D%3F%26%25';
    $args = ['method' => $method];
    if ($body !== null) $args['body'] = json_encode($body);
    $response = stickyRequest($primary . $query, $args);
    check(wp_remote_retrieve_response_code($response) === 200 && $attempts === 1
        && $urls === ['https://' . $host . $destination . $query],
        'Cooldown sends ' . $method . ' ' . $primary . ' directly to its own fallback.');
}

foreach ([401, 503] as $status) {
    $response = stickyRequest('/v1/categories?__sticky=http&__status=' . $status);
    check(wp_remote_retrieve_response_code($response) === $status && $attempts === 1,
        'A direct fallback HTTP ' . $status . ' is returned without probing OpenAPI.');
}
$mode = 'refused';
$response = stickyRequest('/v1/categories?__sticky=refused');
check(is_wp_error($response) && $attempts === 1 && readFallbackPolicy()['fallback_until'] === $until,
    'A fallback connection failure neither retries OpenAPI nor extends the deadline.');
$mode = 'primary_bad_tls';
$response = stickyRequest('/v1/categories?__sticky=tls', ['sslverify' => false]);
check(is_wp_error($response) && strpos($response->get_error_message(), 'cURL error 60') !== false && $attempts === 1,
    'Direct fallback still enforces certificate and hostname verification.');
$mode = 'healthy';
foreach (['__timeout=1', '__disconnect=1'] as $failure) {
    $response = stickyRequest('/v1/categories?__sticky=ambiguous&' . $failure, ['timeout' => 0.2]);
    check(is_wp_error($response) && $attempts === 1, 'Direct fallback ambiguous failures cannot replay: ' . $failure);
}
$mode = 'redirect_failure';
$response = stickyRequest('/v1/categories?__sticky=redirect&__redirect=1', ['redirection' => 3]);
check(is_wp_error($response) && $attempts === 2 && readFallbackPolicy()['fallback_until'] === $until,
    'A direct fallback redirect failure is final and cannot restart the operation.');
check(!has_filter('https_ssl_verify') && !has_action('requests-requests.before_redirect'),
    'Direct fallback cleans up scoped hooks after every failure.');
$mode = 'healthy';
$response = stickyRequest('/v1/unmapped?__sticky=unknown');
check($attempts === 1 && $urls === ['https://openapi.basalam.com/v1/unmapped?__sticky=unknown'],
    'An unmapped route retains its original destination during cooldown.');

$now = $until - 1;
$response = stickyRequest('/v1/categories?__sticky=last_second');
check($attempts === 1 && strpos($urls[0], 'https://core.') === 0 && readFallbackPolicy()['fallback_until'] === $until,
    'The fixed deadline remains active until its last second.');
$now = $until;
$response = stickyRequest('/v1/categories?__sticky=expired');
check(wp_remote_retrieve_response_code($response) === 200 && $attempts === 1
    && $urls === ['https://openapi.basalam.com/v1/categories?__sticky=expired'],
    'Exactly at 48 hours a healthy OpenAPI is tried again.');
$mode = 'openapi_refused';
$response = stickyRequest('/v1/categories?__sticky=new_window');
check($attempts === 2 && count(readFallbackPolicy()['failures']) === 1 && !$policy->isActive(),
    'A new outage starts from one failure after cooldown, retaining immediate recovery.');
resetFallbackPolicy();
