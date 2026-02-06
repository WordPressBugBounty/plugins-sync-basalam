<?php

namespace SyncBasalam\Services\Api;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Admin\Settings\SettingsManager;
use SyncBasalam\Queue\QueueManager;

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
            return [
                'body'          => null,
                'status_code'   => 408,
                'timeout_error' => true,
                'error'         => 'درخواست با تایم‌اوت مواجه شد.',
                'success'       => false
            ];
        }

        return [
            'body'        => null,
            'status_code' => 500,
            'error'       => $errorMessage,
            'error_code'  => $errorCode
        ];
    }

    private function isTimeoutError(string $errorCode, string $errorMessage): bool
    {
        // Common timeout error codes and messages
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

    private function handleHttpStatusCode(int $statusCode, $body): array
    {
        if (in_array($statusCode, [200, 201], true)) {
            return $this->successResponse($body, $statusCode);
        }

        if ($statusCode === 401) return $this->handleUnauthorized($body);

        // Handle timeout status codes
        if (in_array($statusCode, [408, 504], true)) {
            return [
                'body'          => $body,
                'status_code'   => $statusCode,
                'timeout_error' => true,
                'error'         => 'درخواست با تایم‌اوت مواجه شد.',
                'success'       => false
            ];
        }

        $errors = [
            400 => ['درخواست نامعتبر به url', 'Bad Request'],
            403 => ['دسترسی غیرمجاز به url', 'Forbidden'],
            404 => ['منبع مورد نظر یافت نشد در url', 'Not Found'],
            422 => ['خطا در پردازش داده‌ها در url', 'Unprocessable Entity'],
            429 => ['محدودیت تعداد درخواست‌ها برای url', 'Rate Limit Exceeded'],
            500 => ['خطای سمت سرور در url', 'Server Error'],
            502 => ['خطای سمت سرور در url', 'Server Error'],
            503 => ['خطای سمت سرور در url', 'Server Error'],
        ];

        if (isset($errors[$statusCode])) {
            [$logMessage, $title] = $errors[$statusCode];

            return $this->errorResponse($body, $statusCode, $title);
        }

        return $this->errorResponse($body, $statusCode, 'خطای غیرمنتظره');
    }


    private function handleUnauthorized($body): array
    {

        // $data = [
        //     SettingsConfig::TOKEN         => '',
        //     SettingsConfig::REFRESH_TOKEN => '',
        // ];
        // SettingsManager::updateSettings($data);

        // QueueManager::cancelAllTasksGroup('sync_basalam_plugin_create_product');
        // QueueManager::cancelAllTasksGroup('sync_basalam_plugin_update_product');
        // QueueManager::cancelAllTasksGroup('sync_basalam_plugin_connect_auto_product');

        return $this->errorResponse($body, 401, 'دسترسی غیرمجاز');
    }

    private function successResponse($body, int $statusCode): array
    {
        return [
            'body'        => $body,
            'status_code' => $statusCode,
            'success'     => true
        ];
    }


    private function errorResponse($body, int $statusCode, string $defaultMessage): array
    {
        return [
            'body'        => $body,
            'status_code' => $statusCode,
            'success'     => false,
            'error'       => $defaultMessage
        ];
    }

    public function handleTimeout(string $url): array
    {

        return [
            'data'          => null,
            'status_code'   => 500,
            'timeout_error' => true,
            'error'         => 'درخواست تایم‌اوت شد.',
            'success'       => false
        ];
    }
}
