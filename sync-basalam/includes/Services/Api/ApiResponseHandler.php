<?php

namespace SyncBasalam\Services\Api;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;

defined('ABSPATH') || exit;

class ApiResponseHandler
{
    public function handle($response, $url = ''): array
    {
        if (is_wp_error($response)) {
            return $this->handleWpError($response, $url);
        }

        $body = wp_remote_retrieve_body($response);

        $statusCodeRaw = wp_remote_retrieve_response_code($response);
        $statusCode    = is_numeric($statusCodeRaw) ? (int) $statusCodeRaw : 0;

        return $this->handleHttpStatusCode($statusCode, $body, $url);
    }


    private function handleWpError(\WP_Error $response, string $url = ''): array
    {
        $errorMessage = $response->get_error_message();
        $category = RequestStatusTracker::recordWpError($response, $url);
        $reason = RequestStatusTracker::describeCategoryFa($category);

        if ($category === 'blocked_http') {
            throw new BlockedHttpRequestException(esc_html($reason . ' | جزئیات فنی: ' . $errorMessage));
        }

        if ($category === 'timeout') {
            throw RetryableException::apiTimeout(esc_html($reason . ' | جزئیات فنی: ' . $errorMessage));
        }

        if (in_array($category, ['dns_error', 'ssl_error', 'connection_error', 'network_error'], true)) {
            throw RetryableException::networkError(esc_html($reason . ' | جزئیات فنی: ' . $errorMessage));
        }

        throw RetryableException::temporary(esc_html($reason . ' | جزئیات فنی: ' . $errorMessage));
    }

    private function handleHttpStatusCode(int $statusCode, $body, $url): array
    {
        RequestStatusTracker::recordHttpStatus($statusCode, $url);

        if ($statusCode === 0) {
            throw new BlockedHttpRequestException(esc_html(RequestStatusTracker::describeHttpStatusFa(0)));
        }

        if (in_array($statusCode, [200, 201, 202], true)) {
            return $this->successResponse($body, $statusCode);
        }

        if (in_array($statusCode, [408, 504], true)) {
            throw RetryableException::apiTimeout(esc_html(RequestStatusTracker::describeHttpStatusFa($statusCode)));
        }

        if ($statusCode === 429) {
            throw RetryableException::rateLimit(esc_html(RequestStatusTracker::describeHttpStatusFa(429)));
        }

        if (in_array($statusCode, [500, 502, 503], true)) {
            throw RetryableException::serverError(esc_html(RequestStatusTracker::describeHttpStatusFa($statusCode)));
        }

        if ($statusCode === 401) {
            if ($this->isBasalamDomain($url)) {
                SettingsManager::updateSettings([
                    SettingsConfig::TOKEN         => null,
                    SettingsConfig::REFRESH_TOKEN => null,
                ]);
            }
            throw NonRetryableException::unauthorized(esc_html(RequestStatusTracker::describeHttpStatusFa(401)));
        }

        $clientErrors = [
            400 => 'درخواست نامعتبر',
            403 => 'دسترسی غیرمجاز',
            404 => 'منبع مورد نظر یافت نشد',
            422 => 'خطا در پردازش داده‌ها',
        ];

        if (isset($clientErrors[$statusCode])) {
            $bodyMessage = $this->extractErrorMessageFromBody($body);
            $base = $bodyMessage ?: $clientErrors[$statusCode];
            $reason = RequestStatusTracker::describeHttpStatusFa($statusCode);
            throw NonRetryableException::permanent(esc_html($base . $reason));
        }

        // Unknown error - treat as retryable
        throw RetryableException::temporary(esc_html(RequestStatusTracker::describeHttpStatusFa($statusCode)));
    }

    private function extractErrorMessageFromBody($body): ?string
    {
        if (empty($body)) return null;

        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        if (is_array($body)) {
            if (!empty($body['errors'][0]['message'])) return $body['errors'][0]['message'];
            if (isset($body['messages'][0]['message'])) return $body['messages'][0]['message'];
            if (isset($body['message'])) return $body['message'];
            if (isset($body['error'])) return $body['error'];
        }

        return null;
    }

    private function isBasalamDomain(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') return false;


        return $host === 'basalam.com' || substr(strtolower($host), -strlen('.basalam.com')) === '.basalam.com';
    }

    private function successResponse($body, int $statusCode): array
    {
        return [
            'body'        => $body,
            'status_code' => $statusCode,
            'success'     => true
        ];
    }

    public function handleTimeout(string $url): array
    {
        RequestStatusTracker::recordCategory('timeout', ['url' => $url]);
        throw RetryableException::apiTimeout(esc_html(RequestStatusTracker::describeCategoryFa('timeout')));
    }
}
