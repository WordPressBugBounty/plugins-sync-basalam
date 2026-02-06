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
        $data = $apiservice->sendGetRequest($url, []);

        return $data;
    }
}
