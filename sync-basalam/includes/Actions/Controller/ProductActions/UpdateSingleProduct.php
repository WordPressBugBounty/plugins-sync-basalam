<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class UpdateSingleProduct extends ActionController
{
    public function __invoke()
    {
        $productId = isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : null;

        $productOperations = new ProductOperations();

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
            wp_send_json_error($e->getMessage(), 500);
        }

        if (!$result['success']) wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
