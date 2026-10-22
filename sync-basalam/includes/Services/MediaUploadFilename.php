<?php

namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

/** Names sent to the media provider must not depend on the local filename. */
class MediaUploadFilename
{
    private const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
    ];

    public static function forFile(string $filePath, ?string $mimeType = null, ?string $checksum = null): string
    {
        $mimeType = MediaMimeType::canonicalize($mimeType ?? MediaMimeType::detect($filePath));
        $extension = self::IMAGE_EXTENSIONS[$mimeType] ?? strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $checksum = $checksum ?? hash_file('sha256', $filePath);

        if (!preg_match('/\A[a-z0-9]{1,10}\z/', $extension)
            || !is_string($checksum) || !preg_match('/\A[a-f0-9]{64}\z/', $checksum)) {
            throw new \RuntimeException('ساخت نام معتبر برای فایل ارسالی به باسلام ناموفق بود.');
        }

        // A Latin stem also survives providers which strip non-ASCII filename characters.
        return 'basalam-' . substr($checksum, 0, 32) . '.' . $extension;
    }
}
