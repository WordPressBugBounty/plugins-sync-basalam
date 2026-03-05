<?php

namespace SyncBasalam\Admin\Product\Services;

use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

class ProductQueryService
{
    public function getCreatableProducts(array $args = []): array
    {
        global $wpdb;

        $postsTable = $wpdb->posts;
        $metaTable = $wpdb->postmeta;

        $postsPerPage = $args['posts_per_page'] ?? 100;
        $includeOutOfStock = $args['include_out_of_stock'] ?? false;
        $lastId = intval($args['last_creatable_product_id'] ?? 0);
        $productIdMetaKey = ProductMetaKey::basalamProductId();

        $stockCondition = $includeOutOfStock
            ? ""
            : "AND stock.meta_value = 'instock'";

        $query = $wpdb->prepare("
            SELECT p.ID
            FROM {$postsTable} AS p
            LEFT JOIN {$metaTable} AS thumb
                ON thumb.post_id = p.ID
                AND thumb.meta_key = '_thumbnail_id'
            LEFT JOIN {$metaTable} AS basalam
                ON basalam.post_id = p.ID
                AND basalam.meta_key = %s
            LEFT JOIN {$metaTable} AS stock
                ON stock.post_id = p.ID
                AND stock.meta_key = '_stock_status'
            LEFT JOIN {$metaTable} AS price
                ON price.post_id = p.ID
                AND price.meta_key = '_price'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND thumb.meta_value IS NOT NULL
            AND basalam.post_id IS NULL
            AND p.ID > %d
            AND price.meta_value IS NOT NULL
            AND CAST(price.meta_value AS DECIMAL(10,2)) > 1000
            {$stockCondition}
            ORDER BY p.ID ASC
            LIMIT %d
        ", $productIdMetaKey, $lastId, $postsPerPage);

        $ids = $wpdb->get_col($query);

        return array_map('intval', $ids);
    }

    public function getUpdatableProducts(array $args = []): array
    {
        global $wpdb;

        $postsTable = $wpdb->posts;
        $metaTable = $wpdb->postmeta;

        $postsPerPage = $args['posts_per_page'] ?? 100;
        $lastId = intval($args['last_updatable_product_id'] ?? 0);
        $productIdMetaKey = ProductMetaKey::basalamProductId();

        $query = $wpdb->prepare("
        SELECT p.ID
        FROM {$postsTable} AS p
        INNER JOIN {$metaTable} AS basalam
            ON basalam.post_id = p.ID
            AND basalam.meta_key = %s
        WHERE p.post_type = 'product'
        AND p.post_status = 'publish'
        AND p.ID > %d
        ORDER BY p.ID ASC
        LIMIT %d
        ", $productIdMetaKey, $lastId, $postsPerPage);

        $ids = $wpdb->get_col($query);

        return array_map('intval', $ids);
    }
}
