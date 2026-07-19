<?php

namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

class FileUploader
{
    private const PHOTO_EXTENSIONS = ['jpg', 'png', 'webp', 'bmp', 'jfif', 'jpeg', 'avif'];
    private const PHOTO_MAX_SIZE = 5 * 1024 * 1024;

    private const VIDEO_EXTENSIONS = ['mp4', 'mov', '3gp', 'm4v', 'mkv', 'flv', 'mpg', 'webm', 'mpeg', 'ts', 'avi', 'qt', 'm4a'];
    private const VIDEO_MAX_SIZE = 120 * 1024 * 1024;

    public function upload($filePath)
    {
        return $this->uploadMedia($filePath, [
            'file_type' => 'product.photo',
            'allowed_extensions' => self::PHOTO_EXTENSIONS,
            'max_size' => self::PHOTO_MAX_SIZE,
            'error_label' => 'تصویر',
        ]);
    }

    public function uploadVideo($filePath)
    {
        return $this->uploadMedia($filePath, [
            'file_type' => 'product.video',
            'allowed_extensions' => self::VIDEO_EXTENSIONS,
            'max_size' => self::VIDEO_MAX_SIZE,
            'error_label' => 'ویدیو',
        ]);
    }

    private function uploadMedia(string $filePath, array $config): array
    {
        $preparedFile = $this->prepare($filePath, $config['allowed_extensions'], $config['max_size']);

        if ($preparedFile === false) {
            throw new \RuntimeException('فایل ' . esc_html($config['error_label']) . ' برای آپلود معتبر یا در دسترس نیست.');
        }

        $pathToUpload = $preparedFile['path'];
        $tmpFile = $preparedFile['tmpFile'] ?? null;

        try {
            if (!$this->checkFileSize($pathToUpload, $config['max_size'])) {
                throw new \RuntimeException('حجم فایل ' . esc_html($config['error_label']) . ' بیش از حد مجاز است.');
            }

            $options = [
                'allowed_extensions' => $config['allowed_extensions'],
                'max_size' => $config['max_size'],
                'timeout' => $config['file_type'] === 'product.video' ? 600 : 120,
            ];

            $mediaUploader = new MediaUploadService();

            if ($config['file_type'] === 'product.video') {
                return $mediaUploader->uploadVideo($pathToUpload, $options);
            }

            return $mediaUploader->uploadPhoto($pathToUpload, $options);
        } finally {
            if (!empty($preparedFile['isTemp']) && $tmpFile && file_exists($tmpFile)) unlink($tmpFile);
        }
    }

    private function prepare($filePath, array $allowedExtensions, int $maxSize)
    {
        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
            $parsedUrl = parse_url($filePath);
            $path = $parsedUrl['path'] ?? $filePath;
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions, true)) return false;

            $tmpFile = sys_get_temp_dir() . '/' . uniqid('upload_', true) . '.' . $extension;
            $response = wp_safe_remote_get($filePath, [
                'timeout' => 600,
                'stream' => true,
                'filename' => $tmpFile,
                'limit_response_size' => $maxSize + 1,
            ]);

            if (is_wp_error($response)) {
                if (file_exists($tmpFile)) unlink($tmpFile);
                throw new \RuntimeException('دانلود فایل از آدرس مبدا ناموفق بود: ' . esc_html($response->get_error_message()));
            }

            $statusCode = (int) wp_remote_retrieve_response_code($response);
            if ($statusCode < 200 || $statusCode >= 300 || !file_exists($tmpFile)) {
                if (file_exists($tmpFile)) unlink($tmpFile);
                throw new \RuntimeException('دانلود فایل از آدرس مبدا ناموفق بود.');
            }

            return [
                'path' => $tmpFile,
                'isTemp' => true,
                'tmpFile' => $tmpFile
            ];
        }

        if (!file_exists($filePath)) return false;

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) return false;

        return [
            'path' => $filePath,
            'isTemp' => false,
            'tmpFile' => null
        ];
    }

    public function checkFileSize($path, int $maxSize = self::PHOTO_MAX_SIZE): bool
    {
        if (!file_exists($path)) return false;

        $fileSize = filesize($path);
        return $fileSize !== false && $fileSize <= $maxSize;
    }

    public function FileExtensionValidator($extension)
    {
        return in_array($extension, self::PHOTO_EXTENSIONS, true);
    }
}
