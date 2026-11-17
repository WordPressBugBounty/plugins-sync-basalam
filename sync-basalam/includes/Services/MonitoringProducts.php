<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Product\ProductDataFactory;
use SyncBasalam\Admin\Product\Data\Strategies\QuickUpdateProductStrategy;
use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\Products\ProductConnection;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

/** Export the same absolute price/stock used by local synchronization. */
class MonitoringProducts
{
    public const PAGE_SIZE = 20;

    public function page(int $afterId = 0, int $productId = 0, array $productIds = []): array
    {
        global $wpdb;
        if ($afterId < 0 || $productId < 0 || ($productId > 0 && $productIds) || count($productIds) > self::PAGE_SIZE) {
            throw new \InvalidArgumentException('Invalid selected products');
        }
        if ($productIds) $productIds = MonitoringCatalog::parseIds(implode(',', $productIds), self::PAGE_SIZE);
        $explicit = $productId > 0 || (bool) $productIds;
        $health = $this->healthSnapshot();
        $ids = $explicit ? ($productIds ?: [$productId]) : $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p WHERE p.ID > %d
             AND p.post_type = 'product' AND p.post_status = 'publish'
             AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID
                 AND m.meta_key = %s AND m.meta_value REGEXP '^[1-9][0-9]*$')
             ORDER BY p.ID LIMIT %d",
            $afterId, ProductMetaKey::basalamProductId(), self::PAGE_SIZE + 1
        ));
        if (!is_array($ids) || (!$explicit && $wpdb->last_error)) throw new \RuntimeException('Source unavailable');
        $more = count($ids) > self::PAGE_SIZE;
        $ids = array_slice($ids, 0, self::PAGE_SIZE);
        $items = [];
        foreach ($ids as $id) $items[] = $this->exportProduct((int) $id);
        return [
            'schema_version' => 1, 'site_url' => $health['site_url'],
            'vendor_id' => $health['vendor_id'], 'measured_at' => time(),
            'can_update' => $health['can_update'], 'connected_units' => $health['connected_units'],
            'capacity_unit' => 'parent_product',
            'repair_supported' => true,
            'coverage_supported' => true,
            'after_id' => $afterId, 'next_cursor' => $ids ? (int) end($ids) : $afterId,
            'has_more' => $explicit ? false : $more, 'items' => $items,
        ];
    }

    protected function healthSnapshot(): array
    {
        return (new MonitoringHealth())->snapshot();
    }

    protected function exportProduct(int $id): array
    {
        $remoteId = (int) get_post_meta($id, ProductMetaKey::basalamProductId(), true);
        $product = wc_get_product($id);
        $base = ['woo_product_id' => $id, 'basalam_product_id' => max(0, $remoteId),
                 'title' => $product ? mb_substr($product->get_name(), 0, 160) : 'محصول حذف‌شده',
                 'eligible' => false, 'reason' => 'source_removed', 'targets' => []];
        if (!$product || $product->get_status() !== 'publish' || $remoteId <= 0 || $product->is_type('variation')) return self::withRevision($base);
        if (!$product->is_type('simple') && !$product->is_type('variable')) {
            return self::withRevision(array_merge($base, ['reason' => 'unsupported_type']));
        }
        try {
            ProductConnection::assertUnique($id, $remoteId);
            $handler = (new ProductDataFactory())->createHandler($product);
            $payload = (new QuickUpdateProductStrategy())->collect($product, $handler);
            $settings = syncBasalamSettings()->getSettings();
            $policy = syncBasalamContainer()->get(VendorSyncPolicy::class);
            $syncFields = $settings[SettingsConfig::SYNC_PRODUCT_FIELDS] ?? 'all';
            if ($syncFields !== 'price_stock' && !$policy->shouldRestrictUpdateFields(false)) {
                // Preserve price/stock overrides from the existing collection
                // hook without collecting/uploading photos or other fields.
                $overrides = apply_filters('sync_basalam_product_payload', null, $product, $handler,
                    $syncFields === 'custom' ? 'custom_update' : 'update');
                if (is_array($overrides)) {
                    $payload = array_replace($payload, array_intersect_key($overrides,
                        ['primary_price' => true, 'stock' => true, 'variants' => true]));
                }
            }
            $payload = apply_filters('sync_basalam_product_data_before_update', $payload, $id);
            if (!$policy->canUpdate(false)) return self::withRevision(array_merge($base, ['reason' => 'updates_disabled']));
            $payload = $policy->restrictUpdatePayload($payload, false);
            $fields = ['primary_price', 'stock'];
            if (($settings[SettingsConfig::SYNC_PRODUCT_FIELDS] ?? 'all') === 'custom') {
                $priceKey = $product->is_type('variable') ? SettingsConfig::SYNC_PRODUCT_FIELD_VARIANT_PRICE : SettingsConfig::SYNC_PRODUCT_FIELD_PRICE;
                $stockKey = $product->is_type('variable') ? SettingsConfig::SYNC_PRODUCT_FIELD_VARIANT_STOCK : SettingsConfig::SYNC_PRODUCT_FIELD_STOCK;
                $fields = [];
                if (!empty($settings[$priceKey])) $fields[] = 'primary_price';
                if (!empty($settings[$stockKey])) $fields[] = 'stock';
            }
            return self::project($base, $payload, $product->is_type('variable'), $fields);
        } catch (\Throwable $error) {
            return self::withRevision(array_merge($base, ['reason' => 'source_invalid']));
        }
    }

    /** Only allow selected, complete numeric fields and existing variant ids. */
    public static function project(array $base, array $payload, bool $variable, array $fields): array
    {
        $rows = $variable ? ($payload['variants'] ?? []) : [$payload];
        // Never silently cover only part of a parent. Oversized products remain
        // selected but require attention until chunked variant scans are supported.
        if (count($rows) > 500) return self::withRevision(array_merge($base, ['reason' => 'variant_limit_exceeded']));
        if (!$fields || !$rows) return self::withRevision(array_merge($base, ['reason' => 'fields_or_mapping_missing']));
        $seen = [];
        $targets = [];
        foreach ($rows as $row) {
            $variationId = $variable ? ($row['id'] ?? null) : null;
            if ($variable && (!is_numeric($variationId) || (int) $variationId <= 0 || isset($seen[(int) $variationId]))) {
                return self::withRevision(array_merge($base, ['reason' => 'mapping_incomplete']));
            }
            if ($variable) $seen[(int) $variationId] = true;
            $target = ['variation_id' => $variable ? (int) $variationId : null];
            foreach ($fields as $field) {
                if (!in_array($field, ['primary_price', 'stock'], true)) continue;
                if (!array_key_exists($field, $row) || !is_numeric($row[$field]) || !is_finite((float) $row[$field])) {
                    return self::withRevision(array_merge($base, ['reason' => 'value_missing']));
                }
                if ($field === 'primary_price' && (float) $row[$field] < 1000) {
                    return self::withRevision(array_merge($base, ['reason' => 'price_invalid']));
                }
                $target[$field] = $field === 'stock' ? max(0, min(10000, (int) $row[$field])) : (int) $row[$field];
            }
            if (count($target) < 2) return self::withRevision(array_merge($base, ['reason' => 'fields_or_mapping_missing']));
            $targets[] = $target;
        }
        return self::withRevision(array_merge($base, ['eligible' => true, 'reason' => 'ok', 'targets' => $targets]));
    }

    private static function withRevision(array $item): array
    {
        $item['revision'] = hash('sha256', wp_json_encode([$item['basalam_product_id'], $item['eligible'], $item['targets']]));
        return $item;
    }
}
