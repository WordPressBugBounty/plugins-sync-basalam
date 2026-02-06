<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;
class FetchCommission
{
    public static function fetchCategoryCommission($categoryIds)
    {
        $apiservice = new ApiServiceManager();
        $queryParams = [];

        if (isset($categoryIds[0]) && is_numeric($categoryIds[0])) {
            $queryParams[] = "product.category.level1=" . intval($categoryIds[0]);
        }
        if (isset($categoryIds[1]) && is_numeric($categoryIds[1])) {
            $queryParams[] = "product.category.level2=" . intval($categoryIds[1]);
        }
        if (isset($categoryIds[2]) && is_numeric($categoryIds[2])) {
            $queryParams[] = "product.category.level3=" . intval($categoryIds[2]);
        }

        if (empty($queryParams)) return false;

        $url = "https://core.basalam.com/api_v2/commission/get_percent?" . implode("&", $queryParams);

        $result = $apiservice->sendGetRequest($url);

        $decodedBody = json_decode($result['body'], true);

        $commissionPercent = $decodedBody['commission_data']['commission_percent'];

        if ($commissionPercent) return $commissionPercent;
        return 0;
    }
}
