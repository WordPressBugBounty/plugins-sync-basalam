<?php

// Run inside a local WordPress installation:
// docker exec -w /var/www/html/wp-content/plugins/sync-basalam \
//   basalam-wordpress-local-wordpress-1 php tests/integration/product-attribute-suffix.php
require getenv('WORDPRESS_BOOTSTRAP') ?: dirname(__DIR__, 5) . '/wp-load.php';

use SyncBasalam\Admin\Components\SettingPageComponents;
use SyncBasalam\Admin\Product\ProductDataFactory;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsContainer;
use SyncBasalam\Admin\Settings\SettingsPageHandler;

if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Run these integration tests only in a local WordPress environment.');
}

$originalSettings = get_option('sync_basalam_settings');
$originalPost = $_POST;
$products = [];
$globalAttributes = [];
$taxonomyAttributes = [];
$assertions = 0;
$scenarios = [];
$assertSame = static function ($expected, $actual, string $scenario) use (&$assertions) {
    if ($expected !== $actual) {
        throw new RuntimeException($scenario . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    $assertions++;
};
$refreshSettings = static function () {
    // Each scenario represents a new request; clear the request's settings cache.
    $property = new ReflectionProperty(SettingsContainer::class, 'settings');
    $property->setAccessible(true);
    $property->setValue(syncBasalamSettings(), null);
};
$configure = static function ($names, string $enabled = 'yes', string $prefix = '', string $suffix = '') use ($originalSettings, $refreshSettings) {
    update_option('sync_basalam_settings', array_merge(SettingsConfig::getDefaultSettings(), (array) $originalSettings, [
        SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_ENABLED => $enabled,
        SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_PRIORITY => $names,
        SettingsConfig::PRODUCT_PREFIX_TITLE => $prefix,
        SettingsConfig::PRODUCT_SUFFIX_TITLE => $suffix,
    ]));
    $refreshSettings();
};
$customAttributes = static function (array $values): array {
    $attributes = [];
    foreach ($values as $name => $options) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_name((string) $name);
        $attribute->set_options($options);
        $attributes[] = $attribute;
    }
    return $attributes;
};
$nameFor = static function (WC_Product $product): string {
    return (new ProductDataFactory())->createHandler($product)->getName($product);
};

try {
    // Fixture saves must not schedule remote product synchronization.
    remove_all_actions('save_post');
    remove_all_actions('woocommerce_new_product');
    remove_all_actions('woocommerce_update_product');

    $book = ['ناشر' => ['نشر چشمه'], 'نویسنده' => ['صادق هدایت']];
    $cases = [
        ['English comma', 'ناشر, نویسنده', $book, 'کتاب (نشر چشمه, صادق هدایت)'],
        ['Persian comma', 'ناشر، نویسنده', $book, 'کتاب (نشر چشمه, صادق هدایت)'],
        ['configured order', 'نویسنده, ناشر', $book, 'کتاب (صادق هدایت, نشر چشمه)'],
        ['single-attribute compatibility', 'ناشر', $book, 'کتاب (نشر چشمه)'],
        ['whitespace', " \tناشر , نویسنده ", ['ناشر' => [' نشر چشمه '], 'نویسنده' => [' صادق هدایت ']], 'کتاب (نشر چشمه, صادق هدایت)'],
        ['duplicates and empty entries', ', ناشر، ناشر, نویسنده,', $book, 'کتاب (نشر چشمه, صادق هدایت)'],
        ['missing first attribute', 'مترجم, ناشر', $book, 'کتاب (نشر چشمه)'],
        ['missing second attribute', 'ناشر, مترجم', $book, 'کتاب (نشر چشمه)'],
        ['no matching attributes', 'مترجم, زبان', $book, 'کتاب'],
        ['empty attribute', 'ناشر, نویسنده', ['ناشر' => [], 'نویسنده' => ['صادق هدایت']], 'کتاب (صادق هدایت)'],
        ['blank value', 'ناشر, نویسنده', ['ناشر' => [' '], 'نویسنده' => ['صادق هدایت']], 'کتاب (صادق هدایت)'],
        ['multiple values', 'ناشر, نویسنده', ['ناشر' => ['نشر چشمه'], 'نویسنده' => ['صادق هدایت', 'بزرگ علوی']], 'کتاب (نشر چشمه, صادق هدایت, بزرگ علوی)'],
        ['zero value', 'شماره', ['شماره' => ['0']], 'کتاب (0)'],
        ['empty setting', '', $book, 'کتاب'],
        ['unset setting', null, $book, 'کتاب'],
        ['only separators', ', ، ,', $book, 'کتاب'],
        ['no product attributes', 'ناشر, نویسنده', [], 'کتاب'],
    ];

    foreach ($cases as [$scenario, $names, $attributes, $expected]) {
        $configure($names);
        foreach ([WC_Product_Simple::class, WC_Product_Variable::class] as $type) {
            $product = new $type();
            $product->set_name('کتاب');
            $product->set_attributes($customAttributes($attributes));
            $assertSame($expected, $nameFor($product), $scenario . ' / ' . $type);
        }
        $scenarios[] = $scenario;
    }

    $configure('ناشر, نویسنده', 'yes', 'فروشگاه', 'اصل');
    $product = new WC_Product_Simple();
    $product->set_name('کتاب');
    $product->set_attributes($customAttributes($book));
    $assertSame('فروشگاه کتاب اصل (نشر چشمه, صادق هدایت)', $nameFor($product), 'static prefix and suffix');
    $configure('ناشر, نویسنده', 'no', 'فروشگاه', 'اصل');
    $assertSame('فروشگاه کتاب اصل', $nameFor($product), 'disabled suffix');
    $configure('ناشر, نویسنده');
    $product->set_name(str_repeat('ک', 115));
    $assertSame(str_repeat('ک', 115) . ' (نشر', $nameFor($product), 'Unicode title truncation');
    $assertSame(120, mb_strlen($nameFor($product)), 'title length');
    $scenarios[] = 'prefix, suffix, disabled feature and Unicode title limit';

    // Exercise real WooCommerce taxonomy labels, terms and persisted products.
    foreach (['ناشر', 'نویسنده'] as $label) {
        $slug = 'suffix_e2e_' . strtolower(wp_generate_password(8, false));
        $id = wc_create_attribute(['name' => $label, 'slug' => $slug, 'type' => 'select']);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
        $globalAttributes[] = $id;
        $taxonomy = wc_attribute_taxonomy_name($slug);
        register_taxonomy($taxonomy, ['product'], ['label' => $label, 'public' => false]);
        // WooCommerce normally fills this registry on the next request's init.
        $GLOBALS['wc_product_attributes'][$taxonomy] = wc_get_attribute_taxonomies()['id:' . $id];
        $assertSame($label, wc_attribute_label($taxonomy), 'registered taxonomy label');
        $term = wp_insert_term($label === 'ناشر' ? 'نشر چشمه' : 'صادق هدایت', $taxonomy);
        if (is_wp_error($term)) throw new RuntimeException($term->get_error_message());
        $attribute = new WC_Product_Attribute();
        $attribute->set_id($id);
        $attribute->set_name($taxonomy);
        $attribute->set_options([$term['term_id']]);
        $taxonomyAttributes[$label] = $attribute;
    }

    foreach ([WC_Product_Simple::class, WC_Product_Variable::class] as $type) {
        $product = new $type();
        $product->set_name('کتاب');
        $product->set_status('draft');
        $product->set_attributes([$taxonomyAttributes['نویسنده'], $taxonomyAttributes['ناشر']]);
        $products[] = $product->save();
        $product = wc_get_product($product->get_id());
        $assertSame('کتاب (نشر چشمه, صادق هدایت)', $nameFor($product), 'two real taxonomy attributes / ' . $type);
        $product->set_attributes([$taxonomyAttributes['ناشر'], ...$customAttributes(['نویسنده' => ['بزرگ علوی']])]);
        $product->save();
        $assertSame('کتاب (نشر چشمه, بزرگ علوی)', $nameFor(wc_get_product($product->get_id())), 'mixed global and custom attributes / ' . $type);
    }
    $scenarios[] = 'persisted global and custom attributes on simple and variable products';

    // Use the actual settings-save path with the submitted comma-separated value.
    $_POST = ['sync_basalam_settings' => wp_slash([
        SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_ENABLED => 'yes',
        SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_PRIORITY => 'نویسنده، ناشر',
    ])];
    $assertSame(true, SettingsPageHandler::saveSettings(), 'settings save');
    $refreshSettings();
    $assertSame('نویسنده، ناشر', syncBasalamSettings()->getSettings(SettingsConfig::PRODUCT_ATTRIBUTE_SUFFIX_PRIORITY), 'saved names');
    $product = new WC_Product_Simple();
    $product->set_name('کتاب');
    $product->set_attributes($customAttributes($book));
    $assertSame('کتاب (صادق هدایت, نشر چشمه)', $nameFor($product), 'title after saving settings');
    ob_start();
    SettingPageComponents::renderAttributeSuffixPriority();
    $input = ob_get_clean();
    $assertSame(true, strpos($input, 'value="نویسنده، ناشر"') !== false, 'settings input retains saved value');
    $assertSame(true, strpos($input, 'placeholder="مثال: ناشر, نویسنده"') !== false, 'two-attribute example');
    $scenarios[] = 'settings save and rendered input';

    echo wp_json_encode(['passed' => true, 'assertions' => $assertions, 'scenarios' => $scenarios], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} finally {
    $_POST = $originalPost;
    foreach ($products as $id) {
        $product = wc_get_product($id);
        if ($product) $product->delete(true);
    }
    foreach ($globalAttributes as $id) wc_delete_attribute($id);
    if ($originalSettings === false) {
        delete_option('sync_basalam_settings');
    } else {
        update_option('sync_basalam_settings', $originalSettings);
    }
}
