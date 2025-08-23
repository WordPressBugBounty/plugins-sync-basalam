<?php
if (! defined('ABSPATH')) exit;

class Sync_basalam_Upload_File
{

    static function upload($filePath)
    {
        if (!self::check_file_size($filePath)) {
            return false;
        }

        if (!self::check_extension_from_path($filePath)) {
            return false;
        }

        $response = self::upload_file_to_basalam($filePath);
        if ($response and in_array($response['status_code'], [200, 201]) and $response['body']) {
            return [
                'file_id' => $response['body']['id'],
                'url' => $response['body']['urls']['primary']
            ];
        }

        return false;
    }

    static function check_file_size($path)
    {
        if (!file_exists($path)) {
            return false;
        }

        $file_size = filesize($path);

        if ($file_size > 5 * 1024 * 1024) {
            return false;
        }

        return true;
    }

    static function check_extension_from_path($file_path)
    {
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!file_exists($file_path)) {
            return false;
        }

        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        return in_array($extension, $allowed_extensions);
    }

    static function upload_file_to_basalam($file_path)
    {
        $api_service = new sync_basalam_External_API_Service();
        $url = "https://uploadio.basalam.com/api_v2/files";

        $token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
        if (!$token) {
            sync_basalam_Logger::error("توکن یافت نشد.", ['مسیر فایل' => $file_path, 'عملیات' => "آپلود تصویر در Uplodio"]);
            return false;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token,
        ];

        $data = ['file_type' => 'product.photo'];
        $response =  $api_service->upload_file_request($url, $file_path, $data, $headers);
        return $response;
    }
}
