<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Services\ProductSearchService;

defined('ABSPATH') || exit;

final class ProductSearchActions
{
    public static function search(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'اجازه جست‌وجوی محصولات را ندارید.'], 403);
            return;
        }
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'basalam_pro_nonce')) {
            wp_send_json_error(['message' => 'اعتبار صفحه تمام شده است؛ صفحه را تازه‌سازی کنید.'], 403);
            return;
        }
        $term = isset($_POST['query']) && is_string($_POST['query'])
            ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        if (mb_strlen($term, 'UTF-8') > ProductSearchService::MAX_QUERY_LENGTH) {
            wp_send_json_error(['message' => 'برای جست‌وجو حداکثر ۸۰ حرف وارد کنید.'], 400);
            return;
        }
        if (!function_exists('wc_get_product')) {
            wp_send_json_error(['message' => 'برای جست‌وجوی محصولات، ووکامرس باید فعال باشد.'], 503);
            return;
        }
        try {
            $products = (new ProductSearchService())->search($term);
        } catch (\Throwable $error) {
            // Do not expose database/internal exception text in the browser.
            wp_send_json_error(['message' => 'جست‌وجوی محصولات انجام نشد؛ دوباره تلاش کنید.'], 500);
            return;
        }
        wp_send_json_success(['products' => $products]);
    }
}
