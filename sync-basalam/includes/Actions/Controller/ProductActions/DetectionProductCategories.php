<?php

namespace SyncBasalam\Actions\Controller\ProductActions;

use SyncBasalam\Services\Products\GetCategoryId;
use SyncBasalam\Actions\Controller\ActionController;

defined('ABSPATH') || exit;

class DetectionProductCategories extends ActionController
{
    public function __invoke()
    {
        if (!isset($_POST['productTitle'])) {
            wp_send_json_error(['message' => 'عنوان محصول ارسال نشده است.'], 400);
        }

        $productTitle = mb_substr(sanitize_text_field(wp_unslash($_POST['productTitle'])), 0, 120);
        $categoryIds = GetCategoryId::getCategoryIdFromBasalam($productTitle, 'all');

        if ($categoryIds && is_array($categoryIds)) {
            $categories = array_map(function ($category) {
                return [
                    'cat_id'    => $category['cat_id'],
                    'cat_title' => $category['cat_title'],
                ];
            }, $categoryIds);

            wp_send_json_success($categories);
        }
    }
}
