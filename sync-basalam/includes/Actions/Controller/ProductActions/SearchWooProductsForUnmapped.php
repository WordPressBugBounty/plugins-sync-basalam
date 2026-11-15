<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Utilities\ProductMetaKey;

defined('ABSPATH') || exit;

/**
 * جستجوی محصولات ووکامرسِ بدون اتصال برای مودال «اتصال به محصول وردپرس».
 *
 * جستجو کاملا محلی است (بدون تماس با API) تا باز و بسته کردن مودال هیچ فشاری
 * به سایت یا باسلام نیاورد.
 */
class SearchWooProductsForUnmapped extends ActionController
{
    private const LIMIT = 10;

    public function __invoke()
    {
        global $wpdb;

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $title = trim($title);

        if ($title === '') {
            wp_send_json_error(['message' => 'عنوان محصول را وارد کنید.'], 400);
        }

        $productIdMetaKey = ProductMetaKey::basalamProductId();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Title search over core product tables; results must reflect connections made moments ago.
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND pm.post_id IS NULL
               AND p.post_title LIKE %s
             ORDER BY p.ID DESC
             LIMIT %d",
            $productIdMetaKey,
            '%' . $wpdb->esc_like($title) . '%',
            self::LIMIT
        ));

        $results = [];
        foreach ((array) $products as $product) {
            $wooProduct = function_exists('wc_get_product') ? wc_get_product($product->ID) : null;

            $results[] = [
                'id' => (int) $product->ID,
                'title' => (string) $product->post_title,
                'price' => $wooProduct ? (string) $wooProduct->get_price() : '',
                'edit_link' => get_edit_post_link($product->ID, 'raw') ?: '',
            ];
        }

        wp_send_json_success(['products' => $results]);
    }
}
