<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Get_Plugin_Data
{
    private $apiservice;
    private $url;
    public function __construct()
    {
        $this->apiservice = new sync_basalam_External_API_Service;
        $this->url = 'https://integration.basalam.com/api/v1/woo-plugin/last-version';
    }
    public function get_plugin_data()
    {
        $data = $this->apiservice->send_get_request($this->url);
        return $data;
    }
}
