<?php

namespace SyncBasalam\Services\Api;

defined('ABSPATH') || exit;

class ApiRequestValidator
{
    public function validate(string $url, $data, array $headers = []): array
    {
        if (empty($url)) {
            return [
                'valid'   => false,
                'message' => 'URL الزامی است.'
            ];
        }

        if (!empty($data) && !$this->isValidData($data)) {
            return [
                'valid'   => false,
                'message' => 'داده های ورودی باید JSON باشد.'
            ];
        }

        if (!empty($headers) && !$this->isValidHeaders($headers)) {
            return [
                'valid'   => false,
                'message' => 'فرمت هدرها نامعتبر است.'
            ];
        }

        return [
            'valid' => true,
            'message' => 'اعتبارسنجی با موفقیت انجام شد.'
        ];
    }

    private function isValidData($data): bool
    {
        return is_array($data);
    }

    private function isValidHeaders(array $headers): bool
    {
        return is_array($headers);
    }

    public function validateFile(string $filePath): array
    {
        if (empty($filePath)) {
            return [
                'valid'   => false,
                'message' => 'فایل الزامی است.'
            ];
        }

        if (!file_exists($filePath)) {
            return [
                'valid'   => false,
                'message' => "فایل وجود ندارد: {$filePath}"
            ];
        }

        if (!is_readable($filePath)) {
            return [
                'valid'   => false,
                'message' => "فایل دسترسی read ندارد: {$filePath}"
            ];
        }

        return [
            'valid' => true,
            'message' => 'اعتبارسنجی فایل با موفقیت انجام شد.'
        ];
    }
}
