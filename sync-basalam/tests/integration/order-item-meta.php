<?php

// docker exec -w /var/www/html/wp-content/plugins/sync-basalam \
//   basalam-wordpress-local-wordpress-1 php tests/integration/order-item-meta.php --keep-sample
if (PHP_SAPI !== 'cli') {
    exit;
}
defined('WP_HTTP_BLOCK_EXTERNAL') || define('WP_HTTP_BLOCK_EXTERNAL', true);
require getenv('WORDPRESS_BOOTSTRAP') ?: dirname(__DIR__, 5) . '/wp-load.php';

use SyncBasalam\Admin\Order\BasalamItemMeta;
use SyncBasalam\Services\Orders\OrderManager;
use SyncBasalam\Utilities\ProductMetaKey;

if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Run only in a local WordPress environment.');
}
add_filter('pre_wp_mail', '__return_false');
add_filter('pre_http_request', static function () {
    return new WP_Error('local_fixture', 'External requests are disabled for this fixture.');
});
remove_all_actions('save_post');
remove_all_actions('woocommerce_new_product');
remove_all_actions('woocommerce_update_product');
$runner = syncBasalamContainer()->get(\SyncBasalam\JobsRunner::class);
remove_action('shutdown', [$runner, 'maybeDispatchAsyncRequest'], 2);
remove_action('sync_basalam_job_created', [$runner, 'maybeDispatchAsyncRequest'], 10);

$keepSample = in_array('--keep-sample', $argv, true);
$assertions = 0;
$success = false;
$order = null;
$legacyOrder = null;
$product = null;
$assertSame = static function ($expected, $actual, string $message) use (&$assertions): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    $assertions++;
};
$render = static function (WC_Order_Item_Product $item): string {
    ob_start();
    do_action('woocommerce_after_order_itemmeta', $item->get_id(), $item, $item->get_product());
    return ob_get_clean();
};
$assertPublicMeta = static function (WC_Order_Item_Product $item) use ($assertSame): void {
    foreach (['_', ''] as $prefix) {
        $formatted = $item->get_formatted_meta_data($prefix, true);
        $assertSame(['مدل'], array_values(array_map(static function ($meta) {
            return $meta->key;
        }, $formatted)), 'Only ordinary product attributes remain public, prefix=' . $prefix);
    }
    $display = wc_display_item_meta($item, ['echo' => false]);
    $assertSame(false, strpos($display, 'باسلام') !== false, 'Public item display excludes Basalam fields');
    $assertSame(true, strpos($display, 'TU5') !== false, 'Ordinary attributes are preserved');
};

try {
    $wooName = 'هدرز فولادی ۲۰۶ HZ POWER TU5 رانر کوتاه';
    $basalamName = 'هدرز فولادی 206 HZ POWER TU5 رانر کوتاه (پس کرایه)';
    $product = new WC_Product_Simple();
    $product->set_name($wooName);
    $product->set_status('publish');
    $product->set_regular_price('100000');
    $product->save();
    update_post_meta($product->get_id(), ProductMetaKey::basalamProductId(), 57324227);

    $order = wc_create_order(['status' => 'pending']);
    $order->set_billing_first_name('نمونه');
    $order->set_billing_last_name('اصلاح متای باسلام');
    $order->set_payment_method_title('Basalam Payment');
    $order->update_meta_data('_sync_basalam_meta_regression_fixture', true);
    $order->add_order_note('سفارش نمونه محلی برای بررسی حذف تکرار نام محصول در خروجی اقلام.');
    OrderManager::addBasalamItemToOrder($order, [
        'id' => 900101,
        'quantity' => 1,
        'product' => ['id' => 57324227, 'title' => $basalamName],
        'financial_report' => ['report_items' => [['title' => 'قیمت محصول', 'amount' => 1000000]]],
    ]);
    delete_post_meta($product->get_id(), ProductMetaKey::basalamProductId());
    $assertSame(1, count($order->get_items()), 'One mapped item was imported');
    $items = $order->get_items();
    $item = reset($items);
    $assertSame($product->get_id(), $item->get_product_id(), 'Import uses the mapped WooCommerce product');
    $item->add_meta_data('مدل', 'TU5');
    $item->save();
    $order->calculate_totals();
    $order->save();

    // Read a fresh object from the database, rather than the in-memory fixture.
    $order = wc_get_order($order->get_id());
    $items = $order->get_items();
    $item = reset($items);
    $assertSame($wooName, $item->get_name(), 'WooCommerce product name is preserved');
    $assertSame($basalamName, $item->get_meta(OrderManager::BASALAM_ITEM_NAME_META_KEY), 'Private name persists');
    $assertSame('https://basalam.com/p/57324227', $item->get_meta(OrderManager::BASALAM_ITEM_LINK_META_KEY), 'Plain URL persists');
    $assertPublicMeta($item);
    $html = $render($item);
    $assertSame(1, substr_count($html, $basalamName), 'Admin shows the Basalam title once');
    $assertSame(true, strpos($html, 'href="https://basalam.com/p/57324227"') !== false, 'Admin includes clickable URL');
    $assertSame(true, strpos($html, 'مشاهده محصول در باسلام') !== false, 'Link label does not repeat the title');

    $legacyOrder = wc_create_order(['status' => 'pending']);
    $legacyId = $legacyOrder->add_product($product, 1);
    $legacy = $legacyOrder->get_item($legacyId);
    $legacy->add_meta_data(OrderManager::LEGACY_BASALAM_ITEM_NAME_META_KEY, $basalamName);
    $legacy->add_meta_data(OrderManager::LEGACY_BASALAM_ITEM_LINK_META_KEY, '<a href="https://basalam.com/p/57324227" target="_blank">' . $basalamName . '</a>');
    $legacy->add_meta_data('مدل', 'TU5');
    $legacy->save();
    $legacyOrder->save();
    $legacyOrder = wc_get_order($legacyOrder->get_id());
    $legacy = $legacyOrder->get_item($legacyId);
    $assertPublicMeta($legacy);
    $html = $render($legacy);
    $assertSame(1, substr_count($html, $basalamName), 'Legacy admin title is shown once');
    $assertSame(true, strpos($html, 'href="https://basalam.com/p/57324227"') !== false, 'Legacy admin link survives');
    $assertSame($basalamName, $legacy->get_meta(OrderManager::LEGACY_BASALAM_ITEM_NAME_META_KEY), 'Historical data is retained');

    OrderManager::setBasalamItemNameOnOrderItem($legacyOrder, $legacyId, '<script>alert(1)</script>', 57324227);
    $legacy = $legacyOrder->get_item($legacyId);
    $assertSame('', $legacy->get_meta(OrderManager::LEGACY_BASALAM_ITEM_NAME_META_KEY), 'Updated items remove legacy name');
    $assertSame('', $legacy->get_meta(OrderManager::LEGACY_BASALAM_ITEM_LINK_META_KEY), 'Updated items remove legacy link');
    $html = $render($legacy);
    $assertSame(false, strpos($html, '<script>') !== false, 'Admin title is escaped');
    $assertSame(true, strpos($html, '&lt;script&gt;') !== false, 'Escaped title is visible');
    $legacy->update_meta_data(OrderManager::BASALAM_ITEM_LINK_META_KEY, 'javascript:alert(1)');
    $assertSame(false, strpos($render($legacy), 'javascript:') !== false, 'Unsafe link scheme is removed');
    $assertPublicMeta($legacy);

    $success = true;
    echo wp_json_encode([
        'assertions' => $assertions,
        'sample_order_id' => $keepSample ? $order->get_id() : null,
        'sample_order_url' => $keepSample ? $order->get_edit_order_url() : null,
        'public_item_name' => $item->get_name(),
        'public_meta_keys' => array_values(array_map(static function ($meta) { return $meta->key; }, $item->get_formatted_meta_data())),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} finally {
    if ($legacyOrder) {
        $legacyOrder->delete(true);
    }
    if (!$keepSample || !$success) {
        if ($order) $order->delete(true);
        if ($product) $product->delete(true);
    }
}
