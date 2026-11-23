<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\JobManager;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;

class UpdateSingleProduct extends ActionController
{
    public function __invoke()
    {
        $vendorSyncPolicy = syncBasalamContainer()->get(VendorSyncPolicy::class);
        if (!$vendorSyncPolicy->canUpdate()) {
            return wp_send_json_error(['message' => $vendorSyncPolicy->getRestrictionMessage(false)], 409);
        }

        $productId = isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : null;

        if (isset($_POST['cat_id'])) {
            $categoryIds = sanitize_text_field(wp_unslash($_POST['cat_id']));

            if (strpos($categoryIds, ',') !== false) {
                $categoryIds = explode(',', $categoryIds);
            } else {
                $categoryIds = [$categoryIds];
            }
        } else {
            $categoryIds = null;
        }

        if (!$productId) {
            wp_send_json_error('آیدی محصول الزامی است.', 400);
        }
        $queued = syncBasalamContainer()->get(JobManager::class)->createProductUpdateJob((int) $productId, false, $categoryIds);
        if ($queued === 'exists') {
            wp_send_json_error(['message' => 'این محصول هم‌اکنون در صف بروزرسانی باسلام قرار دارد.'], 409);
        }
        if ($queued !== 'created') {
            Logger::error('خطا در افزودن بروزرسانی محصول به صف باسلام.', [
                'product_id' => intval($productId),
                'operation' => 'افزودن بروزرسانی محصول به صف باسلام',
            ]);
            wp_send_json_error(['message' => 'افزودن بروزرسانی محصول به صف باسلام ناموفق بود؛ دوباره تلاش کنید.'], 500);
        }

        wp_send_json_success(['message' => 'بروزرسانی محصول در صف ارسال به باسلام قرار گرفت.'], 202);
    }
}
