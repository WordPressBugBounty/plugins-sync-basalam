<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;
class FileUploader
{
    public function upload($filePath)
    {
        $preparedFile = $this->prepare($filePath);

        if ($preparedFile === false) {
            return false;
        }

        $pathToUpload = $preparedFile['path'];
        $isTemp = $preparedFile['isTemp'];
        $tmpFile = $preparedFile['tmpFile'] ?? null;

        if (!$this->checkFileSize($pathToUpload)) {
            if ($isTemp && $tmpFile) unlink($tmpFile);
            return false;
        }

        $response = $this->uploadFileToBasalam($pathToUpload);

        if ($isTemp && $tmpFile) unlink($tmpFile);

        if ($response && $response['status_code'] == 200 && $response['body']) {
            return [
                'file_id' => $response['body']['id'],
                'url'     => $response['body']['urls']['primary'],
            ];
        }

        return false;
    }

    private function prepare($filePath)
    {
        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
            $parsedUrl = parse_url($filePath);
            $path = $parsedUrl['path'] ?? $filePath;
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (!$this->FileExtensionValidator($extension)) return false;

            $tmpFile = sys_get_temp_dir() . '/' . uniqid('upload_', true) . '.' . $extension;

            $fileContents = file_get_contents($filePath);

            if ($fileContents === false) return false;

            file_put_contents($tmpFile, $fileContents);

            return [
                'path' => $tmpFile,
                'isTemp' => true,
                'tmpFile' => $tmpFile
            ];
        } else {
            if (!file_exists($filePath)) return false;

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            if (!$this->FileExtensionValidator($extension)) return false;

            return [
                'path' => $filePath,
                'isTemp' => false,
                'tmpFile' => null
            ];
        }
    }

    public function checkFileSize($path)
    {
        if (!file_exists($path)) return false;

        $fileSize = filesize($path);

        if ($fileSize > 5 * 1024 * 1024) return false;

        return true;
    }

    public function FileExtensionValidator($extension)
    {
        $allowedExtensions = ['jpg', 'png', 'webp', 'bmp', 'jfif', 'jpeg', 'avif'];
        return in_array($extension, $allowedExtensions);
    }

    public function uploadFileToBasalam($filePath)
    {
        $apiService = new ApiServiceManager();

        $url = "https://uploadio.basalam.com/v3/files";

        $data = ['file_type' => 'product.photo'];
        $token = syncBasalamSettings()->getSettings(SettingsConfig::TOKEN);

        $headers = ['Authorization' => 'Bearer ' . $token];

        $response = $apiService->uploadFileRequest($url, $filePath, $data, $headers);

        return $response;
    }
}
