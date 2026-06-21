<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

/**
 * Fetches and caches the maximum preparation days that Basalam allows per category.
 *
 * The full category tree is fetched from core v3/categories at most once per 24h and
 * stored as a flat [category_id => max_preparation_days] map in a transient.
 */
class CategoryPreparationService
{
    public const TRANSIENT_KEY = 'sync_basalam_category_preparation_map';

    /**
     * Maximum preparation days allowed for a category, or null when unknown / unlimited.
     */
    public function getMaxPreparationDays(?int $categoryId): ?int
    {
        if (empty($categoryId)) return null;

        $map = $this->getPreparationMap();

        if (!array_key_exists($categoryId, $map)) return null;

        $value = $map[$categoryId];

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Returns the cached [category_id => max_preparation_days] map, refreshing it
     * from the API when the 24h transient is missing.
     */
    public function getPreparationMap(): array
    {
        $map = get_transient(self::TRANSIENT_KEY);

        if (is_array($map)) return $map;

        return $this->refresh();
    }

    /**
     * Force-fetches the category tree and rebuilds the cached map. Used by the daily cron.
     */
    public function refresh(): array
    {
        $tree = $this->fetchCategoriesTree();

        if ($tree === null) {
            $existing = get_transient(self::TRANSIENT_KEY);
            if (is_array($existing)) return $existing;

            // Cache an empty map briefly so a failing endpoint isn't hammered on every product.
            set_transient(self::TRANSIENT_KEY, [], HOUR_IN_SECONDS);
            return [];
        }

        $map = [];
        $this->flatten($tree, $map);

        set_transient(self::TRANSIENT_KEY, $map, DAY_IN_SECONDS);

        return $map;
    }

    private function fetchCategoriesTree(): ?array
    {
        $response = wp_remote_get(Endpoints::CATEGORIES_PREPARATION, [
            'timeout' => 20,
            'headers' => [
                'Accept'     => 'application/json',
                'user-agent' => 'Wp-Basalam',
                'referer'    => get_site_url(),
            ],
        ]);

        if (is_wp_error($response)) {
            Logger::error('خطا در دریافت زمان آماده‌سازی دسته‌بندی‌های باسلام: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code != 200 || !is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            Logger::error('پاسخ نامعتبر هنگام دریافت زمان آماده‌سازی دسته‌بندی‌های باسلام.');
            return null;
        }

        return $data['data'];
    }

    private function flatten(array $nodes, array &$map): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['id'])) continue;

            $id = (int) $node['id'];
            $max = $node['max_preparation_days'] ?? null;
            $map[$id] = is_numeric($max) ? (int) $max : null;

            if (!empty($node['children']) && is_array($node['children'])) {
                $this->flatten($node['children'], $map);
            }
        }
    }
}
