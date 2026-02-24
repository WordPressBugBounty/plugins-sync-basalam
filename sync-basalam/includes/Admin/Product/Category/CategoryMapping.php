<?php

namespace SyncBasalam\Admin\Product\Category;

use SyncBasalam\Services\ApiServiceManager;

class CategoryMapping
{
    private static $tableName = 'sync_basalam_category_mappings';

    public static function getWoocommerceCategories()
    {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            throw new \Exception('خطا در بارگذاری دسته‌بندی‌های ووکامرس');
        }

        $categories = [];
        foreach ($terms as $term) {
            $hierarchy = self::getCategoryHierarchy($term);

            $categories[] = [
                'id'        => $term->term_id,
                'name'      => $term->name,
                'slug'      => $term->slug,
                'parent'    => $term->parent,
                'count'     => $term->count,
                'hierarchy' => $hierarchy,
            ];
        }

        return $categories;
    }

    private static function getCategoryHierarchy($term, $separator = ' » ')
    {
        $hierarchy = [];
        $currentTerm = $term;

        while ($currentTerm->parent != 0) {
            $parentTerm = get_term($currentTerm->parent, 'product_cat');
            if (!is_wp_error($parentTerm)) {
                $hierarchy[] = $parentTerm->name;
                $currentTerm = $parentTerm;
            } else {
                break;
            }
        }

        return !empty($hierarchy) ? implode($separator, array_reverse($hierarchy)) : '';
    }

    public static function getBasalamCategories()
    {
        $apiService = new ApiServiceManager();
        $response = $apiService->sendGetRequest('https://openapi.basalam.com/v1/categories');

        if (!$response || !isset($response['body'])) throw new \Exception('خطا در دریافت دسته‌بندی‌های باسلام');

        $body = json_decode($response['body'], true);


        if (!is_array($body['data'])) {
            throw new \Exception('فرمت پاسخ API باسلام نامعتبر است');
        }

        return self::formatBasalamCategoriesTree($body['data']);
    }

    private static function formatBasalamCategoriesTree($categories)
    {
        $formatted = [];

        if (!is_array($categories)) return $formatted;

        foreach ($categories as $category) {
            if (!is_array($category) || !isset($category['id']) || !isset($category['title'])) {
                continue;
            }

            $formatted[] = [
                'id'        => $category['id'],
                'name'      => $category['title'],
                'parent_id' => null,
                'children'  => isset($category['children']) && is_array($category['children'])
                    ? self::formatBasalamCategoriesTree($category['children'])
                    : [],
            ];
        }

        return $formatted;
    }

    public static function getCategoryMappings()
    {
        global $wpdb;
        $tableName = $wpdb->prefix . self::$tableName;

        $results = $wpdb->get_results(
            "SELECT * FROM $tableName ORDER BY created_at DESC",
            ARRAY_A
        );

        return $results ?: [];
    }

    public static function createCategoryMapping($wooCategoryId, $wooCategoryName, $basalamCategoryIds, $basalamCategoryName)
    {
        global $wpdb;
        $tableName = $wpdb->prefix . self::$tableName;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $tableName WHERE woo_category_id = %d",
            $wooCategoryId
        ));

        if ($existing) {
            throw new \Exception('این دسته‌بندی ووکامرس قبلاً به یک دسته‌بندی باسلام متصل شده است');
        }

        $level1 = isset($basalamCategoryIds[0]) && is_numeric($basalamCategoryIds[0]) ? intval($basalamCategoryIds[0]) : null;
        $level2 = isset($basalamCategoryIds[1]) && is_numeric($basalamCategoryIds[1]) ? intval($basalamCategoryIds[1]) : null;
        $level3 = isset($basalamCategoryIds[2]) && is_numeric($basalamCategoryIds[2]) ? intval($basalamCategoryIds[2]) : null;

        $result = $wpdb->insert(
            $tableName,
            [
                'woo_category_id'         => $wooCategoryId,
                'woo_category_name'       => $wooCategoryName,
                'basalam_category_level1' => $level1,
                'basalam_category_level2' => $level2,
                'basalam_category_level3' => $level3,
                'basalam_category_name'   => $basalamCategoryName,
            ],
            ['%d', '%s', '%d', '%d', '%d', '%s']
        );

        return $result !== false;
    }

    public static function deleteCategoryMapping($mappingId)
    {
        global $wpdb;
        $tableName = $wpdb->prefix . self::$tableName;

        $result = $wpdb->delete(
            $tableName,
            ['id' => $mappingId],
            ['%d']
        );

        return $result !== false;
    }

    public static function getBasalamCategoryForWooCategory($wooCategoryId)
    {
        global $wpdb;
        $tableName = $wpdb->prefix . self::$tableName;

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT basalam_category_level1, basalam_category_level2, basalam_category_level3, basalam_category_name FROM $tableName WHERE woo_category_id = %d",
            $wooCategoryId
        ));

        if (!$result) return null;

        $categoryIds = [];
        if (!is_null($result->basalam_category_level1)) {
            $categoryIds[] = intval($result->basalam_category_level1);
        }
        if (!is_null($result->basalam_category_level2)) {
            $categoryIds[] = intval($result->basalam_category_level2);
        }
        if (!is_null($result->basalam_category_level3)) {
            $categoryIds[] = intval($result->basalam_category_level3);
        }

        return (object) [
            'basalam_category_ids'  => $categoryIds,
            'basalam_category_name' => $result->basalam_category_name,
        ];
    }

    public static function getMappedWooCategories()
    {
        global $wpdb;
        $tableName = $wpdb->prefix . self::$tableName;

        $results = $wpdb->get_results(
            "SELECT woo_category_id, basalam_category_level1, basalam_category_level2, basalam_category_level3 FROM $tableName",
            ARRAY_A
        );

        $mappings = [];
        foreach ($results as $result) {
            $categoryIds = [];
            if (!is_null($result['basalam_category_level1'])) {
                $categoryIds[] = intval($result['basalam_category_level1']);
            }
            if (!is_null($result['basalam_category_level2'])) {
                $categoryIds[] = intval($result['basalam_category_level2']);
            }
            if (!is_null($result['basalam_category_level3'])) {
                $categoryIds[] = intval($result['basalam_category_level3']);
            }
            $mappings[$result['woo_category_id']] = $categoryIds;
        }

        return $mappings;
    }

    public static function getMappingStats()
    {
        global $wpdb;
        $tableName = $wpdb->prefix . self::$tableName;

        $totalMappings = $wpdb->get_var("SELECT COUNT(*) FROM $tableName");
        $totalProducts = wp_count_posts('product')->publish;
        $mappedCategories = $wpdb->get_col("SELECT woo_category_id FROM $tableName");

        $productsWithMapping = 0;
        if (!empty($mappedCategories)) {
            $placeholders = implode(',', array_fill(0, count($mappedCategories), '%d'));
            $query = $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                WHERE p.post_type = 'product'
                AND p.post_status = 'publish'
                AND tr.term_taxonomy_id IN ($placeholders)",
                ...$mappedCategories
            );
            $productsWithMapping = $wpdb->get_var($query);
        }

        return [
            'total_mappings'        => intval($totalMappings),
            'total_products'        => intval($totalProducts),
            'products_with_mapping' => intval($productsWithMapping),
        ];
    }
}
