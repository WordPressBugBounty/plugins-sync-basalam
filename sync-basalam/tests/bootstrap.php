<?php

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/wordpress/');
defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);

if (!function_exists('wp_get_image_mime')) {
    function wp_get_image_mime($file)
    {
        return $GLOBALS['sync_basalam_test_image_mime'] ?? false;
    }
}

if (!function_exists('wp_check_filetype')) {
    function wp_check_filetype($file)
    {
        return [
            'ext' => pathinfo($file, PATHINFO_EXTENSION),
            'type' => $GLOBALS['sync_basalam_test_filetype_mime'] ?? null,
        ];
    }
}

require_once dirname(__DIR__) . '/includes/Services/MediaMimeType.php';
require_once dirname(__DIR__) . '/includes/Services/MediaUploadService.php';
require_once dirname(__DIR__) . '/includes/Services/VendorSyncPolicy.php';
