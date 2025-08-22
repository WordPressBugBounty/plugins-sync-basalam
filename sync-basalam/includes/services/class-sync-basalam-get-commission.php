<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Get_Commission
{
    static function get_commission_basalam($category_ids)
    {
        $apiservice = new sync_basalam_External_API_Service;
        $queryParams = [];

        if (isset($category_ids[0]) && is_numeric($category_ids[0])) {
            $queryParams[] = "product.category.level1=" . intval($category_ids[2]);
        }
        if (isset($category_ids[1]) && is_numeric($category_ids[1])) {
            $queryParams[] = "product.category.level2=" . intval($category_ids[1]);
        }
        if (isset($category_ids[2]) && is_numeric($category_ids[2])) {
            $queryParams[] = "product.category.level3=" . intval($category_ids[0]);
        }

        if (empty($queryParams)) {
            return false;
        }

        $url = "https://core.basalam.com/api_v2/commission/get_percent?" . implode("&", $queryParams);
        $sync_basalam_token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $result = $apiservice->send_get_request($url, [
            'Authorization' => 'Bearer ' . $sync_basalam_token
        ]);
        $commission_percent = $result['data']['commission_data']['commission_percent'];
        $have_max_amount = $result['data']['commission_data']['commission_ceil'];
        if ($have_max_amount && $have_max_amount['type'] && $have_max_amount['type'] == 'fix_amount') {
            $max_amount = $have_max_amount['value'];
            return [$commission_percent, $max_amount];
        } else {
            return $commission_percent;
        }
        return $commission_percent;
    }
}
