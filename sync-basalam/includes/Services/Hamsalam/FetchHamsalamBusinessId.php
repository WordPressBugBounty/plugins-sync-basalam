<?php

namespace SyncBasalam\Services\Hamsalam;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class FetchHamsalamBusinessId
{
    private $url;
    private $settings;
    private $hamsalmToken;

    public function __construct()
    {
        $this->settings = syncBasalamSettings()->getSettings();
        $this->url = "https://api.hamsalam.ir/api/v1/all-businesses";
        $this->hamsalmToken = SettingsManager::getSettings(SettingsConfig::HAMSALAM_TOKEN);
    }

    public function fetch()
    {
        try {
            $apiService = new ApiServiceManager();

            $header = ['Authorization' => 'Bearer ' . $this->hamsalmToken];

            $response = $apiService->sendGetRequest($this->url, $header);

            if (!$response || !isset($response['body'])) return ('خطا در دریافت اطلاعات از همسلام');

            $businesses = json_decode($response['body'], true);

            $domain = get_site_url();
            $vendorId = $this->settings[SettingsConfig::VENDOR_ID];
            $businessId = null;

            if (!isset($businesses['data']) || !is_array($businesses['data'])) return null;

            foreach ($businesses['data'] as $business) {
                if ($business['platform'] == 'wordpress'  && $domain == $business['domain'] && $vendorId == $business['vendor_id']) {
                    $businessId = $business['id'];
                    break;
                }
            }
            if ($businessId) {
                $data = [SettingsConfig::HAMSALAM_BUSINESS_ID => $businessId];

                SettingsManager::updateSettings($data);
                return $businessId;
            }

            return null;
        } catch (\Exception) {
            return null;
        }
    }
}
