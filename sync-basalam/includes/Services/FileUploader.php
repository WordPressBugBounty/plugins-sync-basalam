<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Config\Endpoints;
use SyncBasalam\Logger\Logger;

defined('ABSPATH') || exit;

class FileUploader
{
    private const PHOTO_EXTENSIONS = ['jpg', 'png', 'webp', 'bmp', 'jfif', 'jpeg', 'avif'];
    private const PHOTO_MAX_SIZE = 5 * 1024 * 1024;

    private const VIDEO_EXTENSIONS = ['mp4', 'mov', '3gp', 'm4v', 'mkv', 'flv', 'mpg', 'webm', 'mpeg', 'ts', 'avi', 'qt', 'm4a'];
    private const VIDEO_MAX_SIZE = 120 * 1024 * 1024;

    public function upload($filePath)
    {
        return $this->doUpload($filePath, [
            'file_type' => 'product.photo',
            'allowed_extensions' => self::PHOTO_EXTENSIONS,
            'max_size' => self::PHOTO_MAX_SIZE,
            'error_label' => 'تصویر',
        ]);
    }

    public function uploadVideo($filePath)
    {
        return $this->doUpload($filePath, [
            'file_type' => 'product.video',
            'allowed_extensions' => self::VIDEO_EXTENSIONS,
            'max_size' => self::VIDEO_MAX_SIZE,
            'error_label' => 'ویدیو',
        ]);
    }

    private function doUpload(string $filePath, array $config): array
    {
        $preparedFile = $this->prepare($filePath, $config['allowed_extensions']);

        if ($preparedFile === false) {
            throw new \RuntimeException('فایل ' . $config['error_label'] . ' برای آپلود معتبر یا در دسترس نیست.');
        }

        $pathToUpload = $preparedFile['path'];
        $isTemp = $preparedFile['isTemp'];
        $tmpFile = $preparedFile['tmpFile'] ?? null;

        if (!$this->checkFileSize($pathToUpload, $config['max_size'])) {
            if ($isTemp && $tmpFile) unlink($tmpFile);
            throw new \RuntimeException('حجم فایل ' . $config['error_label'] . ' بیش از حد مجاز است.');
        }

        $response = $this->uploadFileToBasalam($pathToUpload, $config);

        if ($isTemp && $tmpFile) unlink($tmpFile);

        if ($response && $response['status_code'] == 200 && $response['body']) {
            return [
                'file_id' => $response['body']['id'],
                'url'     => $response['body']['urls']['primary'] ?? null,
            ];
        }

        $errorMessage = $response['error'] ?? 'آپلود ' . $config['error_label'] . ' به باسلام ناموفق بود.';

        throw new \RuntimeException($errorMessage);
    }

    private function prepare($filePath, array $allowedExtensions)
    {
        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
            $parsedUrl = parse_url($filePath);
            $path = $parsedUrl['path'] ?? $filePath;
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions, true)) return false;

            $tmpFile = sys_get_temp_dir() . '/' . uniqid('upload_', true) . '.' . $extension;

            $fileContents = file_get_contents($filePath);

            if ($fileContents === false) {
                throw new \RuntimeException('دانلود فایل از آدرس مبدا ناموفق بود.');
            }

            file_put_contents($tmpFile, $fileContents);

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

        if ($fileSize > $maxSize) return false;

        return true;
    }

    public function FileExtensionValidator($extension)
    {
        return in_array($extension, self::PHOTO_EXTENSIONS, true);
    }

    public function uploadFileToBasalam($filePath, array $config = [])
    {
        $apiService = syncBasalamContainer()->get(ApiServiceManager::class);

        $url = Endpoints::FILE_UPLOAD;

        $fileType = $config['file_type'] ?? 'product.photo';
        $allowedExtensions = $config['allowed_extensions'] ?? self::PHOTO_EXTENSIONS;
        $maxSize = $config['max_size'] ?? self::PHOTO_MAX_SIZE;

        $data = ['file_type' => $fileType];
        $token = syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);

        $headers = ['Authorization' => 'Bearer ' . $token];

        $options = [
            'allowed_extensions' => $allowedExtensions,
            'max_size' => $maxSize,
        ];

        return $apiService->upload($url, $filePath, $data, $headers, $options);
    }
}
