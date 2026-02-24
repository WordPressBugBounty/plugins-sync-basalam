<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Admin\Product\ProductOperations;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class CreateSingleProduct extends ActionController
{
    public function __invoke()
    {
        $productOperations = new ProductOperations();

        $productId = isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : null;

        $catId = isset($_POST['cat_id']) ? sanitize_text_field(wp_unslash($_POST['cat_id'])) : '';

        $catId = !empty($catId) ? explode(',', $catId) : [];

        if ($productId) {
            try {
                 $result = $productOperations->createNewProduct($productId, $catId);
            } catch (\Exception $e) {
                wp_send_json_error(['message' => $e->getMessage()], 500);
            }
        }
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], $result['status_code'] ?? 500);
        }

        wp_send_json_success(['message' => $result['message']], $result['status_code'] ?? 200);
    }
}
