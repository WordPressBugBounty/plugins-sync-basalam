<?php

namespace SyncBasalam\Admin\Product\Data\Services;

use SyncBasalam\Logger\Logger;
use SyncBasalam\Services\FileUploader;

defined('ABSPATH') || exit;

class PhotoService
{
    private $fileUploader;

    public function __construct()
    {
        $this->fileUploader = new FileUploader();
    }

    public function getMainPhotoId($product): ?int
    {
        $mainImageId = $product->get_image_id();
        if (!$mainImageId) return null;

        $existingPhoto = $this->getExistingPhoto($mainImageId);
        if ($existingPhoto) return $existingPhoto['file_id'];

        $uploadedPhoto = $this->uploadPhoto($mainImageId);
        if ($uploadedPhoto) {
            $this->storePhotoRecord($mainImageId, $uploadedPhoto);
            return $uploadedPhoto['file_id'];
        }

        return null;
    }

    public function getGalleryPhotoIds($product)
    {
        $galleryPhotoIds = [];
        $galleryImageIds = $product->get_gallery_image_ids();

        if (empty($galleryImageIds)) return [];

        $galleryImageIds = array_slice($galleryImageIds, 0, 20);
        foreach ($galleryImageIds as $imageId) {
            $existingPhoto = $this->getExistingPhoto($imageId);

            if ($existingPhoto) {
                $galleryPhotoIds[] = $existingPhoto['file_id'];
            } else {
                $uploadedPhoto = $this->uploadPhoto($imageId);
                if ($uploadedPhoto) {
                    $this->storePhotoRecord($imageId, $uploadedPhoto);
                    $galleryPhotoIds[] = $uploadedPhoto['file_id'];
                }
            }
        }

        return $galleryPhotoIds;
    }

    private function uploadPhoto(int $imageId)
    {
        $imagePathOrUrl = $this->getImagePathOrUrl($imageId);
        if (!$imagePathOrUrl) return null;

        try {
            return $this->fileUploader->upload($imagePathOrUrl);
        } catch (\Throwable $e) {
            throw new \RuntimeException('آپلود تصویر محصول به باسلام ناموفق بود: ' . $e->getMessage(), 0, $e);
        }
    }

    private function getImagePathOrUrl(int $imageId): ?string
    {
        $url = wp_get_attachment_url($imageId);
        $path = get_attached_file($imageId);

        if ($path && file_exists($path)) return $path;

        return $url;
    }

    private function getExistingPhoto(int $wooPhotoId)
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'sync_basalam_uploaded_media';
        $sourceIdentity = 'attachment:' . $wooPhotoId;

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT media_id AS file_id, media_url AS url, created_at
            FROM $tableName
            WHERE type = %s AND source_identity = %s",
            'photo',
            $sourceIdentity
        ), ARRAY_A);

        if (!$result) return null;

        $createdAt = strtotime($result['created_at']);
        $now = current_time('timestamp');
        $fourteenDays = 14 * DAY_IN_SECONDS;

        $age = $now - $createdAt;

        if ($age >= $fourteenDays) {
            $wpdb->delete(
                $tableName,
                ['type' => 'photo', 'source_identity' => $sourceIdentity],
                ['%s', '%s']
            );
            return null;
        }

        return $result;
    }

    private function storePhotoRecord(int $wooPhotoId, array $basalamPhoto): void
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'sync_basalam_uploaded_media';

        $wpdb->replace(
            $tableName,
            [
                'type' => 'photo',
                'source_identity' => 'attachment:' . $wooPhotoId,
                'media_id' => (int) $basalamPhoto['file_id'],
                'media_url' => $basalamPhoto['url'] ?? '',
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );
    }
}
