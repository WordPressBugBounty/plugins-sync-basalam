<?php

namespace SyncBasalam\SalamGate\Order;

use SyncBasalam\SalamGate\SalamGateException;
use SyncBasalam\SalamGate\Services\ConnectionCache;

defined('ABSPATH') || exit;

require_once __DIR__ . '/BasalamShippingPreflight.php';
if (!class_exists(ConnectionCache::class)) {
    require_once __DIR__ . '/../Services/ConnectionCache.php';
}

/** Keep the purchased Basalam line identity without inventing a Woo catalog product. */
final class BasalamOrderProducts
{
    public const SNAPSHOT_META = '_salam_gate_basalam_product_snapshot';

    public static function register(): void
    {
        add_filter('woocommerce_admin_order_item_thumbnail', [self::class, 'thumbnail'], 10, 3);
        // Woo skips its thumbnail filter entirely when an order line has no catalog product.
        add_filter('woocommerce_admin_html_order_item_class', [self::class, 'adminItemClass'], 10, 3);
        add_action('woocommerce_before_order_itemmeta', [self::class, 'adminItemPhoto'], 10, 3);
        add_action('admin_head', [self::class, 'adminStyles']);
    }

    /** @param \WC_Order|BasalamParcelOrder $order Reuse authenticated shipping reads. */
    public static function checkAndRepair(object $order, bool $requireShippable = true, ?array &$snapshots = null): array
    {
        OrderContext::assertSupportedOrder($order);
        $identity = ConnectionCache::identityHash();
        $snapshots = null;
        $preflight = BasalamShippingPreflight::check($order, $requireShippable, $snapshots);
        ConnectionCache::assertCurrentIdentity($identity);
        // A purchased parent line can contribute to several real native parcels.
        // Its catalog/photo snapshot must not be rebound or saved as a partial line.
        if ($order instanceof BasalamParcelOrder) { return $preflight; }
        self::repairFromSnapshots($order, $snapshots['order'], $snapshots['parcel'],
            $preflight['identity']['vendor_id'], $preflight['identity']['invoice_id'],
            $preflight['identity']['parcel_id'], $requireShippable);
        // The original preflight includes the configured defaults used by the shipping form.
        return $preflight;
    }

    /**
     * Only call with server-side authenticated original-order/parcel responses.
     * Validate the complete item set and every proposed link before saving any line.
     * Item IDs, quantities, financials, taxes, order status and stock are never rewritten.
     */
    public static function repairFromSnapshots(
        \WC_Order $order,
        array $orderDetail,
        array $parcel,
        int $vendorId,
        int $invoiceId,
        int $parcelId,
        bool $requireShippable = true
    ): array {
        $identity = ConnectionCache::identityHash();
        $preflight = BasalamShippingPreflight::fromSnapshots($order, $orderDetail, $parcel,
            $vendorId, $invoiceId, $parcelId, [], $requireShippable);
        self::assertLocalIdentity($order, $vendorId, $invoiceId, $parcelId);
        $local = [];
        foreach ($order->get_items() as $item) {
            // Shipping-only storage adapters must never perform a partial item-set repair.
            if (!$item instanceof \WC_Order_Item_Product) { return $preflight; }
            foreach (['get_meta', 'get_name', 'get_product_id', 'get_variation_id', 'get_product',
                'set_name', 'set_product_id', 'set_variation_id', 'update_meta_data', 'save'] as $method) {
                if (!method_exists($item, $method)) { return $preflight; }
            }
            $local[(int) $item->get_id()] = $item;
        }
        $original = [];
        foreach ($orderDetail['items'] as $item) { $original[(int) $item['id']] = $item; }
        $remote = [];
        foreach ($parcel['items'] as $item) { $remote[(int) $item['id']] = $item; }
        $plan = [];
        $catalog = [];
        foreach ($preflight['order_data']['items'] as $source) {
            $item = $local[$source['item_id']];
            $productId = $source['product_id'];
            $variationId = $source['variation_id'];
            $snapshot = [
                'vendor_id' => $vendorId, 'invoice_id' => $invoiceId, 'parcel_id' => $parcelId,
                'external_id' => $source['external_id'], 'product_id' => $productId,
                'variation_id' => $variationId,
                'image' => self::productImage($original[$source['external_id']]['product'] ?? []),
            ];
            if ($snapshot['image'] === null) {
                $snapshot['image'] = self::productImage($remote[$source['external_id']]['product'] ?? []);
            }
            $stored = $item->get_meta(self::SNAPSHOT_META, true);
            if (is_array($stored)) {
                foreach (['vendor_id', 'invoice_id', 'parcel_id', 'external_id', 'product_id', 'variation_id'] as $key) {
                    if ((string) ($stored[$key] ?? '') !== (string) $snapshot[$key]) {
                        throw self::error('ارتباط ذخیره‌شده یکی از اقلام با محصول باسلام متفاوت است؛ ابتدا سفارش را بررسی کنید.');
                    }
                }
            } elseif ($stored !== '' && $stored !== null) {
                throw self::error('اطلاعات ذخیره‌شده محصول باسلام معتبر نیست؛ ابتدا سفارش را بررسی کنید.');
            }
            $currentProductId = (int) $item->get_product_id();
            $currentVariationId = (int) $item->get_variation_id();
            $current = $item->get_product();
            if ($current && !self::isPlaceholder($current)) {
                if (!self::matches($current, $vendorId, $productId, $variationId)
                    || ($variationId > 0 && (int) $current->get_parent_id() !== $currentProductId)
                    || (int) $current->get_id() !== ($variationId > 0 ? $currentVariationId : $currentProductId)
                    || ($variationId === 0 && $currentVariationId !== 0)) {
                    throw self::error('محصول متصل به یکی از اقلام ووکامرس با محصول اصلی باسلام مطابقت ندارد؛ اتصال خودکار انجام نشد.');
                }
                // An explicit correct catalog association remains intact, including its image.
                $target = ['product_id' => $currentProductId, 'variation_id' => $currentVariationId];
            } else {
                $key = $productId . ':' . $variationId;
                if (!array_key_exists($key, $catalog)) {
                    $catalog[$key] = self::catalogMatch($vendorId, $productId, $variationId);
                }
                $target = $catalog[$key] ?? ['product_id' => 0, 'variation_id' => 0];
            }
            $pointerChanged = $target['product_id'] !== $currentProductId || $target['variation_id'] !== $currentVariationId;
            $reducedStock = $item->get_meta('_reduced_stock', true);
            if ($pointerChanged && !in_array($reducedStock, ['', null, false, 0, 0.0, '0'], true)) {
                throw self::error('موجودی این قلم سفارش قبلاً از محصول ووکامرس کسر شده است؛ اتصال محصول بدون بررسی موجودی تغییر نمی‌کند.');
            }
            $plan[] = ['item' => $item, 'name' => $source['title'], 'snapshot' => $snapshot,
                'target' => $target, 'pointer_changed' => $pointerChanged,
                'changed' => $pointerChanged || $item->get_name() !== $source['title'] || $stored !== $snapshot];
        }
        ConnectionCache::assertCurrentIdentity($identity);
        self::assertLocalIdentity($order, $vendorId, $invoiceId, $parcelId);
        foreach ($plan as $change) {
            if (!$change['changed']) { continue; }
            ConnectionCache::assertCurrentIdentity($identity);
            $item = $change['item'];
            if ($change['pointer_changed']) {
                // set_product() would also overwrite tax/quantity data from the current catalog.
                $item->set_product_id($change['target']['product_id']);
                $item->set_variation_id($change['target']['variation_id']);
            }
            $item->set_name($change['name']);
            $item->update_meta_data(self::SNAPSHOT_META, $change['snapshot']);
            $item->save();
        }
        return $preflight;
    }

    /** Native photo only supplements a line with no actual Woo catalog association. */
    public static function thumbnail($thumbnail, $itemId, $item)
    {
        if (!$item instanceof \WC_Order_Item_Product || (int) $itemId !== (int) $item->get_id()
            || (int) $item->get_product_id() > 0
            || (int) $item->get_variation_id() > 0) { return $thumbnail; }
        $snapshot = $item->get_meta(self::SNAPSHOT_META, true);
        if (!is_array($snapshot) || self::positiveId($snapshot['vendor_id'] ?? null) === null
            || self::positiveId($snapshot['product_id'] ?? null) === null
            || self::positiveId($snapshot['invoice_id'] ?? null) === null
            || self::positiveId($snapshot['parcel_id'] ?? null) === null
            || self::positiveId($snapshot['external_id'] ?? null) === null) { return $thumbnail; }
        $url = self::imageUrl($snapshot['image'] ?? null);
        if ($url === null) { return $thumbnail; }
        return '<img class="salam-gate-basalam-item-thumbnail" src="' . esc_url($url)
            . '" alt="' . esc_attr($item->get_name()) . '" width="60" height="60" loading="lazy" style="object-fit:cover;border-radius:6px;" />';
    }

    public static function adminItemClass($classes, $item, $order): string
    {
        $classes = is_string($classes) ? $classes : '';
        if (!$item instanceof \WC_Order_Item_Product
            || self::thumbnail('', $item->get_id(), $item) === '') { return $classes; }
        return trim($classes . ' salam-gate-basalam-native-item');
    }

    /** Render a real semantic image without fabricating a WC_Product or a catalog editor link. */
    public static function adminItemPhoto($itemId, $item, $product): void
    {
        if ($product) { return; }
        $image = self::thumbnail('', $itemId, $item);
        if ($image === '') { return; }
        // Image markup is built only by thumbnail(), with separately escaped URL and alt text.
        echo '<span class="salam-gate-basalam-native-photo">' . $image . '</span>';
    }

    /** Keep the standard table cells/column widths; style only proven native-photo rows. */
    public static function adminStyles(): void
    {
        echo '<style>
            #woocommerce-order-items tr.salam-gate-basalam-native-item > td.thumb .wc-order-item-thumbnail { visibility: hidden; }
            #woocommerce-order-items tr.salam-gate-basalam-native-item > td.name { position: relative; }
            #woocommerce-order-items tr.salam-gate-basalam-native-item > td.name .wc-order-item-name { display: block; padding-inline-start: 52px; min-height: 42px; }
            #woocommerce-order-items tr.salam-gate-basalam-native-item .salam-gate-basalam-native-photo { position: absolute; inset-block-start: 1.5em; inset-inline-start: 1em; width: 40px; height: 40px; }
            #woocommerce-order-items tr.salam-gate-basalam-native-item .salam-gate-basalam-native-photo img { display: block; width: 100%; height: 100%; object-fit: cover; border: 1px solid #e8e8e8; border-radius: 6px; }
        </style>';
    }

    private static function assertLocalIdentity(\WC_Order $order, int $vendorId, int $invoiceId, int $parcelId): void
    {
        $connected = function_exists('syncBasalamSettings')
            ? self::positiveId(syncBasalamSettings()->getSettings('vendor_id')) : null;
        $known = BasalamOrderContext::getParcelId((int) $order->get_id());
        $savedExternal = $order->get_meta('_salam_gate_external_id');
        if ($connected !== $vendorId || BasalamOrderContext::getInvoiceId((int) $order->get_id()) !== $invoiceId
            || ($known !== null && $known !== $parcelId)
            || (self::positiveId($order->get_meta('_salam_gate_parcel_id')) !== null
                && $savedExternal !== '' && self::positiveId($savedExternal) !== $parcelId)) {
            throw self::error('ارتباط سفارش با غرفه یا مرسوله باسلام تغییر کرده است؛ ابتدا سفارش را دوباره بررسی کنید.');
        }
    }

    /** Unique vendor-scoped product plus exact variation under that same parent. */
    private static function catalogMatch(int $vendorId, int $productId, int $variationId): ?array
    {
        $key = 'sync_basalam_product_id_' . $vendorId;
        $parents = get_posts(['post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids',
            'posts_per_page' => 2, 'meta_key' => $key, 'meta_value' => (string) $productId]);
        if (count($parents) !== 1) { return null; }
        $parentId = self::positiveId($parents[0]);
        $parent = $parentId === null ? false : wc_get_product($parentId);
        if (!$parent || $parent->get_status() === 'trash'
            || self::positiveId($parent->get_meta($key, true)) !== $productId) { return null; }
        if ($variationId === 0) {
            return $parent->is_type('simple') ? ['product_id' => $parentId, 'variation_id' => 0] : null;
        }
        if (!$parent->is_type('variable')) { return null; }
        $variations = get_posts(['post_type' => 'product_variation', 'post_status' => 'any', 'fields' => 'ids',
            'post_parent' => $parentId, 'posts_per_page' => 2,
            'meta_key' => 'sync_basalam_variation_id', 'meta_value' => (string) $variationId]);
        if (count($variations) !== 1) { return null; }
        $localVariationId = self::positiveId($variations[0]);
        $variation = $localVariationId === null ? false : wc_get_product($localVariationId);
        if (!$variation || (int) $variation->get_parent_id() !== $parentId
            || !self::matches($variation, $vendorId, $productId, $variationId)) { return null; }
        return ['product_id' => $parentId, 'variation_id' => $localVariationId];
    }

    private static function matches($product, int $vendorId, int $productId, int $variationId): bool
    {
        if ($product->get_status() === 'trash' || self::isPlaceholder($product)) { return false; }
        $key = 'sync_basalam_product_id_' . $vendorId;
        if ($variationId === 0) {
            return $product->is_type('simple') && self::positiveId($product->get_meta($key, true)) === $productId;
        }
        if (!$product->is_type('variation')
            || self::positiveId($product->get_meta('sync_basalam_variation_id', true)) !== $variationId) { return false; }
        $parentId = self::positiveId($product->get_parent_id());
        $parent = $parentId === null ? false : wc_get_product($parentId);
        return $parent && $parent->is_type('variable') && $parent->get_status() !== 'trash'
            && self::positiveId($parent->get_meta($key, true)) === $productId;
    }

    private static function isPlaceholder($product): bool
    {
        if ($product->get_sku() !== 'placeholder-basalam-product') { return false; }
        foreach ($product->get_meta_data() as $meta) {
            $data = $meta->get_data();
            if (strpos((string) ($data['key'] ?? ''), 'sync_basalam_product_id') === 0
                && ($data['value'] ?? '') !== '' && ($data['value'] ?? null) !== null) { return false; }
        }
        return true;
    }

    private static function productImage($product): ?string
    {
        if (!is_array($product)) { return null; }
        foreach (is_array($product['photos'] ?? null) ? $product['photos'] : [] as $photo) {
            if (!is_array($photo)) { continue; }
            foreach ([$photo['resized']['sm'] ?? null, $photo['resized']['md'] ?? null, $photo['original'] ?? null] as $url) {
                if (($safe = self::imageUrl($url)) !== null) { return $safe; }
            }
        }
        $photo = is_array($product['photo'] ?? null) ? $product['photo'] : [];
        foreach ([$photo['sm'] ?? null, $photo['md'] ?? null, $photo['original'] ?? null] as $url) {
            if (($safe = self::imageUrl($url)) !== null) { return $safe; }
        }
        return null;
    }

    private static function imageUrl($url): ?string
    {
        if (!is_string($url) || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $url)
            || !filter_var($url, FILTER_VALIDATE_URL)) { return null; }
        $parts = parse_url($url);
        return is_array($parts) && !isset($parts['user']) && !isset($parts['pass'])
            && isset($parts['host']) && in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true) ? $url : null;
    }

    private static function positiveId($value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) { return null; }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private static function error(string $message): SalamGateException
    {
        return new SalamGateException($message, 422, [], SalamGateException::KIND_VALIDATION);
    }
}
