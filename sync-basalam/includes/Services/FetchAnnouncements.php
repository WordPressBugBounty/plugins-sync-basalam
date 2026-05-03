<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;
use SyncBasalam\Services\Hamsalam\FetchHamsalamToken;

defined('ABSPATH') || exit;

class FetchAnnouncements
{
    private string $url = Endpoints::HAMSALAM_ANNOUNCEMENTS;
    private $tokenFetcher;
    private ?string $hamsalamToken;

    private const MAX_RETRY_ATTEMPTS = 2;

    public function __construct($tokenFetcher = null)
    {
        $settings = syncBasalamSettings()->getSettings();

        $this->hamsalamToken = $this->normalizeToken($settings[SettingsConfig::HAMSALAM_TOKEN] ?? null);
        $this->tokenFetcher = $tokenFetcher ?: new FetchHamsalamToken();
    }

    public function fetch(int $page = 1, int $perPage = 5): ?array
    {
        $queryArgs = [
            'page'             => $page,
            'per_page'         => $perPage,
            'filters[type]'    => 'woosalam',
        ];

        $url = $this->url . '?' . http_build_query($queryArgs);
        $attempt = 0;

        while ($attempt < self::MAX_RETRY_ATTEMPTS) {
            $attempt++;

            try {
                $apiService = syncBasalamContainer()->get(ApiServiceManager::class);
                $response = $apiService->get($url, $this->buildHeaders());
            } catch (NonRetryableException $e) {
                if ((int) $e->getCode() === 401 && $attempt < self::MAX_RETRY_ATTEMPTS && $this->refreshHamsalamToken()) {
                    continue;
                }

                return null;
            } catch (\Exception $e) {
                return null;
            }

            if (($response['status_code'] ?? 0) === 401 && $attempt < self::MAX_RETRY_ATTEMPTS && $this->refreshHamsalamToken()) {
                continue;
            }

            return $this->parseResponse($response, $page, $perPage);
        }

        return null;
    }

    private function buildHeaders(): array
    {
        $headers = [];

        $token = $this->getHamsalamToken();
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    private function getHamsalamToken(): ?string
    {
        if ($this->hamsalamToken !== null) return $this->hamsalamToken;

        $this->refreshHamsalamToken();

        return $this->hamsalamToken;
    }

    private function refreshHamsalamToken(): bool
    {
        $token = $this->tokenFetcher->fetch();
        $this->hamsalamToken = $this->normalizeToken($token);

        return $this->hamsalamToken !== null;
    }

    private function normalizeToken($token): ?string
    {
        if (!is_string($token)) return null;

        $token = trim($token);

        if ($token === '' || str_starts_with($token, 'خطا')) return null;

        return $token;
    }


    private function parseResponse(array $response, int $page, int $perPage): ?array
    {
        if (empty($response['body'])) {
            return null;
        }

        $body = json_decode($response['body'], true);

        if (!is_array($body) || empty($body['data'])) {
            return null;
        }

        return [
            'data'       => $body['data'],
            'total_page' => $body['total_page'] ?? 1,
            'page'       => $body['page'] ?? $page,
            'per_page'   => $body['per_page'] ?? $perPage,
        ];
    }
}
