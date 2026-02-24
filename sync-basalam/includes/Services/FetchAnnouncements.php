<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class FetchAnnouncements
{
    private string $url = 'https://api.hamsalam.ir/api/v1/announcements';

    public function fetch(int $page = 1, int $perPage = 5): array
    {
        $settings = syncBasalamSettings()->getSettings();
        $hamsalamToken = $settings[SettingsConfig::HAMSALAM_TOKEN] ?? '';

        $queryArgs = [
            'page'             => $page,
            'per_page'         => $perPage,
            'filters[type]'    => 'woosalam',
        ];

        $url = $this->url . '?' . http_build_query($queryArgs);

        $headers = [];
        if (!empty($hamsalamToken)) {
            $headers['Authorization'] = 'Bearer ' . $hamsalamToken;
        }

        $emptyResult = ['data' => [], 'total_page' => 1, 'page' => $page, 'per_page' => $perPage];

        try {
            $apiService = new ApiServiceManager();
            $response = $apiService->sendGetRequest($url, $headers);
        } catch (\Exception $e) {
            error_log('Error fetching announcements: ' . $e->getMessage());
            return $emptyResult;
        }
        if (empty($response['body'])) {
            return $emptyResult;
        }

        $body = json_decode($response['body'], true);

        if (!is_array($body) || empty($body['data'])) {
            return [
                'data'       => [],
                'total_page' => 1,
                'page'       => $page,
                'per_page'   => $perPage,
            ];
        }

        return [
            'data'       => $body['data'],
            'total_page' => $body['total_page'] ?? 1,
            'page'       => $body['page'] ?? $page,
            'per_page'   => $body['per_page'] ?? $perPage,
        ];
    }
}
