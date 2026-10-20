<?php

require __DIR__ . '/bootstrap.php';
resetFallbackPolicy();

use SyncBasalam\Services\Api\OpenApiConnectionFallback;
use SyncBasalam\Services\Api\PatchApiService;
use SyncBasalam\Services\Orders\FetchOrdersService;
use SyncBasalam\SalamGate\Order\BasalamParcelReader;
use SyncBasalam\SalamGate\SalamGateException;

$assertions = 0;
function check($condition, string $description): void
{
    global $assertions;
    if (!$condition) throw new RuntimeException($description);
    $assertions++;
}

// Test-only routing to real TCP refusal or local TLS -> Postman simulation.
// Production uses normal DNS. This fixture keeps both real Host and TLS SNI.
$attempts = 0;
$mode = 'fallback';
$urls = [];
add_filter('http_request_args', static function (array $args): array {
    $args['sslcertificates'] = getenv('TEST_CA');
    return $args;
});
add_action('http_api_curl', function ($curl, $args, $url) use (&$attempts, &$mode, &$urls): void {
    $attempts++;
    $urls[] = $url;
    $port = getenv('TLS_PORT');
    if ($mode === 'refused'
        || (in_array($mode, ['fallback', 'bad_tls'], true) && $attempts === 1)
        || ($mode === 'redirect_failure' && $attempts > 1)
        || ($mode === 'fallback_redirect_failure' && $attempts !== 2)) {
        $port = getenv('CLOSED_PORT');
    } elseif (in_array($mode, ['bad_tls', 'primary_bad_tls'], true)) {
        $port = getenv('BAD_TLS_PORT');
    }
    $host = parse_url($url, PHP_URL_HOST);
    if ($mode === 'openapi_refused' && $host === 'openapi.basalam.com') $port = getenv('CLOSED_PORT');
    $requestPort = parse_url($url, PHP_URL_PORT) ?: 443;
    curl_setopt_array($curl, [
        CURLOPT_PROXY => '',
        CURLOPT_CONNECT_TO => [$host . ':' . $requestPort . ':127.0.0.1:' . $port],
        CURLOPT_CAINFO => getenv('TEST_CA'),
    ]);
}, 10, 3);

function runRequest(string $path = '/v1/categories', array $args = [], string $host = 'openapi.basalam.com')
{
    // Each legacy transport scenario tests immediate fallback independently.
    // Sustained-outage coverage below deliberately retains state across calls.
    resetFallbackPolicy();
    global $attempts, $urls;
    $attempts = 0;
    $urls = [];
    return (new OpenApiConnectionFallback())->request('https://' . $host . $path, static function (string $requestUrl) use ($args) {
        return wp_remote_request($requestUrl, array_merge([
            'timeout' => 2,
            'sslcertificates' => getenv('TEST_CA'),
            'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer local-test-token'],
        ], $args));
    });
}

$response = runRequest('/v1/products/123?source=sync&origin=openapi.basalam.com', [
    'method' => 'PATCH', 'body' => '{"primary_price":12300,"stock":2}',
]);
check(wp_remote_retrieve_response_code($response) === 200, 'A refused product PATCH recovers through Core.');
check($urls === [
    'https://openapi.basalam.com/v1/products/123?source=sync&origin=openapi.basalam.com',
    'https://core.basalam.com/v4/products/123?source=sync&origin=openapi.basalam.com',
], 'Only the authority and documented path version change; the query is preserved.');
check(!has_filter('https_ssl_verify'), 'Scoped SSL filter is removed after success.');
check(!has_action('requests-requests.before_redirect'), 'Redirect observer is removed after success.');

$mode = 'healthy';
check(wp_remote_retrieve_response_code(runRequest()) === 200 && $attempts === 1, 'Healthy OpenAPI requests do not try Core.');
check(wp_remote_retrieve_response_code(runRequest('/v4/products/123', [], 'core.basalam.com')) === 200 && $attempts === 1, 'An existing Core request is not retried.');
check(wp_remote_retrieve_response_code(runRequest('/v1/products/123', [], 'core.basalam.com')) === 404, 'The fixture rejects the old v1 path on Core, matching the observed live response.');

$mode = 'fallback';
$create = ['name' => 'fixture', 'category_id' => 1, 'status' => 2976, 'preparation_days' => 1, 'package_weight' => 50];
check(wp_remote_retrieve_response_code(runRequest('/v1/vendors/1/products', ['method' => 'POST', 'body' => json_encode($create)])) === 201 && $attempts === 2, 'A refused create uses the documented v4 vendor route exactly once.');
foreach ([
    ['/v1/categories', '/v3/categories', 'GET'],
    ['/v1/categories/1/attributes?exclude_multi_selects=true', '/v3/categories/1/attributes?exclude_multi_selects=true', 'GET'],
    ['/v1/vendors/1', '/v3/vendors/1', 'GET'],
    ['/v1/vendors/1/discounts', '/v3/vendors/1/discounts', 'POST'],
] as [$original, $core, $method]) {
    check(wp_remote_retrieve_response_code(runRequest($original, ['method' => $method])) === 200
        && $attempts === 2 && $urls[1] === 'https://core.basalam.com' . $core, 'Mapped Core operation: ' . $original);
}

foreach ([401, 403, 404, 429, 500, 503] as $status) {
    $mode = 'healthy';
    check(wp_remote_retrieve_response_code(runRequest('/v1/products/123?__status=' . $status)) === $status && $attempts === 1, 'HTTP ' . $status . ' from OpenAPI must not replay the operation.');
    $mode = 'fallback';
    check(wp_remote_retrieve_response_code(runRequest('/v1/products/123?__status=' . $status)) === $status && $attempts === 2, 'HTTP ' . $status . ' from Core must be returned without another retry.');
}

$mode = 'healthy';
$response = runRequest('/v1/products/123?__timeout=1', ['method' => 'PATCH', 'body' => '{}', 'timeout' => 0.2]);
check(is_wp_error($response) && strpos($response->get_error_message(), 'cURL error 28') !== false && $attempts === 1, 'An ambiguous write timeout is not replayed.');
$response = runRequest('/v1/products/123?__disconnect=1', ['method' => 'PATCH', 'body' => '{}']);
check(is_wp_error($response) && $attempts === 1, 'A disconnected response is not replayed.');

$mode = 'bad_tls';
$response = runRequest('/v1/categories', ['sslverify' => false]);
check(is_wp_error($response) && strpos($response->get_error_message(), 'cURL error 60') !== false && $attempts === 2, 'Core verifies TLS even if the original arguments disabled verification.');
check(!has_filter('https_ssl_verify'), 'A TLS failure also removes the scoped filter.');
$mode = 'primary_bad_tls';
$response = runRequest();
check(is_wp_error($response) && strpos($response->get_error_message(), 'cURL error 60') !== false && $attempts === 1, 'A primary TLS error does not trigger fallback.');

$mode = 'refused';
$response = runRequest('/v1/categories', [], 'api.hamsalam.ir');
check(is_wp_error($response) && $attempts === 1, 'Other services do not use the Core fallback.');
$response = runRequest();
check(is_wp_error($response) && $attempts === 2, 'A failed Core connection is the final attempt.');

foreach ([
    'https://openapi.basalam.com/v1/webhooks/events',
    'https://openapi.basalam.com/v1/webhooks/customers',
    'https://openapi.basalam.com/v1/webhooks/701/logs',
    'https://openapi.basalam.com/v1/webhooks/not-an-id',
    'https://openapi.basalam.com/v1/vendor-parcels/123',
    'https://openapi.basalam.com/v1/vendors/1/products/batch-updates/unsupported',
    'https://openapi.basalam.com/v1/products/123/unsupported',
    'https://openapi.basalam.com.other.test/v1/products/123',
    'https://openapi.basalam.com:8443/v1/products/123',
    'https://user:pass@openapi.basalam.com/v1/products/123',
    'http://openapi.basalam.com/v1/products/123',
] as $url) {
    $calls = [];
    $response = (new OpenApiConnectionFallback())->request($url, static function ($target) use (&$calls) {
        $calls[] = $target;
        return new WP_Error('http_request_failed', 'cURL error 7: Could not connect');
    });
    check(is_wp_error($response) && $calls === [$url], 'No guessed Core route or unsupported authority: ' . $url);
}

$mode = 'healthy';
$attempts = 0;
$response = (new OpenApiConnectionFallback())->request('https://OPENAPI.BASALAM.COM:443/v1/categories?origin=openapi.basalam.com', static function ($url) {
    if (strpos($url, 'OPENAPI') !== false) return new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host');
    return wp_remote_request($url, ['timeout' => 2, 'sslcertificates' => getenv('TEST_CA'), 'headers' => ['Authorization' => 'Bearer local-test-token']]);
});
check(wp_remote_retrieve_response_code($response) === 200 && $attempts === 1, 'A DNS failure and uppercase hostname with explicit HTTPS port map correctly.');

$mode = 'redirect_failure';
$response = runRequest('/v1/vendors/1/products?__redirect=1', ['method' => 'POST', 'body' => '{"phase":"primary"}', 'redirection' => 3]);
check(is_wp_error($response) && $attempts === 2 && strpos(implode(' ', $urls), 'core.basalam.com') === false, 'A refused redirect target must not replay the original POST on Core.');
$mode = 'fallback_redirect_failure';
$response = runRequest('/v1/vendors/1/products?__redirect=1', ['method' => 'POST', 'body' => '{"phase":"fallback"}', 'redirection' => 3]);
check(is_wp_error($response) && $attempts === 3, 'A redirect failure after the Core POST must not replay it.');

// Exercise the actual installed product transport and response/circuit handling.
$mode = 'fallback';
$attempts = 0;
$urls = [];
$result = (new PatchApiService())->send('https://openapi.basalam.com/v1/products/123', ['primary_price' => 45600, 'stock' => 3]);
check($result['status_code'] === 200 && $result['success'] === true && $attempts === 2
    && $urls[1] === 'https://core.basalam.com/v4/products/123', 'Actual PatchApiService dispatches the changed URL before handling the response.');
check(get_option('sync_basalam_circuit_breaker')['failure_count'] === 0, 'A recovered connection does not count as a circuit-breaker failure.');

// The actual bulk job uses PATCH with v4 price fields, not POST or v3 price.
$batchPath = '/v1/vendors/1/products/batch-updates?continue_on_error=true&source=batch';
$batch = ['data' => [
    ['id' => 123, 'primary_price' => 0, 'stock' => 0, 'sku' => 'SIMPLE-123'],
    ['id' => 124, 'sku' => 'VARIABLE-124', 'variants' => [
        ['id' => 321, 'primary_price' => 23400, 'stock' => 3],
        ['id' => 322, 'primary_price' => 0, 'stock' => 0],
    ]],
]];
$attempts = 0; $urls = [];
$result = (new PatchApiService())->send('https://openapi.basalam.com' . $batchPath, $batch);
check($result['status_code'] === 202 && $result['success'] && $attempts === 2,
    'Actual batch PATCH recovers from connection refusal through Core v4.');
check($urls === ['https://openapi.basalam.com' . $batchPath,
    'https://core.basalam.com/v4/vendors/1/products?continue_on_error=true&source=batch'],
    'Batch fallback preserves the vendor id and continue_on_error query.');
check(array_column(json_decode($result['body'], true), 'id') === [123, 124],
    'The per-item 202 response reaches the batch caller unchanged.');
check(get_option('sync_basalam_circuit_breaker')['failure_count'] === 0,
    'A recovered batch does not count as a circuit-breaker failure.');
$mode = 'healthy';
$response = runRequest($batchPath, ['method' => 'PATCH', 'body' => json_encode($batch)]);
check(wp_remote_retrieve_response_code($response) === 202 && $attempts === 1,
    'Healthy OpenAPI batch requests do not try Core.');
$mode = 'fallback';
$attempts = 0; $urls = [];
$result = (new PatchApiService())->send('https://openapi.basalam.com' . $batchPath . '&__partial=1', $batch);
$items = json_decode($result['body'], true);
check($result['status_code'] === 202 && $attempts === 2
    && !$items[0]['has_error'] && $items[1]['has_error'] && $items[1]['error_message'] === 'SKU already exists',
    'A partial batch result is preserved without replaying successful items.');
foreach ([401, 403, 404, 422, 429, 500, 503] as $status) {
    foreach (['healthy' => 1, 'fallback' => 2] as $failureMode => $expectedAttempts) {
        $mode = $failureMode;
        $response = runRequest($batchPath . '&__status=' . $status, ['method' => 'PATCH', 'body' => json_encode($batch)]);
        check(wp_remote_retrieve_response_code($response) === $status && $attempts === $expectedAttempts,
            'Batch HTTP ' . $status . ' is final after ' . $expectedAttempts . ' attempt(s).');
    }
}
$mode = 'healthy';
foreach (['__timeout=1', '__disconnect=1'] as $failure) {
    $response = runRequest($batchPath . '&' . $failure, ['method' => 'PATCH', 'body' => json_encode($batch), 'timeout' => 0.2]);
    check(is_wp_error($response) && $attempts === 1, 'An ambiguous batch write is not replayed: ' . $failure);
}
$mode = 'primary_bad_tls';
$response = runRequest($batchPath, ['method' => 'PATCH', 'body' => json_encode($batch)]);
check(is_wp_error($response) && $attempts === 1, 'A batch primary TLS failure does not trigger Core.');
$mode = 'bad_tls';
$response = runRequest($batchPath, ['method' => 'PATCH', 'body' => json_encode($batch), 'sslverify' => false]);
check(is_wp_error($response) && strpos($response->get_error_message(), 'cURL error 60') !== false && $attempts === 2,
    'The batch fallback enforces TLS verification.');
$mode = 'refused';
$response = runRequest($batchPath, ['method' => 'PATCH', 'body' => json_encode($batch)]);
check(is_wp_error($response) && $attempts === 2, 'Two refused batch connections stop without a third request.');
$mode = 'healthy';
$attempts = 0;
$response = (new OpenApiConnectionFallback())->request('https://openapi.basalam.com' . $batchPath, static function ($url) use ($batch) {
    if (parse_url($url, PHP_URL_HOST) === 'openapi.basalam.com') return new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host');
    return wp_remote_request($url, ['method' => 'PATCH', 'body' => json_encode($batch), 'headers' => [
        'Content-Type' => 'application/json', 'Authorization' => 'Bearer local-test-token',
    ]]);
});
check(wp_remote_retrieve_response_code($response) === 202 && $attempts === 1, 'A batch DNS failure reaches Core v4.');
$mode = 'redirect_failure';
$response = runRequest($batchPath . '&__redirect=1', ['method' => 'PATCH', 'body' => json_encode($batch), 'redirection' => 3]);
check(is_wp_error($response) && $attempts === 2 && strpos(implode(' ', $urls), 'core.basalam.com') === false,
    'A batch redirect failure cannot replay an already-sent PATCH on Core.');
$mode = 'fallback_redirect_failure';
$response = runRequest($batchPath . '&__redirect=1', ['method' => 'PATCH', 'body' => json_encode($batch), 'redirection' => 3]);
check(is_wp_error($response) && $attempts === 3, 'A Core batch redirect failure cannot replay the batch.');
$mode = 'fallback';

// Both order-list consumers use the documented Order Processing v3 route.
$query = '?per_page=10&created_at%5Bgte%5D=2026-10-12T00%3A00%3A00%2B00%3A00&cursor=opaque%2B%2F%3D%3F%26%25';
$response = runRequest('/v1/vendor-parcels' . $query);
check(wp_remote_retrieve_response_code($response) === 200 && $attempts === 2, 'A refused order list recovers through Order Processing.');
check($urls === ['https://openapi.basalam.com/v1/vendor-parcels' . $query,
    'https://order-processing.basalam.com/v3/vendor-parcels' . $query], 'Order filters and the opaque cursor are preserved byte for byte.');
check(json_decode(wp_remote_retrieve_body($response), true)['data'][0]['order']['id'] === 2002, 'The fallback preserves the importer response structure.');
$mode = 'healthy';
check(wp_remote_retrieve_response_code(runRequest('/v1/vendor-parcels' . $query)) === 200 && $attempts === 1, 'Healthy OpenAPI order lists do not try the fallback.');
check(wp_remote_retrieve_response_code(runRequest('/v3/vendor-parcels' . $query, [], 'order-processing.basalam.com')) === 200 && $attempts === 1, 'Direct Order Processing requests have no recursive fallback.');
foreach ([401, 403, 404, 422, 429, 500, 503] as $status) {
    foreach (['healthy' => 1, 'fallback' => 2] as $failureMode => $expectedAttempts) {
        $mode = $failureMode;
        $response = runRequest('/v1/vendor-parcels?__status=' . $status);
        check(wp_remote_retrieve_response_code($response) === $status && $attempts === $expectedAttempts,
            'Order HTTP ' . $status . ' is final after ' . $expectedAttempts . ' attempt(s).');
    }
}
$mode = 'healthy';
foreach (['__timeout=1', '__disconnect=1'] as $failure) {
    $response = runRequest('/v1/vendor-parcels?' . $failure, ['timeout' => 0.2]);
    check(is_wp_error($response) && $attempts === 1, 'An ambiguous order transport failure is not replayed: ' . $failure);
}
$mode = 'bad_tls';
$response = runRequest('/v1/vendor-parcels', ['sslverify' => false]);
check(is_wp_error($response) && strpos($response->get_error_message(), 'cURL error 60') !== false && $attempts === 2, 'The order fallback enforces certificate and hostname verification.');
check(!has_filter('https_ssl_verify'), 'The order fallback removes its TLS filter after failure.');
$mode = 'primary_bad_tls';
$response = runRequest('/v1/vendor-parcels');
check(is_wp_error($response) && $attempts === 1, 'Primary order TLS failures do not trigger fallback.');
$mode = 'refused';
$response = runRequest('/v1/vendor-parcels');
check(is_wp_error($response) && $attempts === 2, 'Two refused order connections stop without a third request.');
$mode = 'healthy';
$attempts = 0;
$response = (new OpenApiConnectionFallback())->request('https://openapi.basalam.com/v1/vendor-parcels' . $query, static function ($url) {
    if (parse_url($url, PHP_URL_HOST) === 'openapi.basalam.com') return new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host');
    return wp_remote_get($url, ['headers' => ['Authorization' => 'Bearer local-test-token']]);
});
check(wp_remote_retrieve_response_code($response) === 200 && $attempts === 1, 'An order DNS failure reaches the documented fallback.');

$mode = 'fallback';
$orders = new FetchOrdersService();
$attempts = 0; $urls = [];
$first = $orders->fetchPage(7);
check($first['success'] && $first['orders'][0]['order']['id'] === 2001 && $first['next_cursor'] === 'opaque+/=?&%' && $attempts === 2, 'The actual importer reads the first fallback page.');
$attempts = 0; $urls = [];
$second = $orders->fetchPage(7, $first['next_cursor']);
check($second['success'] && $second['orders'][0]['order']['id'] === 2002 && $second['next_cursor'] === null && $attempts === 2, 'The actual importer reads the next fallback page and stops.');
check(get_option('sync_basalam_circuit_breaker')['failure_count'] === 0, 'Recovered order pages do not trip the circuit breaker.');
$response = runRequest('/v1/vendor-parcels?__empty=1');
check(json_decode(wp_remote_retrieve_body($response), true) === ['data' => [], 'next_cursor' => null, 'previous_cursor' => null], 'An empty order page remains a successful empty result.');
$attempts = 0; $urls = [];
$first = BasalamParcelReader::orders(7);
check($first['data'][0]['order']['id'] === 2001 && $attempts === 2 && strpos($urls[1], 'https://order-processing.basalam.com/v3/vendor-parcels?') === 0, 'The actual SalamGate reader also uses the shared order fallback.');
$attempts = 0; $urls = [];
$second = BasalamParcelReader::orders(7, $first['next_cursor']);
check($second['data'][0]['order']['id'] === 2002 && $second['next_cursor'] === null && $attempts === 2, 'SalamGate preserves pagination through fallback.');
$mode = 'refused';
$attempts = 0; $urls = [];
try {
    BasalamParcelReader::parcel(1001);
    check(false, 'A refused direct parcel read must fail.');
} catch (SalamGateException $e) {
    check($attempts === 1 && $urls === ['https://order-processing.basalam.com/v3/vendor-parcels/1001'], 'A direct SalamGate parcel read is not rerouted.');
}

require __DIR__ . '/webhooks.php';
require __DIR__ . '/sticky.php';

define('WP_HTTP_BLOCK_EXTERNAL', true);
$response = runRequest();
check(is_wp_error($response) && $response->get_error_code() === 'http_request_not_executed' && $attempts === 0, 'WordPress external HTTP blocking is preserved.');
$response = runRequest('/v1/vendor-parcels');
check(is_wp_error($response) && $response->get_error_code() === 'http_request_not_executed' && $attempts === 0, 'Order fallback respects WordPress external HTTP blocking.');
$response = runRequest($batchPath, ['method' => 'PATCH', 'body' => json_encode($batch)]);
check(is_wp_error($response) && $response->get_error_code() === 'http_request_not_executed' && $attempts === 0, 'Batch fallback respects WordPress external HTTP blocking.');
$response = runRequest('/v1/webhooks', ['method' => 'POST', 'body' => json_encode($webhookData)]);
check(is_wp_error($response) && $response->get_error_code() === 'http_request_not_executed' && $attempts === 0, 'Webhook fallback respects WordPress external HTTP blocking.');

echo json_encode(['success' => true, 'assertions' => $assertions, 'wordpress' => $wp_version]) . "\n";
