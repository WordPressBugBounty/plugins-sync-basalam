<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings;

defined('ABSPATH') || exit;

class VendorInfoService
{
    private ApiServiceManager $apiService;
    private $basalamToken;
    private $basalamVendorId;

    public function __construct()
    {
        $this->apiService = new ApiServiceManager();
        $this->basalamToken = Settings::getSettings(SettingsConfig::TOKEN);
        $this->basalamVendorId = Settings::getSettings(SettingsConfig::VENDOR_ID);
    }

    public function FetchVendorInfo()
    {
        if (!$this->basalamToken || !$this->basalamVendorId) {
            update_option('sync_basalam_info', null);
            return null;
        };
        $apiUrl = "https://openapi.basalam.com/v1/vendors/" . $this->basalamVendorId;
        $FetchVendorInfo = $this->apiService->sendGetRequest($apiUrl, ['Authorization' => 'Bearer ' . $this->basalamToken]);
        $vendorInfo = json_decode($FetchVendorInfo['body'], true);
        update_option('sync_basalam_info', $vendorInfo);
        return $vendorInfo;
    }

    public function getVendorInfo()
    {
        $vendorInfo = get_option('sync_basalam_info');

        if (!$vendorInfo) return $this->FetchVendorInfo();

        return $vendorInfo;
    }
}
