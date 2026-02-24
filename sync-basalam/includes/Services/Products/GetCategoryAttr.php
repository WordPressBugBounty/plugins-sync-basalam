<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;
class GetCategoryAttr
{
    public static function getAttr($categoryId)
    {
        $url = "https://openapi.basalam.com/v1/categories/$categoryId/attributes?exclude_multi_selects=true";
        $apiservice = new ApiServiceManager();

        try {
            $data = $apiservice->sendGetRequest($url, []);
        } catch (\Exception $e) {
            return [
                'body' => null,
                'status_code' => 500,
                'error' => 'خطا در دریافت ویژگی‌های دسته‌بندی: ' . $e->getMessage()
            ];
        }

        return $data;
    }
}
