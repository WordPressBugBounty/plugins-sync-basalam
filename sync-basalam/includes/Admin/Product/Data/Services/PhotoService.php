<?php

namespace SyncBasalam\Admin\Product\Data\Services;

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

        // Limit to 10 photos maximum
        $galleryImageIds = array_slice($galleryImageIds, 0, 10);
        foreach ($galleryImageIds as $index => $imageId) {

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
            $data = $this->fileUploader->upload($imagePathOrUrl);
            return $data;
        } catch (\Exception $e) {
            return null;
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
        $tableName = $wpdb->prefix . 'sync_basalam_uploaded_photo';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT sync_basalam_photo_id AS file_id, sync_basalam_photo_url AS url, created_at
            FROM $tableName
            WHERE woo_photo_id = %d",
            $wooPhotoId
        ), ARRAY_A);

        if (!$result) return null;

        // Check if photo is older than 14 days
        $createdAt = strtotime($result['created_at']);
        $now = current_time('timestamp');
        $fourteenDays = 14 * DAY_IN_SECONDS;

        $age = $now - $createdAt;

        if ($age >= $fourteenDays) {
            $wpdb->delete($tableName, ['woo_photo_id' => $wooPhotoId], ['%d']);
            return null;
        }

        return $result;
    }

    private function storePhotoRecord(int $wooPhotoId, array $basalamPhoto): void
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'sync_basalam_uploaded_photo';

        $insertData = [
            'woo_photo_id' => $wooPhotoId,
            'sync_basalam_photo_id' => $basalamPhoto['file_id'],
            'sync_basalam_photo_url' => $basalamPhoto['url'],
            'created_at' => current_time('mysql'),
        ];

        $wpdb->insert($tableName, $insertData, ['%d', '%d', '%s', '%s']);
    }
}