<?php

// Real WordPress HTTP/Requests, hooks and wpdb against an isolated MariaDB.
// Other store configuration helpers remain in memory; no active plugins load.
define('ABSPATH', rtrim(getenv('WORDPRESS_PATH'), '/') . '/');
define('WPINC', 'wp-includes');
define('MINUTE_IN_SECONDS', 60);
define('WP_DEBUG', false);
require ABSPATH . WPINC . '/version.php';
require ABSPATH . WPINC . '/plugin.php';

function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array) $args); }
function get_bloginfo($key) { return $key === 'version' ? $GLOBALS['wp_version'] : 'https://store.test'; }
function get_status_header_desc($code) { return (string) $code; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; $GLOBALS['ttls'][$key] = $ttl; return true; }
function mbstring_binary_safe_encoding($reset = false) {}
function reset_mbstring_encoding() {}
function __($text) { return $text; }
function esc_html($text) { return $text; }
function get_site_url() { return 'https://store.test'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int) $value); }
function is_multisite() { return false; }
function wp_debug_backtrace_summary(...$args) { return 'fallback-test'; }
function syncBasalamSettings() { return new class { public function getSettings($key) { return 'local-test-token'; } }; }
function current_time($type, $gmt = false) { return time(); }
function syncBasalamContainer() { return new class { public function get($class) { return new $class(); } }; }
class WC_Logger {}
function wc_get_logger() { return new class { public function log($level, $message, $context) {
    $GLOBALS['test_logs'][] = ['level' => $level, 'message' => $message];
} }; }

foreach (['class-wp-error.php', 'class-wp-http.php', 'class-wp-http-encoding.php',
    'class-wp-http-proxy.php', 'class-wp-http-response.php', 'class-wp-http-requests-response.php',
    'class-wp-http-requests-hooks.php', 'class-wp-http-cookie.php', 'http.php'] as $file) {
    require ABSPATH . WPINC . '/' . $file;
}
require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('DB_NAME', 'fallback_test');
require ABSPATH . WPINC . '/class-wpdb.php';
$wpdb = new wpdb('root', '', DB_NAME, '127.0.0.1:' . getenv('TEST_DB_PORT'));
$wpdb->set_prefix('wp_');
$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->options} (
    option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    option_name VARCHAR(191) NOT NULL UNIQUE,
    option_value LONGTEXT NOT NULL,
    autoload VARCHAR(20) NOT NULL DEFAULT 'no'
)");

function resetFallbackPolicy(): void
{
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s",
        \SyncBasalam\Services\Api\OpenApiFallbackPolicy::OPTION_KEY));
}

function readFallbackPolicy(): array
{
    global $wpdb;
    return json_decode($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        \SyncBasalam\Services\Api\OpenApiFallbackPolicy::OPTION_KEY)) ?? '{}', true);
}

function seedFallbackPolicy(array $state): void
{
    global $wpdb;
    $wpdb->replace($wpdb->options, ['option_name' => \SyncBasalam\Services\Api\OpenApiFallbackPolicy::OPTION_KEY,
        'option_value' => json_encode($state), 'autoload' => 'no']);
}
