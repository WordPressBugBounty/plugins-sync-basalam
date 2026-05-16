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

        $boundary = wp_generate_password(24);
        $payload = $this->makePayloadupload($data, $filePath, $boundary);

        $headers = array_merge($headers, ['content-type' => 'multipart/form-data; boundary=' . $boundary]);

        $response = wp_remote_post(
            $url,
            ['headers' => $headers, 'body' => $payload, 'timeout' => 10,],
        );

        return $this->handleResponse($response, $url);
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
