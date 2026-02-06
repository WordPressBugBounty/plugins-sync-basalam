<?php

namespace SyncBasalam\Services\Api;

use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class FileUploadApiService
{
    public function upload(string $url, string $filePath, array $data = [], array $headers = []): array
    {
        $fileValidation = $this->validateFile($filePath);
        if (!$fileValidation['valid']) {
            return [
                'body' => null,
                'status_code' => 400,
                'error' => $fileValidation['message']
            ];
        }

        $boundary = wp_generate_password(24);
        $payload = $this->makePayloadUploadFileRequest($data, $filePath, $boundary);

        $headers = array_merge($headers, ['content-type' => 'multipart/form-data; boundary=' . $boundary]);

        $response = wp_remote_post(
            $url,
            [
                'headers' => $headers,
                'body'    => $payload,
            ]
        );

        return $this->handleResponse($response);
    }

    private function validateFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['valid' => false, 'message' => 'فایل در دسترس نیست' . $filePath];
        }

        $fileSize = filesize($filePath);
        if ($fileSize > 5 * 1024 * 1024) {
            return ['valid' => false, 'message' => 'حجم فایل بیش از حد مجاز است : ' . $filePath];
        }

        $allowedExtensions = ['jpg', 'png', 'webp', 'bmp', 'jfif', 'jpeg', 'avif'];
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions)) {
            return ['valid' => false, 'message' => 'فرمت تصویر متغیر نیست ، فرمت های معتبر : ' . $allowedExtensions];
        }

        return ['valid' => true, 'message' => 'فایل متعبر است'];
    }

    private function handleResponse($response): array
    {
        if (is_wp_error($response)) {
            return [
                'body' => null,
                'status_code' => 500,
                'error' => $response->get_error_message()
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $statusCode = wp_remote_retrieve_response_code($response);

        $decodedBody = json_decode($body, true);

        return [
            'body' => $decodedBody,
            'status_code' => $statusCode,
            'error' => null
        ];
    }

    private function makePayloadUploadFileRequest(array $data, string $localFile, string $boundary): string
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
                Logger::error("خطا در خواندن فایل با استفاده از file_get_contents: " . $localFile);
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
}
