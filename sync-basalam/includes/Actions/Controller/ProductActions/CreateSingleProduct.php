<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\JobManager;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;

class CreateSingleProduct extends ActionController
{
    public function __invoke()
    {
        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canCreate()) {
            return wp_send_json_error(['message' => $vendorSyncPolicy->getRestrictionMessage(false)], 409);
        }

        $productId = isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : null;

        $catId = isset($_POST['cat_id']) ? sanitize_text_field(wp_unslash($_POST['cat_id'])) : '';

        $catId = !empty($catId) ? explode(',', $catId) : [];

        if (!$productId) {
            wp_send_json_error(['message' => 'آیدی محصول الزامی است.'], 400);
        }

        $queued = syncBasalamContainer()->get(JobManager::class)->createProductCreationJob((int) $productId, $catId);
        if ($queued === 'exists') {
            wp_send_json_error(['message' => 'این محصول هم‌اکنون در صف ارسال به باسلام قرار دارد.'], 409);
        }
        if ($queued !== 'created') {
            Logger::error('خطا در افزودن محصول به صف باسلام.', [
                'product_id' => intval($productId),
                'operation' => 'افزودن محصول به صف باسلام',
            ]);
            wp_send_json_error(['message' => 'افزودن محصول به صف باسلام ناموفق بود؛ دوباره تلاش کنید.'], 500);
        }

        wp_send_json_success(['message' => 'محصول با موفقیت در صف ارسال به باسلام قرار گرفت.'], 202);
    }
}
