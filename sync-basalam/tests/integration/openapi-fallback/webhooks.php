<?php
resetFallbackPolicy();

// Real WordPress HTTP and the application's CRUD/setup consumers, against the
// stateful Postman fixture. All tokens, callback URLs and subscriptions are local.
$webhookData = [
    'event_ids' => [3, 5, 7],
    'request_headers' => json_encode(['token' => 'local-webhook-token']),
    'request_method' => 'POST',
    'url' => get_site_url() . '/wp-json/sync-basalam/v1/order-manager',
    'is_active' => true,
    'register_me' => true,
];
$webhookApi = new \SyncBasalam\Services\ApiServiceManager();
$webhookUrl = 'https://openapi.basalam.com/v1/webhooks';
$mode = 'fallback';
$attempts = 0; $urls = [];
$list = $webhookApi->get($webhookUrl . '?event_ids=3%2C5%2C7&service_id=1');
check($list['status_code'] === 200 && json_decode($list['body'], true)['data'] === [] && $attempts === 2,
    'Actual webhook GET recovers through the service and reads an empty list.');
check($urls === [$webhookUrl . '?event_ids=3%2C5%2C7&service_id=1',
    'https://webhook.basalam.com/v1/webhooks?event_ids=3%2C5%2C7&service_id=1'],
    'Webhook list filters are preserved byte for byte.');
$attempts = 0; $urls = [];
$created = $webhookApi->post($webhookUrl, $webhookData);
$webhookId = json_decode($created['body'], true)['id'];
check($created['status_code'] === 200 && $attempts === 2 && $webhookId === 701,
    'Actual webhook POST creates exactly one subscription through fallback.');
$update = $webhookData;
unset($update['register_me']);
$update['is_active'] = false;
$attempts = 0; $urls = [];
$updated = $webhookApi->patch($webhookUrl . '/' . $webhookId, $update);
check($updated['status_code'] === 200 && $attempts === 2
    && json_decode($updated['body'], true)['is_active'] === false,
    'Actual webhook PATCH preserves false and updates the selected subscription.');
$attempts = 0; $urls = [];
$deleted = $webhookApi->delete($webhookUrl . '/' . $webhookId);
check($deleted['status_code'] === 200 && $attempts === 2 && json_decode($deleted['body'], true)['id'] === $webhookId,
    'Actual webhook DELETE uses the same numeric id and returns the provider response.');
check(get_option('sync_basalam_circuit_breaker')['failure_count'] === 0,
    'Recovered webhook CRUD does not count as circuit-breaker failure.');

// These diagnostic requests return before mutating the fixture's subscriptions.
foreach (['GET' => '/v1/webhooks', 'POST' => '/v1/webhooks',
    'PATCH' => '/v1/webhooks/701', 'DELETE' => '/v1/webhooks/701'] as $method => $path) {
    $args = ['method' => $method];
    if (in_array($method, ['POST', 'PATCH'], true)) $args['body'] = json_encode($webhookData);
    $mode = 'healthy';
    $response = runRequest($path . '?__status=200', $args);
    check(wp_remote_retrieve_response_code($response) === 200 && $attempts === 1,
        'Healthy OpenAPI webhook ' . $method . ' never calls the service fallback.');
    foreach ([401, 403, 404, 422, 429, 500, 503] as $status) {
        foreach (['healthy' => 1, 'fallback' => 2] as $failureMode => $expectedAttempts) {
            $mode = $failureMode;
            $response = runRequest($path . '?__status=' . $status, $args);
            check(wp_remote_retrieve_response_code($response) === $status && $attempts === $expectedAttempts,
                'Webhook ' . $method . ' HTTP ' . $status . ' is final after ' . $expectedAttempts . ' attempt(s).');
        }
    }
    if ($method === 'GET') continue;
    $mode = 'healthy';
    foreach (['__timeout=1', '__disconnect=1'] as $failure) {
        $response = runRequest($path . '?' . $failure, $args + ['timeout' => 0.2]);
        check(is_wp_error($response) && $attempts === 1,
            'An ambiguous webhook ' . $method . ' is not replayed: ' . $failure);
    }
    $mode = 'refused';
    $response = runRequest($path, $args);
    check(is_wp_error($response) && $attempts === 2, 'Two refused webhook ' . $method . ' connections stop.');
    $mode = 'redirect_failure';
    $response = runRequest($path . '?__redirect=1', $args + ['redirection' => 3]);
    check(is_wp_error($response) && $attempts === 2 && strpos(implode(' ', $urls), 'webhook.basalam.com') === false,
        'A webhook ' . $method . ' redirect failure cannot replay the original operation.');
    $mode = 'fallback_redirect_failure';
    $response = runRequest($path . '?__redirect=1', $args + ['redirection' => 3]);
    check(is_wp_error($response) && $attempts === 3, 'A fallback webhook ' . $method . ' redirect failure is final.');
}
$mode = 'primary_bad_tls';
$response = runRequest('/v1/webhooks', ['method' => 'POST', 'body' => json_encode($webhookData)]);
check(is_wp_error($response) && $attempts === 1, 'A primary webhook TLS error does not trigger fallback.');
$mode = 'bad_tls';
$response = runRequest('/v1/webhooks', ['method' => 'POST', 'body' => json_encode($webhookData), 'sslverify' => false]);
check(is_wp_error($response) && strpos($response->get_error_message(), 'cURL error 60') !== false && $attempts === 2,
    'The webhook service fallback always verifies the certificate and hostname.');
check(!has_filter('https_ssl_verify') && !has_action('requests-requests.before_redirect'),
    'Webhook failures clean up TLS filters and redirect observers.');
$mode = 'healthy';
$attempts = 0; $urls = [];
$response = (new \SyncBasalam\Services\Api\OpenApiConnectionFallback())->request($webhookUrl, static function ($url) {
    if (parse_url($url, PHP_URL_HOST) === 'openapi.basalam.com') return new WP_Error('http_request_failed', 'cURL error 6: Could not resolve host');
    return wp_remote_get($url, ['headers' => ['Authorization' => 'Bearer local-test-token']]);
});
check(wp_remote_retrieve_response_code($response) === 200 && $attempts === 1, 'A webhook DNS error reaches the documented service.');
$mode = 'refused';
$response = runRequest('/v1/webhooks', [], 'webhook.basalam.com');
check(is_wp_error($response) && $attempts === 1, 'Direct webhook service requests have no recursive fallback.');

// Exercise the actual setup flow: list/create, list/update, then list/delete
// incomplete subscription/update complete subscription. Every OpenAPI request
// refuses TCP, rather than only the first request of each multi-step flow.
$GLOBALS['options']['sync_basalam_settings'] = [];
$attempts = 0; $urls = [];
\SyncBasalam\Services\WebhookService::onActivation();
check($attempts === 0, 'A new unconnected installation makes no webhook request before OAuth.');
$GLOBALS['options']['sync_basalam_settings'] = [
    'token' => 'local-test-token', 'webhook_header_token' => 'local-webhook-token',
];
$mode = 'openapi_refused';
resetFallbackPolicy();
$service = new \SyncBasalam\Services\WebhookService();
$attempts = 0; $urls = [];
\SyncBasalam\Services\WebhookService::onActivation();
check($attempts === 4 && end($GLOBALS['test_logs'])['level'] === 'info',
    'Activation creates the missing subscription through fallback and logs success.');
$attempts = 0; $urls = [];
\SyncBasalam\Services\WebhookService::onActivation();
check($attempts === 4, 'Repeated activation updates the existing subscription without creating a duplicate.');
$incomplete = $webhookData;
$incomplete['event_ids'] = [3];
$seed = $webhookApi->post($webhookUrl, $incomplete);
check($seed['status_code'] === 200, 'A synthetic incomplete subscription is registered in the local fixture.');
$attempts = 0; $urls = [];
check($service->setupWebhook() && $attempts === 6, 'Actual WebhookService falls back for list/delete/update when removing an incomplete subscription.');
$final = json_decode($webhookApi->get($webhookUrl)['body'], true)['data'];
check(count($final) === 1 && array_column($final[0]['events'], 'id') === [3, 5, 7]
    && $final[0]['url'] === $webhookData['url'] && $final[0]['request_headers'] === ['token' => 'local-webhook-token'],
    'The setup flow persists one complete subscription with the correct callback and verification header.');

// Other stores using the same app/account must survive automatic activation.
resetFallbackPolicy();
$foreignIds = [];
foreach ([[3], [3, 5, 7]] as $events) {
    $foreign = $webhookData;
    $foreign['url'] = 'https://another-store.test/wp-json/sync-basalam/v1/order-manager';
    $foreign['event_ids'] = $events;
    $foreign['is_active'] = false;
    $seed = $webhookApi->post('https://webhook.basalam.com/v1/webhooks', $foreign);
    $foreignIds[] = json_decode($seed['body'], true)['id'];
}
$before = json_decode($webhookApi->get('https://webhook.basalam.com/v1/webhooks')['body'], true)['data'];
$attempts = 0; $urls = [];
\SyncBasalam\Services\WebhookService::onActivation();
check($attempts === 4, 'Activation ignores both complete and incomplete subscriptions belonging to another store.');
$after = json_decode($webhookApi->get('https://webhook.basalam.com/v1/webhooks')['body'], true)['data'];
check(count($after) === 3 && array_filter($before, static function ($w) use ($foreignIds) {
        return in_array($w['id'], $foreignIds, true);
    }) === array_filter($after, static function ($w) use ($foreignIds) {
        return in_array($w['id'], $foreignIds, true);
    }), 'Foreign webhook ids, callback, events, activity and tokens remain unchanged.');

$mode = 'refused';
$attempts = 0; $urls = [];
\SyncBasalam\Services\WebhookService::onActivation();
check($attempts === 2 && end($GLOBALS['test_logs'])['level'] === 'error',
    'Activation survives both connection failures and records a visible error.');
$mode = 'fallback';
