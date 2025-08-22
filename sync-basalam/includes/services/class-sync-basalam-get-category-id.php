<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Get_Category_id
{
    static function get_category_id_from_basalam($productTitle, $mode = 'single')
    {
        $apiservice = new sync_basalam_External_API_Service;
        $url = "https://categorydetection.basalam.com/category_detection/api_v1.0/predict/?title=" . $productTitle;
        $result = $apiservice->send_get_request($url, []);

        if ($mode != 'single') {
            if ($mode == 'all') {
                if (isset($result['data']['result']) && count($result['data']['result']) > 0) {
                    $categories = [];

                    foreach ($result['data']['result'] as $category) {
                        $cat_ids = [];
                        self::extract_category_ids([$category], $cat_ids);
                        $category_data = [
                            'cat_id' => $cat_ids,
                            'cat_title' => self::get_combined_titles($category['cat_parent'], $category['cat_title'])
                        ];
                        $categories[] = $category_data;
                    }
                    return $categories;
                }
            }
            if (isset($result['data']['result']) && count($result['data']['result']) > 0) {
                $category_ids = [];

                self::extract_category_ids([$result['data']['result'][0]], $category_ids);

                return $category_ids;
            }
        }
        if (isset($result['data']['result'][0]['cat_id'])) {
            return $result['data']['result'][0]['cat_id'];
        }

        return false;
    }

    static function extract_category_ids($categories, &$category_ids)
    {
        foreach ($categories as $category) {
            $category_ids[] = $category['cat_id'];

            if (isset($category['cat_parent']) && $category['cat_parent'] !== null) {
                self::extract_category_ids([$category['cat_parent']], $category_ids);
            }
        }
    }
    static function get_combined_titles($category_parent, $current_title)
    {
        if ($category_parent === null) {
            return $current_title;
        }

        return self::get_combined_titles($category_parent['cat_parent'], $category_parent['cat_title']) . ' > ' . $current_title;
    }
}
