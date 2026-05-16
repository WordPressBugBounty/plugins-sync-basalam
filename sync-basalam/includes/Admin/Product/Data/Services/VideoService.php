<?php

namespace SyncBasalam\Admin\Product\Data\Services;

use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\FileUploader;
use SyncBasalam\Services\Products\VideoSourceResolver;

defined('ABSPATH') || exit;

class VideoService
{
    private $fileUploader;

    public function __construct()
    {
        $this->fileUploader = new FileUploader();
    }

    public function getVideoFileId($product): ?int
    {
        $productId = (int) $product->get_id();
        $rawValue = VideoSourceResolver::resolveValue($productId);

        if ($rawValue === null) return null;

        $sourceIdentity = $this->buildSourceIdentity($rawValue);
        $filePath = $this->resolveLocalPath($rawValue);

        if ($filePath === null) {
            Logger::info('ویدیو محصول به‌صورت لوکال در دسترس نیست؛ آپلود به باسلام انجام نمی‌شود.', [
                'product_id' => $productId,
                'raw_value' => $rawValue,
            ]);
            return null;
        }

        $existing = $this->getExistingVideo($sourceIdentity);
        if ($existing) return (int) $existing['file_id'];

        try {
            $uploaded = $this->fileUploader->uploadVideo($filePath);
        } catch (\Throwable $e) {
            Logger::error('خطا در آپلود ویدیو محصول به باسلام: ' . $e->getMessage(), [
                'product_id' => $productId,
                'file_path' => $filePath,
            ]);
            return null;
        }

        if (!empty($uploaded['file_id'])) {
            $this->storeVideoRecord($sourceIdentity, $uploaded);
            return (int) $uploaded['file_id'];
        }

        return null;
    }

    private function resolveLocalPath(string $rawValue): ?string
    {
        if (ctype_digit($rawValue) || is_numeric($rawValue)) {
            $attachmentId = (int) $rawValue;
            $path = get_attached_file($attachmentId);
            if ($path && file_exists($path)) return $path;

            $url = wp_get_attachment_url($attachmentId);
            return $url ?: null;
        }

        if (filter_var($rawValue, FILTER_VALIDATE_URL)) {
            $homeUrl = home_url();
            if (strpos($rawValue, $homeUrl) === 0) {
                $attachmentId = attachment_url_to_postid($rawValue);
                if ($attachmentId) {
                    $path = get_attached_file($attachmentId);
                    if ($path && file_exists($path)) return $path;
                }
                return $rawValue;
            }

            return null;
        }

        return null;
    }

    private function buildSourceIdentity(string $rawValue): string
    {
        if (ctype_digit($rawValue) || is_numeric($rawValue)) {
            return 'attachment:' . (int) $rawValue;
        }

        return 'url:' . md5($rawValue);
    }

    private function getExistingVideo(string $sourceIdentity): ?array
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'sync_basalam_uploaded_media';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT media_id AS file_id, media_url AS url, created_at
            FROM $tableName
            WHERE type = %s AND source_identity = %s",
            'video',
            $sourceIdentity
        ), ARRAY_A);

        if (!$result) return null;

        $createdAt = strtotime($result['created_at']);
        $now = current_time('timestamp');
        $fourteenDays = 14 * DAY_IN_SECONDS;

        if (($now - $createdAt) >= $fourteenDays) {
            $wpdb->delete(
                $tableName,
                ['type' => 'video', 'source_identity' => $sourceIdentity],
                ['%s', '%s']
            );
            return null;
        }

        return $result;
    }

    private function storeVideoRecord(string $sourceIdentity, array $uploaded): void
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'sync_basalam_uploaded_media';

        $wpdb->replace(
            $tableName,
            [
                'type' => 'video',
                'source_identity' => $sourceIdentity,
                'media_id' => (int) $uploaded['file_id'],
                'media_url' => $uploaded['url'] ?? '',
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );
    }
}
