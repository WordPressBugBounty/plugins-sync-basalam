<?php

namespace SyncBasalam\Services\Api;

use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class FileUploadApiService
{
    public function upload(string $url, string $filePath, array $data = [], array $headers = [], array $options = []): array
    {
        $fileValidation = $this->validateFile($filePath, $options);
        if (!$fileValidation['valid']) {
            return [
                'body' => null,
                'status_code' => 400,
                'error' => $fileValidation['message']
            ];
        }

        if (function_exists('curl_init') && class_exists('CURLFile')) {
            return $this->uploadWithCurl($url, $filePath, $data, $headers, $options);
        }

        $fileSize = filesize($filePath);
        if ($fileSize !== false && $fileSize > 10 * 1024 * 1024) {
            return [
                'body' => null,
                'status_code' => 500,
                'error' => 'برای آپلود فایل‌های بزرگ، افزونه cURL در PHP باید فعال باشد.',
            ];
        }

        $boundary = wp_generate_password(24);
        $payload = $this->makePayloadupload($data, $filePath, $boundary);
        $headers = array_merge($headers, ['content-type' => 'multipart/form-data; boundary=' . $boundary]);
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 120;

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $payload,
            'timeout' => max(10, $timeout),
        ]);

        return $this->handleResponse($response, $url);
    }

    private function uploadWithCurl(string $url, string $filePath, array $data, array $headers, array $options): array
    {
        $filetypeInfo = wp_check_filetype($filePath);
        $mimeType = $filetypeInfo['type'] ?? 'application/octet-stream';
        $fields = $data;
        $fields['file'] = new \CURLFile($filePath, $mimeType, basename($filePath));
        $headerLines = [];

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'content-type') continue;
            $headerLines[] = $name . ': ' . $value;
        }

        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 120;
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => max(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($body === false) {
            $message = curl_error($curl);
            curl_close($curl);

            Logger::error('خطا در ارسال فایل با cURL: ' . $message, ['url' => $url]);

            return [
                'body' => null,
                'status_code' => 500,
                'error' => $message ?: 'خطای نامشخص در ارسال فایل',
            ];
        }

        curl_close($curl);
        RequestStatusTracker::recordHttpStatus($statusCode, $url);
        $decodedBody = json_decode($body, true);

        if ($statusCode >= 400) {
            $reason = RequestStatusTracker::describeHttpStatusFa($statusCode);
            Logger::error('آپلود فایل به باسلام ناموفق بود. ' . $reason, [
                'url' => $url,
                'status_code' => $statusCode,
                'reason' => $reason,
                'response_body' => $decodedBody ?? $body,
            ]);
        }

        return [
            'body' => $decodedBody,
            'status_code' => $statusCode,
            'error' => $statusCode >= 400 ? $this->extractErrorMessage($decodedBody, $body) : null,
        ];
    }

    private function validateFile(string $filePath, array $options = []): array
    {
        if (!file_exists($filePath)) {
            return ['valid' => false, 'message' => 'فایل در دسترس نیست' . $filePath];
        }

        $maxSize = isset($options['max_size']) ? (int) $options['max_size'] : 5 * 1024 * 1024;

        $fileSize = filesize($filePath);
        if ($fileSize > $maxSize) {
            return ['valid' => false, 'message' => 'حجم فایل بیش از حد مجاز است : ' . $filePath];
        }

        $allowedExtensions = isset($options['allowed_extensions']) && is_array($options['allowed_extensions'])
            ? $options['allowed_extensions']
            : ['jpg', 'png', 'webp', 'bmp', 'jfif', 'jpeg', 'avif'];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            return ['valid' => false, 'message' => 'فرمت فایل معتبر نیست ، فرمت های معتبر : ' . implode(', ', $allowedExtensions)];
        }

        return ['valid' => true, 'message' => 'فایل معتبر است'];
    }

    private function handleResponse($response, string $url = ''): array
    {
        if (is_wp_error($response)) {
            $category = RequestStatusTracker::recordWpError($response, $url);
            $errorData = $response->get_error_data();
            $errorCodes = $response->get_error_codes();
            $errorMessages = [];

            foreach ($errorCodes as $code) {
                $messages = $response->get_error_messages($code);
                if (!empty($messages)) $errorMessages[$code] = $messages;
            }

            Logger::error('خطا در فرایند آپلود فایل در باسلام. ' . RequestStatusTracker::describeCategoryFa($category), [
                'url' => $url,
                'category' => $category,
                'reason' => RequestStatusTracker::describeCategoryFa($category),
                'error_codes' => $errorCodes,
                'error_messages' => $errorMessages,
                'error_data' => $errorData,
            ]);

            return [
                'body' => null,
                'status_code' => $this->resolveWpErrorStatusCode($response),
                'error' => $this->resolveWpErrorMessage($response)
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $statusCode = wp_remote_retrieve_response_code($response);
        RequestStatusTracker::recordHttpStatus((int) $statusCode, $url);

        $decodedBody = json_decode($body, true);

        if ((int) $statusCode >= 400) {
            $reason = RequestStatusTracker::describeHttpStatusFa((int) $statusCode);
            Logger::error('آپلود فایل به باسلام ناموفق بود. ' . $reason, [
                'url' => $url,
                'status_code' => (int) $statusCode,
                'reason' => $reason,
                'response_body' => $decodedBody ?? $body,
            ]);
        }

        return [
            'body' => $decodedBody,
            'status_code' => $statusCode,
            'error' => (int) $statusCode >= 400 ? $this->extractErrorMessage($decodedBody, $body) : null
        ];
    }

    private function makePayloadupload(array $data, string $localFile, string $boundary): string
    {
        $payload = '';
        $eol = "\r\n";

        foreach ($data as $name => $value) {
            $payload .= '--' . $boundary . $eol;
            $payload .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $payload .= $value . $eol;
        }

        if ($localFile) {
            $filename = basename($localFile);
            $fileContent = file_get_contents($localFile);

            if ($fileContent === false) {
                Logger::error('خطا در خواندن فایل با استفاده از file_get_contents: ' . $localFile);
                return '';
            }

            $filetypeInfo = wp_check_filetype($localFile);
            $mimeType = $filetypeInfo['type'] ?? 'application/octet-stream';

            $payload .= '--' . $boundary . $eol;
            $payload .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
            $payload .= 'Content-Type: ' . $mimeType . $eol;
            $payload .= 'Content-Transfer-Encoding: binary' . $eol . $eol;
            $payload .= $fileContent . $eol;
        }

        $payload .= '--' . $boundary . '--' . $eol;

        return $payload;
    }

    private function resolveWpErrorMessage(\WP_Error $response): string
    {
        $errorData = $response->get_error_data();
        $host = is_array($errorData) ? ($errorData['host'] ?? '') : '';
        $message = $response->get_error_message();

        if ($response->get_error_code() === 'http_request_blocked') {
            return 'درخواست آپلود تصویر به باسلام مسدود شد' . ($host ? ': ' . $host : '');
        }

        return $message ?: 'خطای نامشخص در آپلود فایل';
    }

    private function resolveWpErrorStatusCode(\WP_Error $response): int
    {
        $errorData = $response->get_error_data();

        if (is_array($errorData) && isset($errorData['status']) && is_numeric($errorData['status'])) {
            return (int) $errorData['status'];
        }

        return 500;
    }

    private function extractErrorMessage($decodedBody, string $rawBody): string
    {
        if (is_array($decodedBody)) {
            if (!empty($decodedBody['messages'][0]['message'])) return (string) $decodedBody['messages'][0]['message'];
            if (!empty($decodedBody['errors'][0]['message'])) return (string) $decodedBody['errors'][0]['message'];
            if (!empty($decodedBody['error'])) return (string) $decodedBody['error'];
            if (!empty($decodedBody['message'])) return (string) $decodedBody['message'];
        }

        return $rawBody !== '' ? $rawBody : 'خطای نامشخص در آپلود فایل';
    }
}
