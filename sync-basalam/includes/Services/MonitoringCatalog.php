<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

/** Lightweight parent inventory: no WooCommerce payloads, order scans or HTTP. */
class MonitoringCatalog
{
    public const PAGE_SIZE = 100;
    public const COUNT_TTL = 60;

    public static function registerHooks(): void
    {
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [__CLASS__, 'mappingChanged'], 10, 4);
        }
        add_action('save_post_product', [__CLASS__, 'invalidateCount']);
        add_action('deleted_post', [__CLASS__, 'postDeleted'], 10, 2);
        add_action('transition_post_status', [__CLASS__, 'statusChanged'], 10, 3);
    }

    public static function mappingChanged($metaId, $postId, $key, $value): void
    {
        if ($key === ProductMetaKey::basalamProductId()) self::invalidateCount();
    }

    public static function postDeleted($id, $post): void
    {
        if (is_object($post) && $post->post_type === 'product') self::invalidateCount();
    }

    public static function statusChanged($newStatus, $oldStatus, $post): void
    {
        if ($newStatus !== $oldStatus && is_object($post) && $post->post_type === 'product') self::invalidateCount();
    }

    private static function countCacheKey(): string
    {
        // Switching the connected vendor must never reuse another vendor's count.
        return 'woosalam_monitoring_parents_' . hash('sha256', ProductMetaKey::basalamProductId());
    }

    public static function invalidateCount(): void
    {
        delete_transient(self::countCacheKey());
    }

    private static function validFirstMapping(string $alias): string
    {
        global $wpdb;
        $maxId = (string) PHP_INT_MAX;
        $digits = strlen($maxId);
        // get_post_meta(..., true) uses the earliest row, even when later
        // duplicates contain valid IDs. Catalog/count must agree with repairs.
        return "{$alias}.meta_value REGEXP '^[1-9][0-9]*$'
            AND (CHAR_LENGTH({$alias}.meta_value) < {$digits}
                OR (CHAR_LENGTH({$alias}.meta_value) = {$digits} AND {$alias}.meta_value <= '{$maxId}'))
            AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} older
                WHERE older.post_id = {$alias}.post_id AND older.meta_key = {$alias}.meta_key
                AND older.meta_id < {$alias}.meta_id)";
    }

    public static function connectedCount(): int
    {
        global $wpdb;
        $key = self::countCacheKey();
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['count'], $cached['measured_at'])
                && is_int($cached['count']) && $cached['count'] >= 0
                && $cached['measured_at'] > time() - self::COUNT_TTL) return $cached['count'];
        // EXISTS prevents duplicate mapping rows and variations inflating capacity.
        $validMapping = self::validFirstMapping('m');
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_status = 'publish' AND p.post_type = 'product' AND EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID
                AND m.meta_key = %s AND {$validMapping}
             )", ProductMetaKey::basalamProductId()
        ));
        if ($count === null || $wpdb->last_error) throw new \RuntimeException('Monitoring count unavailable');
        $count = (int) $count;
        set_transient($key, ['count' => $count, 'measured_at' => time()], self::COUNT_TTL);
        return $count;
    }

    /** Parse a bounded CSV before any database or product work starts. */
    public static function parseIds($value, int $limit): array
    {
        if ($value === null) return [];
        if (!is_string($value) || $value === '' || strlen($value) > $limit * 21) {
            throw new \InvalidArgumentException('Invalid product ids');
        }
        $parts = explode(',', $value);
        if (count($parts) > $limit) throw new \InvalidArgumentException('Too many product ids');
        $ids = [];
        foreach ($parts as $part) {
            if (!preg_match('/^[1-9][0-9]*$/D', $part) || (string) (int) $part !== $part) {
                throw new \InvalidArgumentException('Invalid product id');
            }
            $ids[] = (int) $part;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public const MAX_SELECTED = 25000;

    /** Read-only, authenticated browse. Filters apply before paging, across the entire catalog. */
    public function browse(array $input): array
    {
        global $wpdb;
        $after = $input['after_id'] ?? 0;
        $limit = $input['limit'] ?? 20;
        $view = $input['view'] ?? 'all';
        $sort = $input['sort'] ?? 'id';
        $query = $input['query'] ?? '';
        $selected = $input['selected_ids'] ?? [];
        $salesCursor = $input['after_sales'] ?? null;
        if (!is_int($after) || $after < 0 || !is_int($limit) || $limit < 1 || $limit > self::PAGE_SIZE
            || !in_array($view, ['all', 'monitored', 'unmonitored'], true)
            || !in_array($sort, ['id', 'bestsellers'], true) || !is_string($query) || mb_strlen($query) > 160
            || !is_array($selected) || count($selected) > self::MAX_SELECTED
            || ($salesCursor !== null && (!is_int($salesCursor) || $salesCursor < 0))
            || ($sort === 'bestsellers' && (($after > 0) !== ($salesCursor !== null)))) {
            throw new \InvalidArgumentException('Invalid catalog browse');
        }
        foreach ($selected as $id) if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('Invalid selected id');
        $query = trim(strtr($query, array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789'))));
        $valid = self::validFirstMapping('c');
        // WooCommerce updates the parent total_sales for variation order items too.
        // Reading the first metadata row agrees with WooCommerce's product data store.
        $sales = "GREATEST(0, COALESCE((SELECT CAST(s.meta_value AS SIGNED) FROM {$wpdb->postmeta} s
            WHERE s.post_id = p.ID AND s.meta_key = 'total_sales' ORDER BY s.meta_id LIMIT 1), 0))";
        $where = "p.post_type = 'product' AND p.post_status = 'publish'
            AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} c WHERE c.post_id = p.ID AND c.meta_key = %s AND {$valid})";
        $args = [ProductMetaKey::basalamProductId()];
        if ($query !== '') {
            $numeric = preg_match('/^[1-9][0-9]*$/D', $query) && (string) (int) $query === $query;
            $where .= ' AND (p.post_title LIKE %s' . ($numeric ? ' OR p.ID = %d)' : ')');
            $args[] = '%' . $wpdb->esc_like($query) . '%';
            if ($numeric) $args[] = (int) $query;
        }
        if ($view !== 'all') {
            if (!$selected && $view === 'monitored') $where .= ' AND 1 = 0';
            elseif ($selected) {
                $where .= ' AND p.ID ' . ($view === 'monitored' ? 'IN' : 'NOT IN')
                    . ' (' . implode(',', array_fill(0, count($selected), '%d')) . ')';
                $args = array_merge($args, $selected);
            }
        }
        if ($sort === 'id') {
            $where .= ' AND p.ID > %d'; $args[] = $after;
        } elseif ($salesCursor !== null) {
            $where .= " AND ({$sales} < %d OR ({$sales} = %d AND p.ID > %d))";
            array_push($args, $salesCursor, $salesCursor, $after);
        }
        $order = $sort === 'id' ? 'p.ID' : 'total_sales DESC, p.ID';
        $args[] = $limit + 1;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT p.ID, {$sales} AS total_sales FROM {$wpdb->posts} p
            WHERE {$where} ORDER BY {$order} LIMIT %d", $args), ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error) throw new \RuntimeException('Catalog unavailable');
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $ids = array_map('intval', array_column($rows, 'ID'));
        // Exact lookup shares eligibility/mapping rules with scanner and repair.
        $page = $this->page(0, $limit, $ids ?: [PHP_INT_MAX]);
        $lookup = array_column($page['items'], null, 'woo_product_id');
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $item = $lookup[$id];
            $item['total_sales'] = max(0, (int) $row['total_sales']);
            $thumbnail = get_post_thumbnail_id($id);
            $url = $thumbnail ? wp_get_attachment_image_url($thumbnail, 'woocommerce_thumbnail') : false;
            $item['image_url'] = $url ? esc_url_raw($url, ['http', 'https']) : '';
            $items[] = $item;
        }
        return array_merge($page, ['items' => $items, 'has_more' => $more, 'sort' => $sort,
            'next_cursor' => $ids ? end($ids) : $after,
            'next_sales' => $rows ? max(0, (int) end($rows)['total_sales']) : $salesCursor]);
    }

    public function page(int $afterId = 0, int $limit = self::PAGE_SIZE, array $productIds = []): array
    {
        global $wpdb;
        if ($afterId < 0 || $limit < 1 || $limit > self::PAGE_SIZE || count($productIds) > self::PAGE_SIZE) {
            throw new \InvalidArgumentException('Invalid catalog page');
        }
        // Revalidate direct service callers as well as REST callers.
        if ($productIds) $productIds = self::parseIds(implode(',', $productIds), self::PAGE_SIZE);
        $metaKey = ProductMetaKey::basalamProductId();
        $select = "SELECT p.ID, p.post_title, p.post_type, p.post_status,
            (SELECT m.meta_value FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s
             ORDER BY m.meta_id LIMIT 1) AS remote_id FROM {$wpdb->posts} p";
        if ($productIds) {
            $sql = $wpdb->prepare($select . ' WHERE p.ID IN (' . implode(',', array_fill(0, count($productIds), '%d')) . ') ORDER BY p.ID',
                array_merge([$metaKey], $productIds));
        } else {
            $validMapping = self::validFirstMapping('c');
            $sql = $wpdb->prepare($select . " WHERE p.ID > %d AND p.post_type = 'product' AND p.post_status = 'publish'
                AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} c WHERE c.post_id = p.ID
                    AND c.meta_key = %s AND {$validMapping}) ORDER BY p.ID LIMIT %d",
                $metaKey, $afterId, $metaKey, $limit + 1);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error) throw new \RuntimeException('Catalog unavailable');
        $more = !$productIds && count($rows) > $limit;
        if (!$productIds) $rows = array_slice($rows, 0, $limit);
        $byId = [];
        foreach ($rows as $row) {
            $remote = (string) ($row['remote_id'] ?? '');
            $eligible = $row['post_type'] === 'product' && $row['post_status'] === 'publish'
                && preg_match('/^[1-9][0-9]*$/D', $remote) && (string) (int) $remote === $remote;
            $byId[(int) $row['ID']] = [
                'woo_product_id' => (int) $row['ID'], 'basalam_product_id' => $eligible ? (int) $remote : 0,
                'title' => mb_substr(sanitize_text_field($row['post_title']), 0, 160),
                'eligible' => (bool) $eligible, 'reason' => $eligible ? 'ok' : 'source_removed',
            ];
        }
        $ids = $productIds ?: array_keys($byId);
        $items = [];
        foreach ($ids as $id) $items[] = $byId[$id] ?? [
            'woo_product_id' => $id, 'basalam_product_id' => 0, 'title' => 'محصول حذف‌شده',
            'eligible' => false, 'reason' => 'source_removed',
        ];
        $settings = syncBasalamSettings()->getSettings();
        return [
            'schema_version' => 1, 'capacity_unit' => 'parent_product', 'coverage_supported' => true,
            'site_url' => untrailingslashit(get_site_url()), 'vendor_id' => (int) ($settings[SettingsConfig::VENDOR_ID] ?? 0),
            'measured_at' => time(), 'items' => $items, 'next_cursor' => $ids ? (int) end($ids) : $afterId,
            'has_more' => $more,
        ];
    }
}
