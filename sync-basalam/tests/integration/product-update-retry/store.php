<?php

// Run through run.py --store-container. This exercises the registered AJAX
// action, real WooCommerce products, MariaDB metadata, jobs and HTTP transport.
define('DISABLE_WP_CRON', true);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require getenv('WORDPRESS_BOOTSTRAP') ?: dirname(__DIR__, 6) . '/wp-load.php';

use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsContainer;
use SyncBasalam\Jobs\JobExecutor;
use SyncBasalam\Jobs\JobRegistry;
use SyncBasalam\Jobs\LockManager;
use SyncBasalam\Jobs\Exceptions\JobException;
use SyncBasalam\Jobs\Types\BulkUpdateProductsJob;
use SyncBasalam\Jobs\Types\UpdateSingleProductJob;
use SyncBasalam\JobsRunner;
use SyncBasalam\JobManager;
use SyncBasalam\Services\Api\CircuitBreaker;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Services\Products\UpdateSingleProductService;
use SyncBasalam\Utilities\ProductMetaKey;

if (wp_get_environment_type() !== 'local' || !getenv('PRODUCT_RETRY_SIMULATION_URL')) {
    throw new RuntimeException('A local WordPress environment and Postman simulation are required.');
}
$lock = new LockManager();
if (!$lock->acquireGlobalJobsLock()) throw new RuntimeException('The local jobs worker is busy. Retry after it finishes.');

$jobManager = syncBasalamContainer()->get(JobManager::class);
$runner = syncBasalamContainer()->get(JobsRunner::class);
remove_action('shutdown', [$runner, 'maybeDispatchAsyncRequest'], 2);
remove_action('sync_basalam_job_created', [$runner, 'maybeDispatchAsyncRequest'], 10);
foreach (['save_post', 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation'] as $hook) {
    remove_all_actions($hook); // Only this test process; fixture saves must not enqueue work.
}

global $wpdb;
$table = $wpdb->prefix . 'sync_basalam_job_manager';
$originalJobs = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id", ARRAY_A);
$originalPost = $_POST;
$originalUser = get_current_user_id();
$options = [];
foreach (['sync_basalam_settings', 'woocommerce_currency', CircuitBreaker::OPTION_KEY,
    'sync_basalam_request_status_stats', 'sync_basalam_bulk_update_products_last_run',
    'sync_basalam_duplicate_connection_report'] as $key) {
    $options[$key] = get_option($key, null);
}
$products = [];
$jobIds = [];
$cases = [];
$assertions = 0;
$vendorId = random_int(910000000, 919999999);
$runId = 'store-' . bin2hex(random_bytes(6));
$current = [];
$attempts = [];

function same($expected, $actual, $message) {
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    $GLOBALS['assertions']++;
}
function refreshRetrySettings() {
    $property = new ReflectionProperty(SettingsContainer::class, 'settings');
    $property->setAccessible(true);
    $property->setValue(syncBasalamSettings(), null);
}
function beginRetryCase($name, array $statuses, array $settings = [], array $headers = []) {
    $GLOBALS['current'] = ['name' => $name, 'key' => $GLOBALS['runId'] . '-' . $name, 'statuses' => $statuses, 'headers' => $headers];
    $GLOBALS['attempts'] = [];
    $base = array_merge(SettingsConfig::getDefaultSettings(), array_fill_keys(SettingsConfig::CUSTOM_PRODUCT_UPDATE_FIELDS, 0), [
        'vendor_id' => $GLOBALS['vendorId'], 'token' => 'local-fixture-token',
        'sync_product_fields' => 'all', 'product_price_field' => 'original_price',
        'price_change_value' => '', 'round_price' => 'none', 'safe_stock' => 0,
        'variable_product_stock_source' => 'variation',
        'sync_status_product' => false, 'sync_status_product_create' => false, 'sync_status_product_update' => false,
    ], $settings);
    update_option('sync_basalam_settings', $base);
    update_option('woocommerce_currency', 'IRR');
    delete_option(CircuitBreaker::OPTION_KEY);
    refreshRetrySettings();
    $cache = new ReflectionProperty(CircuitBreaker::class, 'requestStateCache');
    $cache->setAccessible(true);
    $cache->setValue(null, null);
    // Each case represents a new request, with fresh service-local settings.
    $instances = new ReflectionProperty(syncBasalamContainer(), 'instances');
    $instances->setAccessible(true);
    $values = $instances->getValue(syncBasalamContainer());
    foreach ([ApiServiceManager::class, ProductOperations::class, UpdateSingleProductJob::class,
        BulkUpdateProductsJob::class, JobExecutor::class, JobRegistry::class] as $service) unset($values[$service]);
    $instances->setValue(syncBasalamContainer(), $values);
    foreach ($GLOBALS['parents'] ?? [] as $id) {
        update_post_meta($id, ProductMetaKey::basalamProductSyncStatus(), 'no');
        foreach (wc_get_product($id)->get_children() as $index => $variationId) {
            delete_post_meta($variationId, 'sync_basalam_variation_map');
            delete_post_meta($variationId, 'sync_basalam_variation_id');
            update_post_meta($variationId, 'sync_basalam_variation_id', 501 + $index);
        }
    }
}
function retryRequests() {
    $response = wp_remote_get(getenv('PRODUCT_RETRY_SIMULATION_URL') . '/__requests', [
        'headers' => ['x-mock-slug' => 'basalam'], 'timeout' => 5,
    ]);
    if (is_wp_error($response)) throw new RuntimeException($response->get_error_message());
    return array_values(array_filter(json_decode(wp_remote_retrieve_body($response), true), function ($request) {
        return $request['test'] === $GLOBALS['current']['key'];
    }));
}
function finishRetryCase($count, $mode = 'all') {
    $requests = retryRequests();
    same($count, count($requests), $GLOBALS['current']['name'] . ' HTTP count');
    same($mode, get_option('sync_basalam_settings')['sync_product_fields'], 'Saved mode is unchanged');
    $GLOBALS['cases'][] = ['name' => $GLOBALS['current']['name'], 'requests' => count($requests)];
    return $requests;
}
function priceStockOnly(array $payload, $batch = false) {
    $allowed = array_flip(['id', 'type', 'primary_price', 'stock', 'variants']);
    foreach ($batch ? $payload['data'] : [$payload] as $product) {
        same([], array_diff_key($product, $allowed), 'Retry contains no unrelated product fields');
        foreach ($product['variants'] ?? [] as $variant) {
            same([], array_diff_key($variant, array_flip(['id', 'properties', 'primary_price', 'stock'])), 'Retry contains no unrelated variation fields');
            if (!$batch) same(false, array_key_exists('id', $variant), 'Product PATCH does not send old variation ids');
            same(true, isset($variant['primary_price'], $variant['stock']), 'Variation price and stock are present');
        }
    }
}
function updateRetryProduct($id) {
    return syncBasalamContainer()->get(ProductOperations::class)->updateExistProduct($id);
}
function expectRetryFailure(callable $run) {
    try { $run(); } catch (JobException $exception) { $GLOBALS['assertions']++; return $exception; }
    throw new RuntimeException('Expected an update failure in ' . $GLOBALS['current']['name']);
}
function createRetryJob($productId) {
    global $wpdb;
    $payload = wp_json_encode(['product_id' => $productId, 'automatic' => false]);
    $GLOBALS['jobManager']->createJob('sync_basalam_update_single_product', 'processing', $payload);
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$GLOBALS['table']} WHERE payload = %s AND job_type = %s ORDER BY id DESC LIMIT 1", $payload, 'sync_basalam_update_single_product'));
}

$captureJob = static function () use (&$jobIds, $wpdb) { $jobIds[] = (int) $wpdb->insert_id; };
add_action('sync_basalam_job_created', $captureJob, -1000, 0);
$routeHttp = static function ($preempt, $args, $url) use (&$current, &$attempts) {
    $base = getenv('PRODUCT_RETRY_SIMULATION_URL');
    if (strpos($url, $base . '/') === 0) return false;
    if (strpos($url, 'http://127.0.0.1:9/') === 0
        && (!empty($current['headers']['x-test-unreachable']) || !empty($current['headers']['x-test-openapi-unreachable']))) {
        return false; // Exercise a real closed socket, not the external-request guard.
    }
    $host = wp_parse_url($url, PHP_URL_HOST);
    if (!in_array($host, ['openapi.basalam.com', 'core.basalam.com'], true) || ($args['method'] ?? 'GET') !== 'PATCH') {
        return new WP_Error('unexpected_test_http', 'Unexpected request blocked by local test: ' . $host);
    }
    $attempts[] = ['url' => $url, 'payload' => json_decode($args['body'], true)];
    $query = wp_parse_url($url, PHP_URL_QUERY);
    $destination = $base . wp_parse_url($url, PHP_URL_PATH) . ($query ? '?' . $query : '');
    if (!empty($current['headers']['x-test-unreachable'])
        || (!empty($current['headers']['x-test-openapi-unreachable']) && $host === 'openapi.basalam.com')) {
        $destination = 'http://127.0.0.1:9' . wp_parse_url($url, PHP_URL_PATH);
    }
    $args['headers'] = array_merge($args['headers'], [
        'x-mock-slug' => 'basalam', 'x-test-case' => $current['key'],
        'x-test-statuses' => wp_json_encode($current['statuses']), 'x-test-variants' => '1',
        'x-test-origin-host' => $host,
    ], $current['headers']);
    $args['timeout'] = 1;
    return wp_remote_request($destination, $args);
};
add_filter('pre_http_request', $routeHttp, PHP_INT_MAX, 3);
add_filter('sync_basalam_product_payload', static function ($data, $product, $handler, $strategy) use (&$products) {
    if (!in_array($product->get_id(), $products, true) || $strategy !== 'update') return $data;
    // Static media/category fixture; real handlers still calculate stock,
    // prices, SKUs, attributes and variation identities from WooCommerce.
    return ['name' => $product->get_name(), 'description' => 'Fixture forbidden description',
        'photo' => 200, 'photos' => [], 'weight' => 100, 'package_weight' => 50,
        'preparation_days' => 1, 'unit_type' => 6304, 'unit_quantity' => 1, 'is_wholesale' => false];
}, PHP_INT_MAX, 4);

try {
    beginRetryCase('setup', []);
    $admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
    wp_set_current_user((int) $admins[0]);
    $simple = new WC_Product_Simple();
    $simple->set_name('Retry integration simple ' . $runId);
    $simple->set_status('publish');
    $simple->set_sku('S-' . $runId);
    $simple->set_regular_price('25000');
    $simple->set_manage_stock(true);
    $simple->set_stock_quantity(0);
    $simple->set_weight('100');
    $simpleId = $simple->save();
    $products[] = $simpleId;

    $variable = new WC_Product_Variable();
    $variable->set_name('Retry integration variable ' . $runId);
    $variable->set_status('publish');
    $variable->set_sku('P-' . $runId);
    $variable->set_weight('100');
    $attribute = new WC_Product_Attribute();
    $attribute->set_name('Color');
    $attribute->set_options(['Red', 'Blue']);
    $attribute->set_variation(true);
    $variable->set_attributes([$attribute]);
    $variableId = $variable->save();
    $products[] = $variableId;
    foreach (['Red', 'Blue'] as $index => $color) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($variableId);
        $variation->set_status('publish');
        $variation->set_attributes(['color' => $color]);
        $variation->set_sku('V' . $index . '-' . $runId);
        $variation->set_regular_price((string) (25000 + $index * 10000));
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($index * 3);
        $products[] = $variation->save();
    }
    WC_Product_Variable::sync($variableId);
    wc_delete_product_transients($variableId);
    $parents = [$simpleId, $variableId];
    update_post_meta($simpleId, ProductMetaKey::basalamProductId(), 910001);
    update_post_meta($variableId, ProductMetaKey::basalamProductId(), 910002);

    foreach (['all', 'price_stock', 'custom'] as $mode) {
        foreach (['simple' => $simpleId, 'variable' => $variableId] as $type => $id) {
            foreach ([400, 422] as $status) {
                beginRetryCase("{$mode}-{$type}-{$status}", [$status, 200], ['sync_product_fields' => $mode, 'sync_product_field_name' => 1]);
                same(true, updateRetryProduct($id)['success'], 'Fallback succeeds');
                wp_cache_delete($id, 'post_meta');
                same('synced', get_post_meta($id, ProductMetaKey::basalamProductSyncStatus(), true), 'Successful fallback is persisted');
                $requests = finishRetryCase(2, $mode);
                priceStockOnly($requests[1]['body']);
                if ($type === 'simple') {
                    same(0, $requests[1]['body']['stock'], 'Zero stock is preserved');
                    same(25000, $requests[1]['body']['primary_price'], 'Real WooCommerce price is used');
                } else {
                    same([0, 3], array_column($requests[1]['body']['variants'], 'stock'), 'All variation stock values survive');
                    foreach (wc_get_product($id)->get_children() as $index => $variationId) {
                        wp_cache_delete($variationId, 'post_meta');
                        same(9100020 + $index + 1, (int) get_post_meta($variationId, 'sync_basalam_variation_id', true), 'Returned variation id is persisted');
                    }
                }
            }
        }
    }

    foreach (['html', 'malformed'] as $body) {
        beginRetryCase('body-' . $body, [422, 200], [], ['x-test-error-body' => $body]);
        same(true, updateRetryProduct($simpleId)['success'], 'Non-JSON rejection also retries');
        priceStockOnly(finishRetryCase(2)[1]['body']);
    }
    beginRetryCase('duplicate-sku-precedence', [422, 200], [], ['x-test-error-field' => 'sku']);
    same(true, updateRetryProduct($simpleId)['success'], 'Duplicate SKU uses immediate price/stock fallback');
    priceStockOnly(finishRetryCase(2)[1]['body']);

    foreach ([400, 422] as $first) {
        foreach ([400, 422] as $second) {
            beginRetryCase("rejected-{$first}-{$second}", [$first, $second]);
            same($second, expectRetryFailure(fn() => updateRetryProduct($simpleId))->getCode(), 'Second error is propagated');
            same('no', get_post_meta($simpleId, ProductMetaKey::basalamProductSyncStatus(), true), 'Failed update is not marked synced');
            priceStockOnly(finishRetryCase(2)[1]['body']);
        }
    }
    foreach ([401, 403, 404, 408, 429, 500, 502, 503, 504] as $status) {
        beginRetryCase('unrelated-' . $status, [$status]);
        expectRetryFailure(fn() => updateRetryProduct($simpleId));
        same('no', get_post_meta($simpleId, ProductMetaKey::basalamProductSyncStatus(), true), 'Unrelated failure is not marked synced');
        finishRetryCase(1);
    }

    beginRetryCase('custom-variation-and-parent', [422, 200, 400, 200, 422, 200], [
        'sync_product_fields' => 'custom', 'sync_product_field_name' => 1, 'sync_product_field_sku' => 1,
        'sync_product_field_variant_price' => 1, 'sync_product_field_variant_stock' => 1,
    ]);
    same(true, updateRetryProduct($variableId)['success'], 'Custom variation and parent fallbacks succeed');
    $requests = finishRetryCase(6, 'custom');
    foreach ([1, 3, 5] as $position) priceStockOnly($requests[$position]['body']);
    same(true, strpos($requests[0]['path'], '/variations/') !== false, 'Dedicated variation endpoint ran');
    same(true, isset($requests[5]['body']['variants']), 'Parent fallback rebuilds the complete variants');

    beginRetryCase('stale-variation-then-rejection', [404, 422, 200], [
        'sync_product_fields' => 'custom', 'sync_product_field_name' => 1,
        'sync_product_field_variant_price' => 1, 'sync_product_field_variant_stock' => 1,
    ]);
    same(true, updateRetryProduct($variableId)['success'], 'Stale mapping and product rejection recover together');
    priceStockOnly(finishRetryCase(3, 'custom')[2]['body']);

    beginRetryCase('success-no-retry', [200]);
    same(true, updateRetryProduct($simpleId)['success'], 'Ordinary update succeeds');
    finishRetryCase(1);

    foreach (['simple' => $simpleId, 'variable' => $variableId] as $type => $id) {
        foreach ([400, 422] as $status) {
            beginRetryCase("sku-follow-up-{$type}-{$status}", [200, $status, 200], [], [
                'x-test-omit-sku' => '1', 'x-test-variant-id-step' => '1000',
            ]);
            same(true, updateRetryProduct($id)['success'], 'SKU follow-up rejection also recovers');
            priceStockOnly(finishRetryCase(3)[2]['body']);
            if ($type === 'variable') {
                foreach (wc_get_product($id)->get_children() as $index => $variationId) {
                    wp_cache_delete($variationId, 'post_meta');
                    same(9103020 + $index + 1, (int) get_post_meta($variationId, 'sync_basalam_variation_id', true), 'Latest SKU fallback variation id is persisted');
                }
            }
        }
    }
    beginRetryCase('sku-follow-up-second-rejection', [200, 422, 422], [], ['x-test-omit-sku' => '1']);
    same(422, expectRetryFailure(fn() => updateRetryProduct($simpleId))->getCode(), 'Failed SKU fallback is propagated');
    same('no', get_post_meta($simpleId, ProductMetaKey::basalamProductSyncStatus(), true), 'Failed SKU fallback is not marked synced');
    priceStockOnly(finishRetryCase(3)[2]['body']);

    beginRetryCase('status-operation-no-fallback', [422]);
    expectRetryFailure(fn() => syncBasalamContainer()->get(UpdateSingleProductService::class)->updateProductStatus($simpleId, 2977));
    $requests = finishRetryCase(1);
    same(['status' => 2977], $requests[0]['body'], 'Status-only operations keep their purpose');

    beginRetryCase('transport-timeout', [200], [], ['x-test-delay' => '1500']);
    same(true, expectRetryFailure(fn() => updateRetryProduct($simpleId))->shouldRetry(), 'Timeout retains queue retry behavior');
    finishRetryCase(1);

    beginRetryCase('transport-unreachable', [], [], ['x-test-unreachable' => '1']);
    same(true, expectRetryFailure(fn() => updateRetryProduct($simpleId))->shouldRetry(), 'Connection failure remains retryable');
    same(2, count($attempts), 'Connection failure tries OpenAPI and Core once each');
    foreach ($attempts as $attempt) same(true, isset($attempt['payload']['name']), 'Connection retries preserve the full payload');
    finishRetryCase(0);

    beginRetryCase('connection-then-validation', [422, 200], [], ['x-test-openapi-unreachable' => '1']);
    same(true, updateRetryProduct($simpleId)['success'], 'Connection and validation fallbacks compose');
    same(4, count($attempts), 'Each product update makes at most one Core connection fallback');
    $requests = finishRetryCase(2);
    same('core.basalam.com', $requests[1]['origin'], 'Price/stock retry reaches Core');
    priceStockOnly($requests[1]['body']);

    beginRetryCase('custom-price-adjustment', [422, 200], [
        'sync_product_fields' => 'custom', 'sync_product_field_name' => 1, 'price_change_value' => '10',
    ]);
    update_option('woocommerce_currency', 'IRT');
    same(true, updateRetryProduct($simpleId)['success'], 'Custom fallback honors price settings');
    $requests = finishRetryCase(2, 'custom');
    same(275000, $requests[1]['body']['primary_price'], 'Toman conversion and ten percent adjustment are applied');
    same(0, $requests[1]['body']['stock'], 'Zero stock survives price recalculation');

    beginRetryCase('already-price-stock', [422]);
    expectRetryFailure(fn() => syncBasalamContainer()->get(UpdateSingleProductService::class)->updateProductInBasalam(['primary_price' => 25000, 'stock' => 0], $simpleId));
    finishRetryCase(1);

    beginRetryCase('variable-second-failure', [422, 400]);
    expectRetryFailure(fn() => updateRetryProduct($variableId));
    same('no', get_post_meta($variableId, ProductMetaKey::basalamProductSyncStatus(), true), 'Variable failure is not marked synced');
    priceStockOnly(finishRetryCase(2)[1]['body']);

    beginRetryCase('duplicate-connection', []);
    $duplicate = new WC_Product_Simple();
    $duplicate->set_name('Retry connection fixture ' . $runId);
    $duplicate->set_status('publish');
    $duplicateId = $duplicate->save();
    $products[] = $duplicateId;
    update_post_meta($duplicateId, ProductMetaKey::basalamProductId(), 910001);
    same(false, updateRetryProduct($simpleId)['success'], 'Ambiguous product connection is rejected');
    finishRetryCase(0);
    delete_post_meta($duplicateId, ProductMetaKey::basalamProductId());

    foreach (['success' => [422, 200], 'permanent' => [422, 400], 'temporary' => [429]] as $label => $statuses) {
        beginRetryCase('queued-' . $label, $statuses);
        $job = createRetryJob($simpleId);
        $result = syncBasalamContainer()->get(JobExecutor::class)->execute($job->job_type, $job);
        $row = $jobManager->getJob(['id' => $job->id]);
        if ($label === 'success') {
            same(true, $result, 'Queue completes after fallback');
            same(null, $row, 'Successful queue item is removed');
            same('synced', get_post_meta($simpleId, ProductMetaKey::basalamProductSyncStatus(), true), 'Queue sync metadata persists');
        } elseif ($label === 'permanent') {
            same(false, $result, 'Rejected fallback fails the queue item');
            same('failed', $row->status, 'Queue failure is persisted');
        } else {
            same(true, $result, 'Temporary failure is rescheduled');
            $replacement = $jobManager->getJob(['job_type' => $job->job_type, 'payload' => $job->payload, 'status' => 'pending']);
            same(1, (int) $replacement->attempts, 'Queue backoff is preserved');
            same(true, (int) $replacement->retry_after > time(), 'Queue retry is delayed');
            $jobIds[] = (int) $replacement->id;
        }
        finishRetryCase($label === 'temporary' ? 1 : 2);
    }

    class RetryAjaxExit extends Error {}
    add_filter('wp_doing_ajax', '__return_true');
    add_filter('wp_die_ajax_handler', static function () { return static function () { throw new RetryAjaxExit(); }; });
    foreach (['authorized', 'bad-nonce'] as $label) {
        beginRetryCase('ajax-' . $label, [422, 200]);
        $_POST = ['action' => 'update_product_in_basalam', 'product_id' => $simpleId,
            '_wpnonce' => $label === 'authorized' ? wp_create_nonce('update_product_in_basalam_nonce') : 'invalid'];
        ob_start();
        try { do_action('wp_ajax_update_product_in_basalam'); } catch (RetryAjaxExit $exit) {}
        $body = json_decode(ob_get_clean(), true);
        same($label === 'authorized', $body['success'] ?? null, 'Registered AJAX action response');
        finishRetryCase($label === 'authorized' ? 2 : 0);
    }

    foreach ([400, 422] as $status) {
        beginRetryCase('bulk-' . $status, [$status, 202], ['sync_product_fields' => 'price_stock']);
        $result = syncBasalamContainer()->get(BulkUpdateProductsJob::class)->execute(['last_updatable_product_id' => min($parents) - 1]);
        same(true, $result->isSuccessful(), 'Real database batch succeeds');
        $requests = finishRetryCase(2, 'price_stock');
        same(2, count($requests[1]['body']['data']), 'Batch includes both fixture parents');
        priceStockOnly($requests[1]['body'], true);
    }

    beginRetryCase('bulk-second-failure', [422, 400], ['sync_product_fields' => 'price_stock']);
    expectRetryFailure(fn() => syncBasalamContainer()->get(BulkUpdateProductsJob::class)->execute(['last_updatable_product_id' => min($parents) - 1]));
    priceStockOnly(finishRetryCase(2, 'price_stock')[1]['body'], true);

    $report = ['store_scenarios' => count($cases),
        'http_requests' => array_sum(array_column($cases, 'requests')), 'cases' => $cases,
        'php' => PHP_VERSION, 'wordpress' => get_bloginfo('version'), 'woocommerce' => WC_VERSION];
} catch (Throwable $exception) {
    fwrite(STDERR, 'Scenario ' . ($current['name'] ?? 'setup') . ': ' . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    throw $exception;
} finally {
    foreach (array_unique($jobIds) as $id) $jobManager->deleteJob(['id' => $id]);
    foreach (array_reverse($products) as $id) {
        $product = wc_get_product($id);
        if ($product) $product->delete(true);
    }
    foreach ($options as $key => $value) {
        if ($value === null) delete_option($key); else update_option($key, $value);
        same($value, get_option($key, null), 'Original option restored: ' . $key);
    }
    refreshRetrySettings();
    $_POST = $originalPost;
    wp_set_current_user($originalUser);
    remove_filter('pre_http_request', $routeHttp, PHP_INT_MAX);
    remove_action('sync_basalam_job_created', $captureJob, -1000);
    same($originalJobs, $wpdb->get_results("SELECT * FROM {$table} ORDER BY id", ARRAY_A), 'Existing queue rows are unchanged');
    foreach ($products as $id) same(null, get_post($id), 'Fixture product was removed');
    $lock->releaseGlobalJobsLock();
}
$report['assertions'] = $assertions;
$report['cleanup'] = 'settings, fixture products and queue verified';
echo wp_json_encode($report) . "\n";
