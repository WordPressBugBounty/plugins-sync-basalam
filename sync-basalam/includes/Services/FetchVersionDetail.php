<?php

namespace SyncBasalam\Services;

use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class FetchVersionDetail
{
    private $apiService;

    public function __construct()
    {
        $this->apiService = new ApiServiceManager();
    }

    public function Fetch()
    {
        $url = 'https://api.hamsalam.ir/api/v1/wp-sites/version-detail?site_url=' . get_site_url() . '&current_version=' . syncBasalamPlugin()::VERSION;
        $response = $this->apiService->sendGetRequest($url);
        return $response;
    }

    public function checkForceUpdate()
    {
        $response = $this->Fetch();
        $data = json_decode($response['body'], true);
        
        if ($data['force_update'] && $data['force_update'] == true) {
            update_option('sync_basalam_force_update', true);
            return true;
        } else {
            delete_option('sync_basalam_force_update');
            return false;
        }
    }
}
