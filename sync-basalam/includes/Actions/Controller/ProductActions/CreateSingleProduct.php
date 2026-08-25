<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\Actions\Controller\ActionController;
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

        $productOperations = syncBasalamContainer()->get(ProductOperations::class);

        $productId = isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : null;

        $catId = isset($_POST['cat_id']) ? sanitize_text_field(wp_unslash($_POST['cat_id'])) : '';

        $catId = !empty($catId) ? explode(',', $catId) : [];

        if (!$productId) {
            wp_send_json_error(['message' => 'آیدی محصول الزامی است.'], 400);
        }

        try {
             $result = $productOperations->createNewProduct($productId, $catId);
        } catch (\Exception $e) {
            Logger::error("خطا در اضافه کردن محصول به باسلام: " . $e->getMessage(), [
                'product_id' => intval($productId),
                'operation' => 'اضافه کردن محصول به باسلام',
            ]);
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
