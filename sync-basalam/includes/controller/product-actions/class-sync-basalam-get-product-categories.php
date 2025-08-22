<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Get_Product_Categories extends Sync_BasalamController
{
    public function __invoke()
    {
        if (!isset($_POST['productTitle'])) {
            wp_send_json_error(['message' => 'عنوان محصول ارسال نشده است.'], 400);
        }

        $product_title = mb_substr(sanitize_text_field(wp_unslash($_POST['productTitle'])), 0, 120);
        $category_ids = Sync_basalam_Get_Category_id::get_category_id_from_basalam($product_title, 'all');

        if ($category_ids && is_array($category_ids)) {
            $categories = array_map(function ($category) {
                return [
                    'cat_id' => $category['cat_id'],
                    'cat_title' => $category['cat_title']
                ];
            }, $category_ids);

            wp_send_json_success($categories);
        }
    }
}
