<?php
if (!defined('ABSPATH')) exit;

class Sync_Basalam_Category_Mapping
{
    private static $table_name = 'sync_basalam_category_mappings';







    public static function get_woocommerce_categories()
    {
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ]);

        if (is_wp_error($terms)) {
            throw new Exception('خطا در بارگذاری دسته‌بندی‌های ووکامرس');
        }

        $categories = [];
        foreach ($terms as $term) {
            $hierarchy = self::get_category_hierarchy($term);

            $categories[] = [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => $term->parent,
                'count' => $term->count,
                'hierarchy' => $hierarchy
            ];
        }

        return $categories;
    }

    private static function get_category_hierarchy($term, $separator = ' » ')
    {
        $hierarchy = [];
        $current_term = $term;

        while ($current_term->parent != 0) {
            $parent_term = get_term($current_term->parent, 'product_cat');
            if (!is_wp_error($parent_term)) {
                $hierarchy[] = $parent_term->name;
                $current_term = $parent_term;
            } else {
                break;
            }
        }

        return !empty($hierarchy) ? implode($separator, array_reverse($hierarchy)) : '';
    }

    public static function get_basalam_categories()
    {
        $api_service = new sync_basalam_External_API_Service();
        $response = $api_service->send_get_request('https://core.basalam.com/v3/categories');

        if (!$response || !isset($response['data'])) {
            throw new Exception('خطا در دریافت دسته‌بندی‌های باسلام');
        }

        $categories_data = isset($response['data']['data']) ? $response['data']['data'] : $response['data'];

        if (!is_array($categories_data)) {
            throw new Exception('فرمت پاسخ API باسلام نامعتبر است');
        }
        return self::format_basalam_categories_tree($categories_data);
    }

    private static function format_basalam_categories_tree($categories)
    {
        $formatted = [];

        if (!is_array($categories)) {
            return $formatted;
        }

        foreach ($categories as $category) {
            if (!is_array($category) || !isset($category['id']) || !isset($category['title'])) {
                continue;
            }

            $formatted[] = [
                'id' => $category['id'],
                'name' => $category['title'],
                'parent_id' => null,
                'children' => isset($category['children']) && is_array($category['children'])
                    ? self::format_basalam_categories_tree($category['children'])
                    : []
            ];
        }

        return $formatted;
    }

    private static function format_basalam_categories($categories, $parent_name = null)
    {
        $formatted = [];

        if (!is_array($categories)) {
            return $formatted;
        }

        foreach ($categories as $category) {
            if (!is_array($category) || !isset($category['id']) || !isset($category['title'])) {
                continue;
            }

            $formatted[] = [
                'id' => $category['id'],
                'name' => $category['title'],
                'parent_name' => $parent_name,
                'slug' => isset($category['slug']) ? $category['slug'] : '',
            ];

            if (isset($category['children']) && is_array($category['children']) && !empty($category['children'])) {
                $children = self::format_basalam_categories($category['children'], $category['title']);
                $formatted = array_merge($formatted, $children);
            }
        }

        return $formatted;
    }

    public static function get_category_mappings()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $results = $wpdb->get_results(
            "SELECT * FROM $table_name ORDER BY created_at DESC",
            ARRAY_A
        );

        return $results ?: [];
    }

    public static function create_category_mapping($woo_category_id, $woo_category_name, $basalam_category_id, $basalam_category_name)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE woo_category_id = %d",
            $woo_category_id
        ));

        if ($existing) {
            throw new Exception('این دسته‌بندی ووکامرس قبلاً به یک دسته‌بندی باسلام متصل شده است');
        }

        $result = $wpdb->insert(
            $table_name,
            [
                'woo_category_id' => $woo_category_id,
                'woo_category_name' => $woo_category_name,
                'basalam_category_id' => $basalam_category_id,
                'basalam_category_name' => $basalam_category_name
            ],
            ['%d', '%s', '%d', '%s']
        );

        return $result !== false;
    }

    public static function delete_category_mapping($mapping_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $result = $wpdb->delete(
            $table_name,
            ['id' => $mapping_id],
            ['%d']
        );

        return $result !== false;
    }

    public static function get_basalam_category_for_woo_category($woo_category_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT basalam_category_id, basalam_category_name FROM $table_name WHERE woo_category_id = %d",
            $woo_category_id
        ));

        return $result;
    }

    public static function get_mapped_woo_categories()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $results = $wpdb->get_results(
            "SELECT woo_category_id, basalam_category_id FROM $table_name",
            ARRAY_A
        );

        $mappings = [];
        foreach ($results as $result) {
            $mappings[$result['woo_category_id']] = $result['basalam_category_id'];
        }

        return $mappings;
    }



    public static function get_mapping_stats()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $total_mappings = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_products = wp_count_posts('product')->publish;
        $mapped_categories = $wpdb->get_col("SELECT woo_category_id FROM $table_name");

        $products_with_mapping = 0;
        if (!empty($mapped_categories)) {
            $placeholders = implode(',', array_fill(0, count($mapped_categories), '%d'));
            $query = $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                WHERE p.post_type = 'product'
                AND p.post_status = 'publish'
                AND tr.term_taxonomy_id IN ($placeholders)",
                ...$mapped_categories
            );
            $products_with_mapping = $wpdb->get_var($query);
        }

        return [
            'total_mappings' => intval($total_mappings),
            'total_products' => intval($total_products),
            'products_with_mapping' => intval($products_with_mapping)
        ];
    }
}
