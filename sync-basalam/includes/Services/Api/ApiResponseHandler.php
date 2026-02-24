<?php

namespace SyncBasalam\Services\Api;

use SyncBasalam\Jobs\Exceptions\RetryableException;
use SyncBasalam\Jobs\Exceptions\NonRetryableException;

defined('ABSPATH') || exit;

class ApiResponseHandler
{
    public function handle($response): array
    {
        if (is_wp_error($response)) {
            return $this->handleWpError($response);
        }

        $body = wp_remote_retrieve_body($response);

        $statusCode = wp_remote_retrieve_response_code($response);

        return $this->handleHttpStatusCode($statusCode, $body);
    }


    private function handleWpError(\WP_Error $response): array
    {
        $errorCode = $response->get_error_code();
        $errorMessage = $response->get_error_message();

        if ($this->isTimeoutError($errorCode, $errorMessage)) {
            throw RetryableException::apiTimeout('درخواست با تایم‌اوت مواجه شد: ' . $errorMessage);
        }

        if ($this->isNetworkError($errorCode, $errorMessage)) {
            throw RetryableException::networkError('خطای شبکه: ' . $errorMessage);
        }

        throw RetryableException::temporary('خطای موقت در درخواست: ' . $errorMessage);
    }

    private function isTimeoutError(string $errorCode, string $errorMessage): bool
    {
        $timeoutIndicators = [
            'http_request_failed',
            'timeout',
            'timed out',
            'operation timed out',
            'curl error 28',
            'connection timeout',
        ];

        $errorCodeLower = strtolower($errorCode);
        $errorMessageLower = strtolower($errorMessage);

        foreach ($timeoutIndicators as $indicator) {
            if (strpos($errorCodeLower, $indicator) !== false || strpos($errorMessageLower, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isNetworkError(string $errorCode, string $errorMessage): bool
    {
        $networkIndicators = [
            'network',
            'connection',
            'curl error',
            'dns',
            'socket',
            'ssl',
        ];

        $errorCodeLower = strtolower($errorCode);
        $errorMessageLower = strtolower($errorMessage);

        foreach ($networkIndicators as $indicator) {
            if (strpos($errorCodeLower, $indicator) !== false || strpos($errorMessageLower, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    private function handleHttpStatusCode(int $statusCode, $body): array
    {
        if (in_array($statusCode, [200, 201, 202], true)) {
            return $this->successResponse($body, $statusCode);
        }

        if (in_array($statusCode, [408, 504], true)) {
            throw RetryableException::apiTimeout('درخواست با تایم‌اوت مواجه شد');
        }

        if ($statusCode === 429) {
            throw RetryableException::rateLimit('محدودیت تعداد درخواست‌ها - لطفا کمی صبر کنید');
        }

        if (in_array($statusCode, [500, 502, 503], true)) {
            throw RetryableException::serverError('خطای سمت سرور (کد ' . $statusCode . ')');
        }

        if ($statusCode === 401) {
            throw NonRetryableException::unauthorized('دسترسی غیرمجاز - لطفا دوباره وارد شوید');
        }

        $clientErrors = [
            400 => 'درخواست نامعتبر',
            403 => 'دسترسی غیرمجاز',
            404 => 'منبع مورد نظر یافت نشد',
            422 => 'خطا در پردازش داده‌ها',
        ];

        if (isset($clientErrors[$statusCode])) {
            $errorMessage = $this->extractErrorMessageFromBody($body) ?: $clientErrors[$statusCode];
            throw NonRetryableException::permanent($errorMessage . ' (کد ' . $statusCode . ')');
        }

        // Unknown error - treat as retryable
        throw RetryableException::temporary('خطای غیرمنتظره (کد ' . $statusCode . ')');
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
            if (isset($body['messages'][0]['message'])) return $body['messages'][0]['message'];
            if (isset($body['message'])) return $body['message'];

            if (isset($body['error'])) return $body['error'];
        }

        return null;
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
        throw RetryableException::apiTimeout('درخواست تایم‌اوت شد');
    }
}
