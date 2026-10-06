<?php

// Run in the local WordPress stack:
// docker exec -w /var/www/html/wp-content/plugins/sync-basalam \
//   basalam-wordpress-local-wordpress-1 php tests/integration/product-sync-operations.php
defined('DISABLE_WP_CRON') || define('DISABLE_WP_CRON', true);
require getenv('WORDPRESS_BOOTSTRAP') ?: dirname(__DIR__, 5) . '/wp-load.php';

use SyncBasalam\Admin\Components\SettingPageComponents;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsContainer;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Admin\Settings\SettingsPageHandler;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\JobManager;
use SyncBasalam\Jobs\JobExecutor;
use SyncBasalam\Jobs\LockManager;
use SyncBasalam\JobsRunner;
use SyncBasalam\Utilities\ProductMetaKey;

if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Run these integration tests only in a local WordPress environment.');
}

$lock = new LockManager();
if (!$lock->acquireGlobalJobsLock()) throw new RuntimeException('The local product worker is busy. Retry when it is idle.');

$originalSettings = get_option('sync_basalam_settings');
$originalPost = $_POST;
$products = [];
$attachmentId = 0;
$requests = [];
$assertions = 0;
$scenarios = [];
$mockProductId = 900000000;
$mockVariantId = 800000000;
$jobManager = new JobManager();
$jobTable = $wpdb->prefix . 'sync_basalam_job_manager';
$runner = syncBasalamContainer()->get(JobsRunner::class);
remove_action('shutdown', [$runner, 'maybeDispatchAsyncRequest'], 2);
remove_action('sync_basalam_job_created', [$runner, 'maybeDispatchAsyncRequest'], 10);

$assertSame = static function ($expected, $actual, string $scenario) use (&$assertions): void {
    if ($expected !== $actual) {
        throw new RuntimeException($scenario . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    $assertions++;
};
$refreshSettings = static function (): void {
    $property = new ReflectionProperty(SettingsContainer::class, 'settings');
    $property->setAccessible(true);
    $property->setValue(syncBasalamSettings(), null);
};
$configure = static function (bool $create, bool $update) use ($refreshSettings): void {
    SettingsManager::updateSettings([
        SettingsConfig::SYNC_STATUS_PRODUCT_CREATE => $create ? '1' : '0',
        SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE => $update ? '1' : '0',
    ]);
    $refreshSettings();
};
$jobsFor = static function (int $productId) use ($wpdb, $jobTable): array {
    $jobs = $wpdb->get_results("SELECT * FROM {$jobTable} ORDER BY id");
    return array_values(array_filter($jobs, static function ($job) use ($productId): bool {
        $payload = json_decode($job->payload, true);
        return is_array($payload) && (int) ($payload['product_id'] ?? 0) === $productId;
    }));
};
$addVariableVariation = static function ($product, string $type) use (&$products): void {
    if ($type !== WC_Product_Variable::class) return;

    $variation = new WC_Product_Variation();
    $variation->set_parent_id($product->get_id());
    $variation->set_status('publish');
    $variation->set_attributes([sanitize_title('سایز') => 'S']);
    $variation->set_regular_price('25000');
    $variation->set_manage_stock(true);
    $variation->set_stock_quantity(3);
    $variation->set_stock_status('instock');
    $products[] = $variation->save();

    WC_Product_Variable::sync($product->get_id());
    wc_delete_product_transients($product->get_id());
};

// Keep the real product operations and HTTP adapters; only the remote responses
// and uploaded media/category fixture values are replaced.
$mockHttp = static function ($preempt, $args, $url) use (&$requests, &$mockProductId, &$mockVariantId) {
    $method = $args['method'] ?? 'GET';
    if ($method === 'POST' && preg_match('~/v1/vendors/987654321/products$~', $url)) {
        $id = ++$mockProductId;
        $status = 201;
    } elseif ($method === 'PATCH' && preg_match('~/v1/products/(9[0-9]{8})$~', $url, $matches)) {
        $id = (int) $matches[1];
        $status = 200;
    } else {
        return new WP_Error('unexpected_integration_http_request', 'Unexpected HTTP request during integration test: ' . $method . ' ' . $url);
    }
    $payload = json_decode($args['body'], true);
    $requests[] = ['method' => $method, 'url' => $url, 'payload' => $payload, 'id' => $id];
    $responsePayload = array_merge($payload, ['id' => $id]);
    if (isset($responsePayload['variants']) && is_array($responsePayload['variants'])) {
        $responsePayload['variants'] = array_map(static function (array $variant) use (&$mockVariantId): array {
            $variant['id'] = ++$mockVariantId;
            $variant['properties'] = array_map(static function (array $property): array {
                return [
                    'property' => ['title' => (string) ($property['property'] ?? '')],
                    'value' => ['title' => (string) ($property['value'] ?? '')],
                ];
            }, $variant['properties'] ?? []);
            return $variant;
        }, $responsePayload['variants']);
    }
    return [
        'headers' => [],
        'body' => wp_json_encode($responsePayload),
        'response' => ['code' => $status, 'message' => 'OK'],
        'cookies' => [],
    ];
};
$fixturePayload = static function ($data, $product) use (&$products) {
    if (!in_array($product->get_id(), $products, true)) return $data;
    return [
        'name' => $product->get_name(), 'sku' => null, 'description' => 'Product sync integration fixture',
        'category_id' => 100, 'photo' => 200, 'photos' => [], 'video' => null,
        'weight' => 100, 'package_weight' => 50, 'status' => 2976, 'preparation_days' => 1,
        'unit_type' => 6304, 'unit_quantity' => 1, 'is_wholesale' => false,
        'product_attribute' => [], 'primary_price' => 250000, 'stock' => 3,
    ];
};
add_filter('pre_http_request', $mockHttp, PHP_INT_MAX, 3);
add_filter('sync_basalam_product_payload', $fixturePayload, PHP_INT_MAX, 4);

try {
    update_option('sync_basalam_settings', array_merge(SettingsConfig::getDefaultSettings(), [
        SettingsConfig::VENDOR_ID => 987654321,
        SettingsConfig::TOKEN => 'local-integration-fixture',
        SettingsConfig::AUTO_FULL_UPDATE_INTERVAL => 0,
    ]));
    $refreshSettings();
    $attachmentId = wp_insert_attachment([
        'post_title' => 'Product sync integration image',
        'post_mime_type' => 'image/png',
        'post_status' => 'inherit',
    ]);
    if (is_wp_error($attachmentId)) throw new RuntimeException($attachmentId->get_error_message());

    foreach ([[false, false], [true, false], [false, true], [true, true]] as [$create, $update]) {
        foreach ([WC_Product_Simple::class, WC_Product_Variable::class] as $type) {
            foreach (['new', 'unconnected_update', 'connected_update'] as $scenario) {
                $configure(false, false);
                $product = new $type();
                $product->set_name('محصول تست همگام‌سازی');
                $product->set_status('publish');
                $product->set_regular_price('25000');
                $product->set_image_id($attachmentId);
                $product->set_weight('100');

                if ($type === WC_Product_Variable::class) {
                    $attribute = new WC_Product_Attribute();
                    $attribute->set_name('سایز');
                    $attribute->set_options(['S']);
                    $attribute->set_variation(true);
                    $product->set_attributes([$attribute]);
                }

                if ($scenario !== 'new') {
                    $products[] = $product->save();
                    $addVariableVariation($product, $type);
                    $assertSame([], $jobsFor($product->get_id()), 'fixture save while sync disabled');
                    if ($scenario === 'connected_update') {
                        update_post_meta($product->get_id(), ProductMetaKey::basalamProductId(), ++$mockProductId);
                    }
                }

                $configure($create, $update);
                $label = $scenario . ' / ' . $type . ' / create=' . (int) $create . ', update=' . (int) $update;
                $product->set_name('محصول تست همگام‌سازی ' . $label);
                $productId = $product->save(); // Actual WooCommerce hooks, including overlapping save hooks.
                $addVariableVariation($product, $type);
                if ($scenario === 'new') $products[] = $productId;
                $jobs = $jobsFor($productId);
                $enabled = $scenario === 'connected_update' ? $update : $create;
                $assertSame($enabled ? 1 : 0, count($jobs), $label . ' queued job count');

                $requestCount = count($requests);
                if ($enabled) {
                    $operation = $scenario === 'connected_update' ? 'update' : 'create';
                    $expectedType = 'sync_basalam_' . $operation . '_single_product';
                    $assertSame($expectedType, $jobs[0]->job_type, $label . ' job type');
                    $assertSame(true, json_decode($jobs[0]->payload, true)['automatic'], $label . ' automatic job marker');
                    $assertSame(true, syncBasalamContainer()->get(JobExecutor::class)->execute($jobs[0]->job_type, $jobs[0]), $label . ' job execution');
                    $assertSame($requestCount + 1, count($requests), $label . ' HTTP request count');
                    $request = end($requests);
                    $assertSame($operation === 'create' ? 'POST' : 'PATCH', $request['method'], $label . ' HTTP method');
                    $expectedUrl = $operation === 'create' ? sprintf(Endpoints::PRODUCT_CREATE, 987654321) : sprintf(Endpoints::PRODUCT_UPDATE, $request['id']);
                    $assertSame($expectedUrl, $request['url'], $label . ' HTTP endpoint');
                    $assertSame($product->get_name(), $request['payload']['name'], $label . ' persisted product name');
                    $assertSame($request['id'], (int) get_post_meta($productId, ProductMetaKey::basalamProductId(), true), $label . ' Basalam connection');
                    $assertSame('synced', get_post_meta($productId, ProductMetaKey::basalamProductSyncStatus(), true), $label . ' sync status');
                } else {
                    $assertSame($requestCount, count($requests), $label . ' disabled HTTP count');
                }
                $assertSame([], $jobsFor($productId), $label . ' empty fixture queue');
                $scenarios[] = $label;
            }
        }
    }

    // Disabling each operation after enqueueing must prevent its HTTP request.
    foreach (['create', 'update'] as $operation) {
        $configure(false, false);
        $product = new WC_Product_Simple();
        $product->set_name('محصول تست توقف صف');
        $product->set_status('publish');
        $product->set_regular_price('25000');
        $product->set_image_id($attachmentId);
        $products[] = $product->save();
        $id = $product->get_id();
        if ($operation === 'update') update_post_meta($id, ProductMetaKey::basalamProductId(), ++$mockProductId);
        $configure(true, true);
        $product->save();
        $jobs = $jobsFor($id);
        $assertSame(1, count($jobs), $operation . ' stop fixture queue');
        $configure($operation !== 'create', $operation !== 'update');
        $requestCount = count($requests);
        $assertSame(true, syncBasalamContainer()->get(JobExecutor::class)->execute($jobs[0]->job_type, $jobs[0]), $operation . ' disabled queue execution');
        $assertSame($requestCount, count($requests), $operation . ' disabled queue makes no HTTP request');
        $assertSame([], $jobsFor($id), $operation . ' disabled queue cleared');
        $scenarios[] = $operation . ' disabled after enqueue';
    }

    // Rendered unchecked checkboxes must submit zero and save independently.
    foreach ([[false, false], [true, false], [false, true], [true, true]] as [$create, $update]) {
        $configure($create, $update);
        ob_start();
        SettingPageComponents::renderProductSyncOperations();
        $html = ob_get_clean();
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $submitted = [];
        foreach ($dom->getElementsByTagName('input') as $input) {
            if ($input->getAttribute('type') === 'checkbox' && !$input->hasAttribute('checked')) continue;
            preg_match('/sync_basalam_settings\[([^\]]+)\]/', $input->getAttribute('name'), $matches);
            $submitted[$matches[1]] = $input->getAttribute('value');
        }
        $assertSame($create ? '1' : '0', $submitted[SettingsConfig::SYNC_STATUS_PRODUCT_CREATE], 'rendered create choice');
        $assertSame($update ? '1' : '0', $submitted[SettingsConfig::SYNC_STATUS_PRODUCT_UPDATE], 'rendered update choice');
        $configure(!$create, !$update);
        $_POST = ['sync_basalam_settings' => wp_slash($submitted)];
        $assertSame(true, SettingsPageHandler::saveSettings(), 'settings form saves');
        $refreshSettings();
        $assertSame($create, SettingsManager::isProductCreationSyncEnabled(), 'creation choice saved');
        $assertSame($update, SettingsManager::isProductUpdateSyncEnabled(), 'update choice saved');
        $scenarios[] = 'settings form / create=' . (int) $create . ', update=' . (int) $update;
    }

    echo wp_json_encode(['success' => true, 'scenarios' => count($scenarios), 'assertions' => $assertions, 'mocked_http_requests' => count($requests)], JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    $configure(false, false);
    foreach ($products as $id) {
        foreach ($jobsFor($id) as $job) $jobManager->deleteJob(['id' => $job->id]);
        wp_delete_post($id, true);
    }
    if ($attachmentId) wp_delete_attachment($attachmentId, true);
    update_option('sync_basalam_settings', $originalSettings);
    $refreshSettings();
    $_POST = $originalPost;
    remove_filter('pre_http_request', $mockHttp, PHP_INT_MAX);
    remove_filter('sync_basalam_product_payload', $fixturePayload, PHP_INT_MAX);
    $lock->releaseGlobalJobsLock();
}
