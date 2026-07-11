<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

class FinancialManagementService
{
    private const BALANCE_URL = 'https://accounting.basalam.com/financial/v1/client/transaction/balance';
    private const BALANCE_SETTLEMENT_URL = 'https://accounting.basalam.com/financial/v1/client/balance-settlement';
    private const BALANCE_SETTLEMENT_POST_URL = 'https://accounting.basalam.com/financial/v2/client/balance-settlement';
    private const BANK_ACCOUNTS_URL = 'https://identity.basalam.com/v1/users/bank-accounts';
    private const ACTIVE_STATUS_FILTER_TYPE = 2;
    private const ACTIVE_STATUSES = [4, 6, 8, 9, 10];
    private const DEFAULT_PER_PAGE = 20;

    public function getDefaultPerPage(): int
    {
        return self::DEFAULT_PER_PAGE;
    }

    public function getBalanceUrl(): string
    {
        return self::BALANCE_URL;
    }

    public function getActiveSettlementsUrl(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): string
    {
        return $this->buildBalanceSettlementUrl($page, $perPage, self::ACTIVE_STATUSES, true);
    }

    public function getHistoryUrl(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): string
    {
        return $this->buildBalanceSettlementUrl($page, $perPage);
    }

    public function getBalance(): array
    {
        return $this->requestJson(self::BALANCE_URL);
    }

    public function getActiveSettlements(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        return $this->requestJson($this->getActiveSettlementsUrl($page, $perPage));
    }

    public function getSettlementHistory(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        return $this->requestJson($this->getHistoryUrl($page, $perPage));
    }

    public function getBankAccounts(): array
    {
        return $this->requestJson(self::BANK_ACCOUNTS_URL);
    }

    public function createSettlement(int $amount, int $method, ?int $investmentOptionId = null, ?int $bankAccountId = null): array
    {
        $body = [
            'amount' => $amount,
            'method' => $method,
        ];

        if ($investmentOptionId !== null) {
            $body['investment_option_id'] = $investmentOptionId;
        }

        if ($bankAccountId !== null) {
            $body['bank_account_id'] = $bankAccountId;
        }

        return $this->postJson(self::BALANCE_SETTLEMENT_POST_URL, $body);
    }

    public function getToken(): string
    {
        if (function_exists('syncBasalamSettings') && class_exists(SettingsConfig::class)) {
            $token = syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);
            return is_string($token) ? trim($token) : '';
        }

        $settings = get_option('sync_basalam_settings', []);
        if (!is_array($settings)) {
            return '';
        }

        $token = $settings['token'] ?? '';
        return is_string($token) ? trim($token) : '';
    }

    private function requestJson(string $url): array
    {
        return $this->sendJsonRequest('GET', $url);
    }

    private function postJson(string $url, array $body): array
    {
        return $this->sendJsonRequest('POST', $url, $body);
    }

    private function sendJsonRequest(string $method, string $url, ?array $body = null): array
    {
        $token = $this->getToken();
        if ($token === '') {
            return [
                'success' => false,
                'status_code' => 0,
                'message' => 'توکن باسلام یافت نشد. ابتدا اتصال باسلام را در افزونه اصلی ووسلام انجام دهید.',
                'data' => [],
                'raw_body' => '',
            ];
        }

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'user-agent'    => 'Wp-Basalam',
                'referer'       => get_site_url(),
            ],
        ];


        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'status_code' => 0,
                'message' => $response->get_error_message(),
                'data' => [],
                'raw_body' => '',
            ];
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = $this->decodeJsonResponse($body);

        if ($statusCode < 200 || $statusCode > 299) {
            return [
                'success' => false,
                'status_code' => $statusCode,
                'message' => $this->extractErrorMessage($decoded, $body, $statusCode),
                'data' => is_array($decoded) ? $decoded : [],
                'raw_body' => $body,
            ];
        }

        if (!is_array($decoded)) {
            return [
                'success' => false,
                'status_code' => $statusCode,
                'message' => 'پاسخ API قابل تبدیل به JSON نیست.',
                'data' => [],
                'raw_body' => $body,
            ];
        }

        return [
            'success' => true,
            'status_code' => $statusCode,
            'message' => '',
            'data' => $decoded,
            'raw_body' => $body,
        ];
    }

    private function buildBalanceSettlementUrl(int $page, int $perPage, array $statuses = [], bool $withStatusFilter = false): string
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $params = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($withStatusFilter) {
            $params['status_filter_type'] = self::ACTIVE_STATUS_FILTER_TYPE;
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        foreach ($statuses as $status) {
            $query .= '&status=' . rawurlencode((string) $status);
        }

        return self::BALANCE_SETTLEMENT_URL . '?' . $query;
    }

    private function decodeJsonResponse(string $body): ?array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($trimmed, $start, ($end - $start + 1));
            $decodedCandidate = json_decode($candidate, true);
            if (is_array($decodedCandidate) && json_last_error() === JSON_ERROR_NONE) {
                return $decodedCandidate;
            }
        }

        return null;
    }

    private function extractErrorMessage($decodedBody, string $rawBody, int $statusCode): string
    {
        if (is_array($decodedBody)) {
            if (!empty($decodedBody['message']) && is_string($decodedBody['message'])) {
                return $decodedBody['message'] . ' (کد ' . $statusCode . ')';
            }

            if (!empty($decodedBody['error']) && is_string($decodedBody['error'])) {
                return $decodedBody['error'] . ' (کد ' . $statusCode . ')';
            }

            if (!empty($decodedBody['errors'][0]['message']) && is_string($decodedBody['errors'][0]['message'])) {
                return $decodedBody['errors'][0]['message'] . ' (کد ' . $statusCode . ')';
            }
        }

        if ($rawBody !== '') {
            return 'خطای API با کد ' . $statusCode . ': ' . wp_strip_all_tags($rawBody);
        }

        return 'خطای API با کد ' . $statusCode;
    }
}
