<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Get_Category_Attr
{
    static function get_attr($category_id)
    {
        $url = "https://core.basalam.com/api_v2/category/$category_id/attributes?exclude_multi_selects=true";
        $apiservice  = new sync_basalam_External_API_Service();
        $data = $apiservice->send_get_request($url, []);
        return $data;
    }
}
