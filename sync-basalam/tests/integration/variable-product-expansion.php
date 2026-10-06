<?php

// docker exec -w /var/www/html/wp-content/plugins/sync-basalam \
//   basalam-wordpress-local-wordpress-1 php tests/integration/variable-product-expansion.php [--live]
// --live creates one zero-stock product in the configured test vendor and archives it.
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require getenv('WORDPRESS_BOOTSTRAP') ?: dirname(__DIR__, 5) . '/wp-load.php';

use SyncBasalam\Admin\Product\Data\Services\VariantService;
use SyncBasalam\Admin\Product\Data\Validators\CreateProductDataValidator;
use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsContainer;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Services\Orders\OrderManager;
use SyncBasalam\Services\Products\Discount\DiscountManager;
use SyncBasalam\Services\Products\Discount\DiscountTaskProcessor;
use SyncBasalam\Services\Products\Discount\VariableProductDiscount;
use SyncBasalam\Services\Products\UpdateProductVariationsService;
use SyncBasalam\Services\Products\UpdateSingleProductService;
use SyncBasalam\Utilities\ProductMetaKey;

if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Run this integration test only in a local WordPress environment.');
}

$originalSettings = get_option('sync_basalam_settings');
$products = [];
$globalAttributes = [];
$assertions = 0;
$scenarios = [];
$liveResult = null;
$liveProductId = null;
$liveRemoteId = null;
$assertSame = static function ($expected, $actual, string $message) use (&$assertions) {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    $assertions++;
};
$configure = static function (array $settings) use ($originalSettings) {
    update_option('sync_basalam_settings', array_merge(SettingsConfig::getDefaultSettings(), (array) $originalSettings, [
        SettingsConfig::PRODUCT_PRICE_FIELD => 'original_price',
        SettingsConfig::ROUND_PRICE => 'none',
        SettingsConfig::PRICE_CHANGE_VALUE => '',
        SettingsConfig::SAFE_STOCK => 0,
        SettingsConfig::VARIABLE_PRODUCT_STOCK_SOURCE => 'variation',
    ], $settings));
    $property = new ReflectionProperty(SettingsContainer::class, 'settings');
    $property->setAccessible(true);
    $property->setValue(syncBasalamSettings(), null);
};
$fixture = static function (array $options, array $variations) use (&$products) {
    $product = new WC_Product_Variable();
    $product->set_name('تست ووسلام تنوع سایز و رنگ ' . wp_generate_password(8, false));
    $product->set_status('publish');
    $attributes = [];
    foreach ($options as $name => $values) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_name($name);
        if (taxonomy_exists($name)) $attribute->set_id(wc_attribute_taxonomy_id_by_name($name));
        $attribute->set_options($values);
        $attribute->set_variation(true);
        $attributes[] = $attribute;
    }
    $product->set_attributes($attributes);
    $products[] = $product->save();
    foreach ($variations as $position => $data) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product->get_id());
        $variation->set_status($data['status'] ?? 'publish');
        $variation->set_menu_order($position);
        $selectedAttributes = [];
        foreach ($data['attributes'] as $name => $value) $selectedAttributes[sanitize_title($name)] = $value;
        $variation->set_attributes($selectedAttributes);
        $variation->set_regular_price($data['price'] ?? '100000');
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($data['stock'] ?? 4);
        $variation->set_stock_status(($data['stock'] ?? 4) > 0 ? 'instock' : 'outofstock');
        $variation->save();
    }
    WC_Product_Variable::sync($product->get_id());
    wc_delete_product_transients($product->get_id());
    return wc_get_product($product->get_id());
};
$checkVariants = static function (array $variants, int $count) use ($assertSame) {
    $assertSame($count, count($variants), 'variant count');
    foreach ($variants as $variant) {
        foreach ($variant['properties'] as $property) {
            $assertSame(true, trim($property['value']) !== '', 'no empty property values');
        }
    }
};

try {
    // Fixture saves must not schedule synchronization of unrelated products.
    foreach (['save_post', 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation'] as $hook) {
        remove_all_actions($hook);
    }
    $configure([]);
    $service = new VariantService();
    $product = $fixture(['سایز' => ['S', 'M', 'L']], [['attributes' => ['سایز' => '']]]);
    $variants = $service->getVariants($product);
    $checkVariants($variants, 3);
    $assertSame(['S', 'M', 'L'], array_map(static function ($v) { return $v['properties'][0]['value']; }, $variants), 'all sizes');
    $scenarios[] = 'one Any-size variation expands to three sizes';

    $product = $fixture(['سایز' => ['S', 'M', 'L'], 'رنگ' => ['قرمز', 'آبی']], [
        ['attributes' => ['سایز' => '', 'رنگ' => 'قرمز'], 'price' => '110000', 'stock' => 3],
        ['attributes' => ['سایز' => '', 'رنگ' => 'آبی'], 'price' => '120000', 'stock' => 0],
    ]);
    $variants = $service->getVariants($product);
    $checkVariants($variants, 6);
    $assertSame(1100000, $variants[0]['primary_price'], 'red variation price in rial');
    $assertSame(3, $variants[0]['stock'], 'red variation stock');
    $assertSame(1200000, $variants[3]['primary_price'], 'blue variation price in rial');
    $assertSame(0, $variants[3]['stock'], 'out-of-stock blue variations are retained');
    $scenarios[] = 'each color expands all sizes with its own price and stock';

    $product = $fixture(['سایز' => ['S', 'M', 'L'], 'رنگ' => ['قرمز', 'آبی']], [
        ['attributes' => ['سایز' => '', 'رنگ' => '']],
    ]);
    $variants = $service->getVariants($product);
    $checkVariants($variants, 6);
    $remote = [];
    foreach ($variants as $index => $variant) {
        $remote[] = ['id' => 80000000 + $index, 'properties' => array_reverse(array_map(static function ($property) {
            return ['property' => ['title' => $property['property']], 'value' => ['title' => $property['value']]];
        }, $variant['properties']))];
    }
    $service->syncExpandedVariationIds($product, array_reverse($remote));
    $mapped = $service->getVariants($product);
    $assertSame(array_column($remote, 'id'), array_column($mapped, 'id'), 'mapping ignores response order');
    $variationId = $product->get_children()[0];
    $assertSame(6, count(get_post_meta($variationId, 'sync_basalam_variation_id', false)), 'all remote ids are stored');
    foreach ($remote as $variant) {
        $assertSame($variationId, OrderManager::getWooProductVariableId($variant['id']), 'order lookup for each expanded id');
    }
    $scenarios[] = 'two Any attributes expand all six combinations and map every id';

    $apiRecorder = new class {
        public array $requests = [];
        public function patch($url, $data) {
            $this->requests[] = ['url' => $url, 'data' => $data];
            return ['status_code' => 200, 'body' => '{}'];
        }
        public function post($url, $data) {
            $this->requests[] = ['url' => $url, 'data' => $data];
            return ['status_code' => 202, 'body' => '{}'];
        }
        public function delete($url, $headers, $data) {
            $this->requests[] = ['url' => $url, 'data' => $data];
            return ['status_code' => 202, 'body' => '{}'];
        }
    };
    update_post_meta($product->get_id(), ProductMetaKey::basalamProductId(), 99999999);
    $configure([SettingsConfig::SYNC_PRODUCT_FIELDS => 'custom', SettingsConfig::SYNC_PRODUCT_FIELD_VARIANT_PRICE => 1, SettingsConfig::SYNC_PRODUCT_FIELD_VARIANT_STOCK => 1]);
    $updater = new UpdateSingleProductService($apiRecorder, new UpdateProductVariationsService($apiRecorder), $service);
    $updater->updateProductInBasalam(['id' => 99999999, 'type' => 'variable', 'variants' => $mapped], $product->get_id());
    $assertSame(6, count($apiRecorder->requests), 'custom updates visit all six remote ids');
    foreach ($remote as $index => $variant) {
        $assertSame(sprintf(Endpoints::PRODUCT_VARIATION_UPDATE, 99999999, $variant['id']), $apiRecorder->requests[$index]['url'], 'custom update routes each mapped id');
    }
    $configure([]);
    $apiRecorder->requests = [];
    $variation = wc_get_product($variationId);
    $variation->set_sale_price('80000');
    $variation->save();
    $discount = new VariableProductDiscount(new DiscountManager($apiRecorder));
    $discount->apply($product);
    $discount->remove($product);
    $metaIds = array_map('strval', array_column($remote, 'id'));
    $assertSame($metaIds, $apiRecorder->requests[0]['data']['product_filter']['variation_ids'], 'discount applies to every expanded id');
    $assertSame($metaIds, $apiRecorder->requests[1]['data']['product_filter']['variation_ids'], 'discount removal visits every expanded id');
    $taskModel = new class {
        public array $tasks = [];
        public function create($data) { $this->tasks[] = $data; return count($this->tasks); }
    };
    $jobManager = new class {
        public function getCountJobs($criteria) { return 1; }
    };
    $processor = new DiscountTaskProcessor(new DiscountManager($apiRecorder), $taskModel, $jobManager);
    $processor->addDiscountTasks([['variation_id' => $variationId, 'discount_percent' => 20]]);
    $assertSame($metaIds, array_column($taskModel->tasks, 'variation_id'), 'discount queue stores all expanded ids');
    $scenarios[] = 'custom updates and discounts visit every expanded remote id';

    $product = $fixture(['سایز' => ['S', 'M']], [
        ['attributes' => ['سایز' => 'S'], 'price' => '150000'],
        ['attributes' => ['سایز' => ''], 'price' => '100000'],
        ['attributes' => ['سایز' => 'M'], 'price' => '200000', 'status' => 'private'],
    ]);
    $variants = $service->getVariants($product);
    $checkVariants($variants, 2);
    $assertSame([1500000, 1000000], array_column($variants, 'primary_price'), 'first matching Woo variation wins');
    $scenarios[] = 'overlaps follow Woo order without duplicate or disabled variants';

    $slug = 'expansion_' . strtolower(wp_generate_password(8, false));
    $attributeId = wc_create_attribute(['name' => 'اندازه', 'slug' => $slug, 'type' => 'select']);
    if (is_wp_error($attributeId)) throw new RuntimeException($attributeId->get_error_message());
    $globalAttributes[] = $attributeId;
    $taxonomy = wc_attribute_taxonomy_name($slug);
    register_taxonomy($taxonomy, ['product'], ['label' => 'اندازه', 'public' => false]);
    $GLOBALS['wc_product_attributes'][$taxonomy] = wc_get_attribute_taxonomies()['id:' . $attributeId];
    $termIds = [];
    foreach (['کوچک', 'بزرگ'] as $title) {
        $term = wp_insert_term($title, $taxonomy);
        if (is_wp_error($term)) throw new RuntimeException($term->get_error_message());
        $termIds[] = $term['term_id'];
    }
    $product = $fixture([$taxonomy => $termIds], [['attributes' => [$taxonomy => '']]]);
    $variants = $service->getVariants($product);
    $checkVariants($variants, 2);
    $values = array_map(static function ($v) { return $v['properties'][0]['value']; }, $variants);
    sort($values);
    $expectedValues = ['کوچک', 'بزرگ'];
    sort($expectedValues);
    $assertSame($expectedValues, $values, 'taxonomy slugs resolve to Persian term names');
    $scenarios[] = 'global taxonomy options use readable Persian names';

    $product = $fixture(['شماره' => ['0', '1']], [['attributes' => ['شماره' => '']]]);
    $checkVariants($service->getVariants($product), 2);
    $product = $fixture(['سایز' => ['S']], [['attributes' => ['سایز' => 'S'], 'price' => '']]);
    $checkVariants($service->getVariants($product), 0);
    $validation = (new CreateProductDataValidator())->validate(['category_id' => 1, 'photo' => 1, 'variants' => []], $product->get_id());
    $assertSame(false, $validation['valid'], 'variable products cannot be created without valid variants');
    $scenarios[] = 'zero option is preserved and unpriced variations are skipped';

    $product = $fixture(['سایز' => ['S']], [['attributes' => ['سایز' => '']]]);
    $variationId = $product->get_children()[0];
    update_post_meta($variationId, 'sync_basalam_variation_id', 70000000);
    $assertSame(false, isset($service->getVariants($product)[0]['id']), 'legacy empty properties need a full remap even with one option');
    $remote = [['id' => 80000001, 'properties' => [['property' => 'سایز', 'value' => 'S']]]];
    $service->syncExpandedVariationIds($product, $remote);
    $variation = wc_get_product($variationId);
    $variation->set_attributes([sanitize_title('سایز') => 'S']);
    $variation->save();
    $remote[0]['id'] = 80000002;
    $service->syncExpandedVariationIds($product, $remote);
    $assertSame(80000002, $service->getVariants($product)[0]['id'], 'mapping refreshes after Any becomes a specific option');
    $scenarios[] = 'legacy wildcard ids and changing wildcard selections remap safely';

    if (in_array('--live', $argv, true)) {
        $api = syncBasalamContainer()->get(ApiServiceManager::class);
        $base = null;
        foreach (wc_get_products(['type' => 'simple', 'limit' => 20]) as $candidate) {
            if ($candidate->get_image_id() && get_post_meta($candidate->get_id(), ProductMetaKey::basalamProductId(), true)) {
                $base = $candidate;
                break;
            }
        }
        if (!$base) throw new RuntimeException('The live test needs an existing connected simple product with an image.');
        $baseRemoteId = get_post_meta($base->get_id(), ProductMetaKey::basalamProductId(), true);
        $response = $api->get(sprintf(Endpoints::PRODUCT_UPDATE, $baseRemoteId));
        $baseData = json_decode($response['body'], true);
        $product = $fixture(['سایز' => ['S', 'M', 'L'], 'رنگ' => ['قرمز', 'آبی']], [
            ['attributes' => ['سایز' => '', 'رنگ' => ''], 'stock' => 0],
        ]);
        $liveProductId = $product->get_id();
        $product->set_image_id($base->get_image_id());
        $product->set_weight('0.2');
        $product->save();
        $payloadFilter = static function ($data, $p, $handler, $mode) use ($liveProductId, $baseData) {
            if ($p->get_id() !== $liveProductId || $mode !== 'create') return $data;
            return array_merge((array) $data, [
                'category_id' => $baseData['category_id'] ?? $baseData['category']['id'],
                'photo' => $baseData['photo']['id'],
                'photos' => [],
                'weight' => 200,
                'package_weight' => 300,
                'preparation_days' => 3,
            ]);
        };
        add_filter('sync_basalam_product_payload', $payloadFilter, 100, 4);
        $operations = syncBasalamContainer()->get(ProductOperations::class);
        try {
            $result = $operations->createNewProduct($liveProductId, null);
        } finally {
            remove_filter('sync_basalam_product_payload', $payloadFilter, 100);
        }
        $liveRemoteId = $result['basalam_id'];
        $assertSame(true, $result['success'], 'live creation succeeds');
        $response = $api->get(sprintf(Endpoints::PRODUCT_UPDATE, $liveRemoteId));
        $created = json_decode($response['body'], true);
        $createdVariants = $created['variants'] ?? $created['variant'] ?? [];
        $assertSame(6, count($createdVariants), 'six variants persist in Basalam');
        $mapped = $service->getVariants(wc_get_product($liveProductId));
        $assertSame(6, count(array_column($mapped, 'id')), 'six live remote ids map back to Woo');
        foreach ($createdVariants as $variant) {
            $assertSame(0, $variant['stock'], 'live test stays out of stock');
            foreach ($variant['properties'] as $property) {
                $assertSame(true, trim($property['value']['title']) !== '', 'live property is populated');
            }
        }
        $liveResult = ['basalam_id' => $liveRemoteId, 'variant_count' => 6, 'variants' => $createdVariants];
        $scenarios[] = 'real product creation and readback in Basalam';
    }
} catch (Throwable $error) {
    fwrite(STDERR, wp_json_encode(['passed' => false, 'error' => $error->getMessage(), 'basalam_id' => $liveRemoteId], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    throw $error;
} finally {
    try {
        if ($liveRemoteId) {
            syncBasalamContainer()->get(ProductOperations::class)->archiveExistProduct($liveProductId);
            $response = syncBasalamContainer()->get(ApiServiceManager::class)->get(sprintf(Endpoints::PRODUCT_UPDATE, $liveRemoteId));
            $archived = json_decode($response['body'], true);
            $assertSame(3790, $archived['revision']['data']['status']['value'] ?? $archived['status']['value'], 'archive status is persisted');
            $liveResult['unpublished'] = true;
        }
    } finally {
        foreach ($products as $id) {
            $product = wc_get_product($id);
            if ($product) $product->delete(true);
        }
        foreach ($globalAttributes as $id) wc_delete_attribute($id);
        if ($originalSettings === false) delete_option('sync_basalam_settings');
        else update_option('sync_basalam_settings', $originalSettings);
    }
}

echo wp_json_encode(['passed' => true, 'assertions' => $assertions, 'scenarios' => $scenarios, 'live' => $liveResult], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
