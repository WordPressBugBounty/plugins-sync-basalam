<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\Actions\Controller\ActionController;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class UpdateSingleProduct extends ActionController
{
    public function __invoke()
    {
        $productId = isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : null;

        $productOperations = syncBasalamContainer()->get(ProductOperations::class);

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
        try {
            $result = $productOperations->updateExistProduct($productId, $categoryIds);
        } catch (\Exception $e) {
            Logger::error("خطا در بروزرسانی محصول: " . $e->getMessage(), [
                'product_id' => intval($productId),
                'operation' => 'بروزرسانی محصول',
            ]);
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }

        if (!$result['success']) wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
