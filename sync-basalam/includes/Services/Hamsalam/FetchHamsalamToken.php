<?php

namespace SyncBasalam\Services\Hamsalam;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class FetchHamsalamToken
{
    private $url;
    private $basalamToken;

    public function __construct()
    {
        $settings = syncBasalamSettings()->getSettings();
        $this->url = "https://api.hamsalam.ir/api/v1/auth/basalam/get-token";
        $this->basalamToken = $settings[SettingsConfig::TOKEN];
    }

    public function fetch()
    {
        try {
            $apiService = new ApiServiceManager();

            $body = ['basalam_token' => $this->basalamToken];

            $response = $apiService->sendPostRequest($this->url, $body);

            if (!$response || !isset($response['body'])) return ('خطا در دریافت توکن همسلام');

            $body = json_decode($response['body'], true);

            if (!$response || !isset($body['access_token'])) return ('خطا در دریافت access_token همسلام');

            $data = [
                SettingsConfig::HAMSALAM_TOKEN => $body['access_token'],
            ];

            SettingsManager::updateSettings($data);
            return $body['access_token'];
        } catch (\Exception $e) {
            return 'خطا در دریافت توکن همسلام: ' . $e->getMessage();
        }
    }
}
