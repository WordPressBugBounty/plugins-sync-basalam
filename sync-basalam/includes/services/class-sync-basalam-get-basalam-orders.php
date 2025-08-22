<?php
if (! defined('ABSPATH')) exit;
class Sync_basalam_Get_sync_basalam_Orders
{
    private $token;
    private $url;
    private $apiservice;

    function __construct()
    {
        $this->token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        $this->url = sync_basalam_Admin_Settings::get_static_settings("url_get_sync_basalam_orders");
        $this->apiservice = new sync_basalam_External_API_Service();
    }
    public function get_weekly_sync_basalam_orders()
    {
        $one_week_ago_timestamp = current_time('timestamp', true) - (7 * 24 * 60 * 60);
        $one_week_ago_iso = gmdate('c', $one_week_ago_timestamp);
        $headers = [
            'Authorization' => 'Bearer ' . $this->token
        ];

        $base_url = sync_basalam_Admin_Settings::get_static_settings("url_get_sync_basalam_orders");
        $url = $base_url . '?per_page=30' . '&created_at%5Bgte%5D=' . urlencode($one_week_ago_iso);

        $orders = $this->apiservice->send_get_request($url, $headers);
        return $orders;
    }
}
