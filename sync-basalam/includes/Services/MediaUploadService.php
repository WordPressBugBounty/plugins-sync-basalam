<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class MediaUploadService
{
    private const UPLOAD_PREFERENCE = 'presigned_post';

    private $apiService;

    public function __construct($apiService = null)
    {
        $this->apiService = $apiService ?: syncBasalamContainer()->get(ApiServiceManager::class);
    }

    public function uploadPhoto(string $filePath, array $options = []): array
    {
        return $this->upload($filePath, 'product.photo', 'تصویر', $options);
    }

    public function uploadVideo(string $filePath, array $options = []): array
    {
        return $this->upload($filePath, 'product.video', 'ویدیو', $options);
    }

    private function upload(string $filePath, string $fileType, string $fileLabel, array $options): array
    {
        $token = (string) syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);
        if ($token === '') throw new \RuntimeException('توکن باسلام یافت نشد. ابتدا اتصال باسلام را انجام دهید.');

        $mimeType = $this->detectMimeType($filePath);
        $fileSize = filesize($filePath);
        $checksum = hash_file('sha256', $filePath);

        if ($fileSize === false || $checksum === false) {
            throw new \RuntimeException('خواندن اطلاعات فایل ' . esc_html($fileLabel) . ' برای آپلود ناموفق بود.');
        }

        $headers = ['Authorization' => 'Bearer ' . $token];
        $request = $this->apiService->post(Endpoints::MEDIA_UPLOAD_REQUEST, [
            'file_name' => basename($filePath),
            'mime_type' => $mimeType,
            'size' => (int) $fileSize,
            'file_type' => $fileType,
            'checksum_sha256' => $checksum,
            'upload_preference' => self::UPLOAD_PREFERENCE,
            'multipart_part_size_bytes' => 0,
        ], $headers);

        $uploadRequest = $this->decodeBody($request['body'] ?? null);
        $fileId = $uploadRequest['file_id'] ?? null;
        $strategy = $uploadRequest['upload_strategy'] ?? '';

        if (!$fileId || $strategy !== 'presigned_post') {
            Logger::error('پاسخ درخواست آپلود ' . esc_html($fileLabel) . ' باسلام معتبر نیست.', [
                'status_code' => $request['status_code'] ?? 0,
                'upload_strategy' => $strategy,
                'response_body' => $uploadRequest,
            ]);
            throw new \RuntimeException('باسلام لینک معتبر آپلود ' . esc_html($fileLabel) . ' برنگرداند.');
        }

        $staging = $uploadRequest['staging'] ?? [];
        $uploadUrl = $staging['url'] ?? '';
        $fields = $staging['fields'] ?? [];

        if (!is_string($uploadUrl) || $uploadUrl === '' || !is_array($fields) || empty($fields)) {
            throw new \RuntimeException('اطلاعات لینک آپلود ' . esc_html($fileLabel) . ' باسلام ناقص است.');
        }

        $uploadResponse = $this->apiService->upload($uploadUrl, $filePath, $fields, [], $options);
        $uploadStatus = (int) ($uploadResponse['status_code'] ?? 0);

        if ($uploadStatus < 200 || $uploadStatus >= 300) {
            throw new \RuntimeException($uploadResponse['error'] ?? 'ارسال فایل ' . esc_html($fileLabel) . ' به لینک باسلام ناموفق بود.');
        }

        $complete = $this->apiService->post(Endpoints::MEDIA_UPLOAD_COMPLETE, [
            'file_id' => $fileId,
        ], $headers);
        $completeStatus = (int) ($complete['status_code'] ?? 0);

        if ($completeStatus < 200 || $completeStatus >= 300) {
            throw new \RuntimeException($complete['error'] ?? 'نهایی‌سازی آپلود ' . esc_html($fileLabel) . ' در باسلام ناموفق بود.');
        }

        $completedMedia = $this->decodeBody($complete['body'] ?? null);
        $normalized = $this->normalizeCompletedMedia($completedMedia, (string) $fileId);

        if ($normalized === null) {
            $maxAttempts = $fileType === 'product.video' ? 60 : 15;
            $normalized = $this->waitForCompletedMedia((string) $fileId, $headers, $fileLabel, $maxAttempts);
        }

        if ($normalized === null) {
            Logger::error('پاسخ نهایی آپلود ' . esc_html($fileLabel) . ' باسلام شناسه رسانه ندارد.', [
                'file_id' => $fileId,
                'status_code' => $complete['status_code'] ?? 0,
                'response_body' => $completedMedia,
            ]);
            throw new \RuntimeException('باسلام شناسه نهایی ' . esc_html($fileLabel) . ' آپلودشده را برنگرداند.');
        }

        return $normalized;
    }

    private function waitForCompletedMedia(string $fileId, array $headers, string $fileLabel, int $maxAttempts): ?array
    {
        $url = sprintf(Endpoints::MEDIA_UPLOAD_STATUS, rawurlencode($fileId));

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $status = $this->apiService->get($url, $headers);
            $statusCode = (int) ($status['status_code'] ?? 0);

            if ($statusCode >= 200 && $statusCode < 300) {
                $statusBody = $this->decodeBody($status['body'] ?? null);
                $normalized = $this->normalizeCompletedMedia($statusBody, $fileId);
                if ($normalized !== null) return $normalized;

                if (isset($statusBody['status']) && in_array($statusBody['status'], ['failed', 'aborted'], true)) {
                    throw new \RuntimeException('پردازش ' . esc_html($fileLabel) . ' آپلودشده در باسلام ناموفق بود.');
                }
            }

            if ($attempt < $maxAttempts - 1) sleep(1);
        }

        return null;
    }

    private function detectMimeType(string $filePath): string
    {
        $fileType = wp_check_filetype($filePath);
        if (!empty($fileType['type'])) return (string) $fileType['type'];

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if (is_string($mimeType) && $mimeType !== '') return $mimeType;
            }
        }

        return 'application/octet-stream';
    }

    private function decodeBody($body): array
    {
        if (is_array($body)) return $body;
        if (!is_string($body) || $body === '') return [];

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeCompletedMedia(array $body, string $requestFileId): ?array
    {
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
        $ready = $data['ready'] ?? null;
        $status = isset($data['status']) && is_string($data['status']) ? strtolower($data['status']) : null;

        if (isset($data['media']) && is_array($data['media'])) $data = $data['media'];
        if (isset($data['file']) && is_array($data['file'])) $data = $data['file'];

        if (isset($data['status']) && is_string($data['status'])) $status = strtolower($data['status']);
        if (array_key_exists('ready', $data)) $ready = $data['ready'];

        if (in_array($status, ['failed', 'aborted'], true)) {
            throw new \RuntimeException('پردازش فایل آپلودشده در باسلام ناموفق بود.');
        }

        if ($ready === false || in_array($status, ['initiated', 'uploading', 'processing', 'queued'], true)) {
            return null;
        }

        $mediaId = $data['id'] ?? $data['media_id'] ?? null;
        if ($mediaId === null || $mediaId === '') return null;

        $url = $data['urls']['primary'] ?? $data['url'] ?? $data['media_url'] ?? null;

        return [
            'file_id' => $mediaId,
            'url' => is_string($url) ? $url : null,
            'upload_file_id' => $requestFileId,
        ];
    }
}
