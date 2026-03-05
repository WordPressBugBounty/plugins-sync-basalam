<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class VendorInfoService
{
    private $apiService;
    private $basalamToken;
    private $basalamVendorId;

    public function __construct()
    {
        $this->apiService = syncBasalamContainer()->get(ApiServiceManager::class);
        $this->basalamToken = Settings::getSettings(SettingsConfig::TOKEN);
        $this->basalamVendorId = Settings::getSettings(SettingsConfig::VENDOR_ID);
    }

    public function FetchVendorInfo()
    {
        if (!$this->basalamToken || !$this->basalamVendorId) {
            update_option('sync_basalam_info', null);
            return null;
        }

        try {
            $apiUrl = sprintf(Endpoints::VENDOR_INFO, $this->basalamVendorId);
            $FetchVendorInfo = $this->apiService->get($apiUrl, ['Authorization' => 'Bearer ' . $this->basalamToken]);

            if (!is_array($FetchVendorInfo) || !isset($FetchVendorInfo['body'])) {
                update_option('sync_basalam_info', null);
                return null;
            }

            $vendorInfo = json_decode($FetchVendorInfo['body'], true);
            if (!is_array($vendorInfo)) {
                update_option('sync_basalam_info', null);
                return null;
            }

            update_option('sync_basalam_info', $vendorInfo);
            return $vendorInfo;
        } catch (\Exception $e) {
            Logger::error('خطا در دریافت اطلاعات فروشنده: ' . $e->getMessage());
            update_option('sync_basalam_info', null);
            return null;
        }
    }

    public function getVendorInfo()
    {
        $vendorInfo = get_option('sync_basalam_info');

        if (!$vendorInfo) return $this->FetchVendorInfo();

        return $vendorInfo;
    }
}
