<?php

/** Offline checks use WordPress's own polyfills, as the installed plugin does. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$compatFile = getenv('WOOSALAM_WP_COMPAT_FILE');
if (is_string($compatFile) && $compatFile !== '') {
    require_once $compatFile;
}
foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $function) {
    if (!function_exists($function)) {
        throw new RuntimeException('Set WOOSALAM_WP_COMPAT_FILE to WordPress wp-includes/compat.php for PHP 7.4 checks.');
    }
}
