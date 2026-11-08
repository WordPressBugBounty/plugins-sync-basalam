<?php

namespace SyncBasalam\SalamGate\Admin\Ajax;

use SyncBasalam\SalamGate\Services\ProductSearchService;

defined('ABSPATH') || exit;

final class ProductSearchActions
{
    public static function search(): void
    {
        ActionGuard::forController(self::class);
        $term = isset($_POST['query']) && is_string($_POST['query'])
            ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        if (mb_strlen($term, 'UTF-8') > ProductSearchService::MAX_QUERY_LENGTH) {
            ResponseBoundary::error(['message' => 'برای جست‌وجو حداکثر ۸۰ حرف وارد کنید.'], 400);
            return;
        }
        if (!function_exists('wc_get_product')) {
            ResponseBoundary::error(['message' => 'برای جست‌وجوی محصولات، ووکامرس باید فعال باشد.'], 503);
            return;
        }
        try {
            $products = (new ProductSearchService())->search($term);
        } catch (\Throwable $error) {
            // Do not expose database/internal exception text in the browser.
            ResponseBoundary::error(['message' => 'جست‌وجوی محصولات انجام نشد؛ دوباره تلاش کنید.'], 500);
            return;
        }
        ResponseBoundary::success(['products' => $products]);
    }
}
