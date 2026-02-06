<?php

namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;
class FileUploader
{
    public static function upload($filePath)
    {
        if (filter_var($filePath, FILTER_VALIDATE_URL)) {
            $tmpFile = \wp_tempnam($filePath);
            $fileContents = file_get_contents($filePath);

            if ($fileContents === false) return false;

            file_put_contents($tmpFile, $fileContents);
            $pathToUpload = $tmpFile;
            $isTemp = true;
        } else {
            $pathToUpload = $filePath;
            $isTemp = false;
        }

        if (!self::checkFileSize($pathToUpload)) {
            if (!empty($isTemp)) {
                unlink($tmpFile);
            }

            return false;
        }

        if (!self::checkExtensionFromPath($pathToUpload)) {
            if (!empty($isTemp)) {
                unlink($tmpFile);
            }

            return false;
        }

        $response = self::uploadFileToBasalam($pathToUpload);

        if (!empty($isTemp)) {
            unlink($tmpFile);
        }

        if ($response && $response['status_code'] == 200 && $response['body']) {
            return [
                'file_id' => $response['body']['id'],
                'url'     => $response['body']['urls']['primary'],
            ];
        }

        return false;
    }

    public static function checkFileSize($path)
    {
        if (!file_exists($path)) return false;

        $fileSize = filesize($path);

        if ($fileSize > 5 * 1024 * 1024) return false;

        return true;
    }

    public static function checkExtensionFromPath($filePath)
    {
        $allowedExtensions = ['jpg', 'png', 'webp', 'bmp', 'jfif', 'jpeg', 'avif'];

        if (!file_exists($filePath)) return false;

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return in_array($extension, $allowedExtensions);
    }

    public static function uploadFileToBasalam($filePath)
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
