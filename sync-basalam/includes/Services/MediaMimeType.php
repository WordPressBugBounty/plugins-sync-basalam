<?php

namespace SyncBasalam\Services;

defined('ABSPATH') || exit;

class MediaMimeType
{
    private const ALIASES = [
        'image/jpg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/x-png' => 'image/png',
        'image/x-bmp' => 'image/bmp',
        'image/x-ms-bmp' => 'image/bmp',
        'image/x-windows-bmp' => 'image/bmp',
    ];

    /**
     * Detect a media type from the file contents before falling back to its extension.
     * WordPress filters can return non-standard aliases such as image/jpg, which Uploadio rejects.
     */
    public static function detect(string $filePath): string
    {
        if (function_exists('wp_get_image_mime')) {
            $mimeType = wp_get_image_mime($filePath);
            if (is_string($mimeType) && $mimeType !== '') return self::canonicalize($mimeType);
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);

                if (is_string($mimeType) && $mimeType !== '' && $mimeType !== 'application/octet-stream') {
                    return self::canonicalize($mimeType);
                }
            }
        }

        $fileType = wp_check_filetype($filePath);

        return !empty($fileType['type'])
            ? self::canonicalize((string) $fileType['type'])
            : 'application/octet-stream';
    }

    public static function canonicalize(string $mimeType): string
    {
        $mimeType = strtolower(trim($mimeType));

        return self::ALIASES[$mimeType] ?? $mimeType;
    }
}
