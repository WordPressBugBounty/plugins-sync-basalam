<?php

namespace SyncBasalam\Services;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\ApiServiceManager;
use SyncBasalam\Services\ForceUpdateGate;

defined('ABSPATH') || exit;

class FetchVersionDetail
{
    private $apiService;
    private $version;

    public function __construct($version)
    {
        $this->apiService = syncBasalamContainer()->get(ApiServiceManager::class);
        $this->version = $version;
    }

    public function Fetch()
    {
        $url = Endpoints::HAMSALAM_VERSION_DETAIL . '?site_url=' . get_site_url() . '&current_version=' . $this->version;
        try {
            $response = $this->apiService->get($url);
            return $response;
        } catch (\Throwable $e) {
            Logger::error('خطا در دریافت اطلاعات نسخه: ' . $e->getMessage());
            return null;
        }
    }

    public function checkForceUpdate()
    {
        if (wp_get_environment_type() === 'local') {
            delete_option('sync_basalam_force_update');
            return false;
        }

        try {
            $response = $this->Fetch();

            if (!is_array($response) || !isset($response['body'])) return false;

            $data = json_decode($response['body'], true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || !array_key_exists('force_update', $data)) {
                return false;
            }

            if (ForceUpdateGate::shouldHonorRemoteFlag(
                $data['force_update'],
                $this->version,
                isset($data['latest_version']) ? (string) $data['latest_version'] : null
            )) {
                update_option('sync_basalam_force_update', true);
                return true;
            }

            delete_option('sync_basalam_force_update');
            return false;
        } catch (\Throwable $e) {
            Logger::error('خطا در بررسی force update: ' . $e->getMessage());
            return false;
        }
    }
}
