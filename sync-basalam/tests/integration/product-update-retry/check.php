<?php

// Real WordPress HTTP and plugin services; configuration, products and metadata
// stay in memory. No store database, credentials or live API are used.
define('ABSPATH', rtrim(getenv('WORDPRESS_PATH'), '/') . '/');
define('WPINC', 'wp-includes');
define('WP_DEBUG', false);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
require ABSPATH . WPINC . '/version.php';
require ABSPATH . WPINC . '/plugin.php';
require dirname(__DIR__, 3) . '/vendor/autoload.php';

function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array) $args); }
function get_bloginfo($key) { return $key === 'version' ? $GLOBALS['wp_version'] : 'https://store.test'; }
function get_status_header_desc($code) { return (string) $code; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function get_transient($key) { return false; }
function set_transient($key, $value, $ttl = 0) { return true; }
function get_post_type($id) { return 'product'; }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; return true; }
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? null; }
function get_woocommerce_currency() { return 'IRR'; }
function wp_get_environment_type() { return 'local'; }
function mbstring_binary_safe_encoding($reset = false) {}
function reset_mbstring_encoding() {}
function __($text) { return $text; }
function esc_html($text) { return $text; }
function get_site_url() { return 'https://store.test'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function current_time($type, $gmt = false) { return time(); }
function syncBasalamContainer() { return new class { public function get($class) { return new $class(); } }; }
function syncBasalamSettings() { return new class {
    public function getSettings($key = null) { return \SyncBasalam\Admin\Settings\SettingsManager::getSettings($key); }
}; }
class WC_Logger {}
function wc_get_logger() { return new class { public function log($level, $message, $context) {} }; }

foreach (['class-wp-error.php', 'class-wp-http.php', 'class-wp-http-encoding.php',
    'class-wp-http-proxy.php', 'class-wp-http-response.php', 'class-wp-http-requests-response.php',
    'class-wp-http-requests-hooks.php', 'class-wp-http-cookie.php', 'http.php'] as $file) {
    require ABSPATH . WPINC . '/' . $file;
}

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Product\ProductDataFactory;
use SyncBasalam\Jobs\Exceptions\JobException;
use SyncBasalam\Jobs\Types\BulkUpdateProductsJob;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Services\Products\UpdateProductVariationsService;
use SyncBasalam\Services\Products\UpdateSingleProductService;
use SyncBasalam\Utilities\ProductMetaKey;

$GLOBALS['wpdb'] = new class {
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public function prepare($sql, ...$args) { return $sql; }
    public function get_results($sql) { return []; }
    public function get_col($sql) { return [10]; }
};
$GLOBALS['options']['sync_basalam_settings'] = array_merge(SettingsConfig::getDefaultSettings(), [
    'vendor_id' => 1, 'token' => 'local-fixture-token', 'sync_product_fields' => 'all',
    'product_price_field' => 'original_price', 'price_change_value' => '0', 'round_price' => 'none',
]);

$api = new class extends ApiServiceManager {
    public $scenario;
    public $firstStatus;
    public $nextStatus = 200;
    public $returnErrors = false;
    public function patch($url, $data, $headers = []) {
        // Only the test adapter changes the destination. The production HTTP
        // client, serialization, status handler and retry code all run normally.
        $query = parse_url($url, PHP_URL_QUERY);
        $localUrl = getenv('SIMULATION_URL') . parse_url($url, PHP_URL_PATH) . ($query ? '?' . $query : '');
        try {
            return parent::patch($localUrl, $data, [
                'x-mock-slug' => 'basalam', 'x-test-case' => $this->scenario,
                'x-test-first-status' => $this->firstStatus, 'x-test-next-status' => $this->nextStatus,
            ]);
        } catch (JobException $exception) {
            if (!$this->returnErrors) throw $exception;
            return ['status_code' => $exception->getCode(), 'body' => $exception->getResponseData()];
        }
    }
};

$variantData = new class {
    public function getVariants($product) { return [[
        'id' => 321, 'primary_price' => 25000, 'stock' => 0, 'sku' => 'VARIANT-SKU',
        'properties' => [['property' => 'Color', 'value' => 'Red']],
    ]]; }
};
$service = new UpdateSingleProductService($api, new UpdateProductVariationsService($api), $variantData);
$assertions = 0;
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['assertions']++;
}
function product($variable = false) {
    return new class($variable) {
        private $variable;
        public function __construct($variable) { $this->variable = $variable; }
        public function is_type($type) { return $this->get_type() === $type; }
        public function get_type() { return $this->variable ? 'variable' : 'simple'; }
        public function get_id() { return 10; }
        public function get_regular_price() { return '25000'; }
        public function get_sku() { return 'BATCH-SKU'; }
        public function get_sale_price() { return ''; }
        public function get_stock_quantity() { return 0; }
        public function get_stock_status() { return 'instock'; }
        public function get_children() { return []; }
        public function get_attributes() { return []; }
    };
}
function configure($scenario, $status, $next = 200, $variable = false) {
    $GLOBALS['api']->scenario = $scenario;
    $GLOBALS['api']->firstStatus = $status;
    $GLOBALS['api']->nextStatus = $next;
    $GLOBALS['api']->returnErrors = false;
    $GLOBALS['products'][10] = product($variable);
    $GLOBALS['meta'][10] = [ProductMetaKey::basalamProductId() => 123];
}

$full = ['name' => 'Fixture product', 'description' => 'Fixture description', 'sku' => 'PRODUCT-SKU', 'primary_price' => 25000, 'stock' => 0];
foreach ([400, 422] as $status) {
    configure('simple-' . $status, $status);
    check($service->updateProductInBasalam($full, 10)['success'], 'Simple product fallback failed');
    check(get_post_meta(10, ProductMetaKey::basalamProductSyncStatus(), true) === 'synced', 'Successful fallback was not recorded');
    check(get_option('sync_basalam_settings')['sync_product_fields'] === 'all', 'Saved update mode changed');

    configure('variable-' . $status, $status, 200, true);
    $variablePayload = ['name' => 'Variable fixture', 'sku' => 'PARENT', 'variants' => $variantData->getVariants(null)];
    check($service->updateProductInBasalam($variablePayload, 10)['success'], 'Variable product fallback failed');
}

configure('custom-simple', 422);
$GLOBALS['options']['sync_basalam_settings']['sync_product_fields'] = 'custom';
$GLOBALS['options']['sync_basalam_settings']['sync_product_field_name'] = 1;
check($service->updateProductInBasalam(['name' => 'Custom name only'], 10)['success'], 'Custom mode did not rebuild missing price/stock');
check(get_option('sync_basalam_settings')['sync_product_fields'] === 'custom', 'Custom mode changed');

configure('custom-variable', 400, 200, true);
check($service->updateProductInBasalam(['name' => 'Custom variable name only'], 10)['success'], 'Custom mode did not rebuild missing variants');

foreach ([403, 404, 429, 503] as $status) {
    configure('other-' . $status, $status);
    try {
        $service->updateProductInBasalam($full, 10);
        throw new RuntimeException('Expected failure for ' . $status);
    } catch (JobException $exception) {
        check(true, 'Original failure preserved');
    }
    check(get_post_meta(10, ProductMetaKey::basalamProductSyncStatus(), true) !== 'synced', 'Failed update was recorded as synced');
}

configure('second-failure', 422, 400);
try {
    $service->updateProductInBasalam($full, 10);
    throw new RuntimeException('Expected second failure');
} catch (JobException $exception) {
    check($exception->getCode() === 400, 'Second failure status was lost');
}
check(get_post_meta(10, ProductMetaKey::basalamProductSyncStatus(), true) !== 'synced', 'Rejected fallback was recorded as synced');

configure('success', 200);
check($service->updateProductInBasalam($full, 10)['success'], 'Ordinary update failed');

configure('variation', 422);
check((new UpdateProductVariationsService($api))->updateVariations(123, [[
    'id' => 321, 'sku' => 'VARIANT-SKU', 'primary_price' => 25000, 'stock' => 0,
]], 10)['updated'] === 1, 'Variation endpoint fallback failed');

$jobs = new class {
    public function getJob($query) { return null; }
};
$batchJob = new BulkUpdateProductsJob($jobs, $api, new ProductDataFactory(), syncBasalamSettings());
foreach ([400, 422] as $status) {
    configure('batch-' . $status, $status, 202);
    check($batchJob->execute([])->isSuccessful(), 'Batch fallback failed');
}
configure('batch-second-failure', 422, 400);
try {
    $batchJob->execute([]);
    throw new RuntimeException('Expected batch fallback failure');
} catch (JobException $exception) {
    check($exception->getCode() === 400, 'Batch fallback error was lost');
}

// Some adapters return non-2xx arrays. Keep the same real network exchange and
// convert only the adapter's error delivery shape at its boundary.
foreach ([400, 422] as $status) {
    configure('returned-simple-' . $status, $status);
    $api->returnErrors = true;
    check($service->updateProductInBasalam($full, 10)['success'], 'Returned validation error did not retry');
}
configure('returned-simple-second-failure', 422, 400);
$api->returnErrors = true;
try {
    $service->updateProductInBasalam($full, 10);
    throw new RuntimeException('Returned second failure was reported as success');
} catch (JobException $exception) {
    check(get_post_meta(10, ProductMetaKey::basalamProductSyncStatus(), true) !== 'synced', 'Returned failure must not mark the product synced');
}
configure('variation-returned-second-failure', 422, 400);
$api->returnErrors = true;
try {
    (new UpdateProductVariationsService($api))->updateVariations(123, [[
        'id' => 321, 'sku' => 'VARIANT-SKU', 'primary_price' => 25000, 'stock' => 0,
    ]], 10);
    throw new RuntimeException('Returned variation error was reported as success');
} catch (JobException $exception) {
    check(true, 'Returned variation failure propagated');
}
foreach (['success' => 202, 'second-failure' => 400] as $label => $nextStatus) {
    configure('batch-returned-' . $label, 422, $nextStatus);
    $api->returnErrors = true;
    try {
        $result = $batchJob->execute([]);
        check($label === 'success' && $result->isSuccessful(), 'Returned batch error was reported as success');
    } catch (JobException $exception) {
        check($label === 'second-failure', 'Returned batch success should not throw');
    }
}

echo json_encode(['assertions' => $assertions], JSON_THROW_ON_ERROR) . "\n";
