<?php

namespace SyncBasalam\Logger {
    final class Logger {
        public static array $entries = [];
        public static function info($message, $context = []) { self::$entries[] = ['info', $message, $context]; }
        public static function warning($message, $context = []) { self::$entries[] = ['warning', $message, $context]; }
    }
}

namespace {
    /** Offline regression: optional reports do not lock shipping; routine sync is silent. */
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('ABSPATH', __DIR__ . '/wordpress/');
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    $options = [];
    $checks = 0;
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
    function wp_cache_delete(...$args) {}
    function wc_get_order($id) { throw new \RuntimeException('No order should be loaded in an empty batch'); }
    function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    function esc_html($value) { return esc_attr($value); }
    function esc_url($value) { return esc_attr($value); }
    function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
    function resilienceCheck(bool $condition, string $message): void {
        global $checks;
        if (!$condition) throw new \RuntimeException($message);
        $checks++;
    }

    $wpdb = new class {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $last_error = '';
        public bool $failReads = false;
        public function prepare($query, ...$args) { return $query; }
        public function query($query) { return 1; } // Synthetic lease insert/release only.
        public function get_col($query) {
            if ($this->failReads) throw new \RuntimeException('Synthetic datastore failure');
            return [];
        }
    };
    \SyncBasalam\SalamGate\ClientFactory::$resolver = static fn () => new class extends \SyncBasalam\SalamGate\SalamGateClient {
        public function token(): ?string { return 'synthetic-access'; }
    };
    $service = new \SyncBasalam\SalamGate\Services\StatusSyncService();
    $result = $service->syncBatch();
    resilienceCheck($result === ['checked' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => ''], 'Empty sync completes normally');
    resilienceCheck(\SyncBasalam\Logger\Logger::$entries === [], 'Routine sync must not emit any INFO entry');
    resilienceCheck(isset($options[$service::LAST_SYNC_KEY]['ran_at']), 'Preserve last-sync diagnostics');
    resilienceCheck($options[$service::LAST_SYNC_KEY]['checked'] === 0, 'Keep real counters, not fabricated success');
    $wpdb->failReads = true;
    $result = $service->syncBatch();
    resilienceCheck($result['skipped'] === 'error', 'Real sync failure remains visible in diagnostics');
    resilienceCheck(count(\SyncBasalam\Logger\Logger::$entries) === 1
        && \SyncBasalam\Logger\Logger::$entries[0][0] === 'warning', 'Keep genuine failure warnings');

    $data = ['state' => 'active', 'view' => '', 'shops' => [], 'message' => ''];
    ob_start();
    include dirname(__DIR__, 2) . '/templates/SalamGate/page-router.php';
    $html = (string) ob_get_clean();
    $document = new \DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    $xpath = new \DOMXPath($document);
    resilienceCheck($xpath->query('//*[@id="sg-state-container"][@data-sg-load]')->length === 0, 'Optional stats must not gate the whole dashboard');
    resilienceCheck($xpath->query('//*[@data-sg-stats]/ancestor::*[@data-sg-load]')->length === 1, 'Stats own an isolated loading region');
    resilienceCheck($xpath->query('//a[contains(@href, "view=send")]/ancestor::*[@data-sg-load or @inert]')->length === 0, 'Shipping navigation never waits for stats');
    resilienceCheck($xpath->query('//*[@data-sg-stats]//*[@data-stat]')->length === 3, 'Keep all three real statistics');
    resilienceCheck($xpath->query('//*[@data-sg-stats]/ancestor::*[@data-sg-load]//*[@data-sg-load-retry]')->length === 1, 'Retry belongs to the report alone');
    resilienceCheck($xpath->query('//*[@data-sg-stats]/ancestor::*[@inert]')->length === 1, 'No partial statistics before the report is ready');
    echo "SalamGate dashboard resilience: {$checks} checks passed\n";
}
