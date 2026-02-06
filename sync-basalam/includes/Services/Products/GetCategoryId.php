<?php

namespace SyncBasalam\Services\Products;

use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;
class GetCategoryId
{
    public static function getCategoryIdFromBasalam($productTitle, $mode = 'all')
    {
        $apiservice = new ApiServiceManager();

        $url = "https://categorydetection.basalam.com/category_detection/api_v1.0/predict/?title=" . $productTitle;

        $result = $apiservice->sendGetRequest($url, []);

        $decodedBody = json_decode($result['body'], true);

        if ($mode == 'all') {
            if (isset($decodedBody['result']) && count($decodedBody['result']) > 0) {
                $categories = [];

                foreach ($decodedBody['result'] as $category) {
                    $catIds = [];

                    self::extractCategoryIds([$category], $catIds);

                    $categoryData = [
                        'cat_id'    => array_reverse($catIds),
                        'cat_title' => self::getCombinedTitles($category['cat_parent'], $category['cat_title']),
                    ];
                    $categories[] = $categoryData;
                }

                return $categories;
            }
        } else {
            if (isset($decodedBody['result']) && count($decodedBody['result']) > 0) {
                $categoryIds = [];

                self::extractCategoryIds([$decodedBody['result'][0]], $categoryIds);

                return array_reverse($categoryIds);
            }
        }

        return false;
    }

    public static function extractCategoryIds($categories, &$categoryIds)
    {
        foreach ($categories as $category) {
            $categoryIds[] = $category['cat_id'];

            if (isset($category['cat_parent']) && $category['cat_parent'] !== null) {
                self::extractCategoryIds([$category['cat_parent']], $categoryIds);
            }
        }
    }

    public static function getCombinedTitles($categoryParent, $currentTitle)
    {
        if ($categoryParent === null) {
            return $currentTitle;
        }

        return self::getCombinedTitles($categoryParent['cat_parent'], $categoryParent['cat_title']) . ' > ' . $currentTitle;
    }
}
