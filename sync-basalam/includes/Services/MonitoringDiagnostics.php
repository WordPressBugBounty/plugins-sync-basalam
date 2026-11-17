<?php
namespace SyncBasalam\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Logger\WooLogger;

defined('ABSPATH') || exit;

/** Only monitoring events. No general WooCommerce/debug logs leave the site. */
class MonitoringDiagnostics
{
    const OPTION = 'sync_basalam_monitoring_diagnostics_v1';
    const MAX_EVENTS = 500;
    const MAX_BYTES = 1048576;
    const MAX_AGE = 604800;
    private static $pending = [];
    private static $registered = false;
    private static $logger;

    /** Send the complete bounded REST body before any shutdown diagnostics I/O. */
    public static function serveRepairResponse($served, $result, $request, $server)
    {
        if (!self::$pending || $request->get_method() !== 'POST'
                || $request->get_route() !== '/sync-basalam/v1/monitoring-repair') return $served;
        if ($served) { self::$pending = []; return true; }
        // Unusual output transforms cannot safely advertise an exact byte length.
        // Lose best-effort telemetry instead of delaying or corrupting the response.
        $buffered = false;
        foreach (ob_get_status(true) as $buffer) {
            if (!empty($buffer['buffer_used']) || empty($buffer['flags']) || !($buffer['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE)) $buffered = true;
        }
        if (headers_sent() || ini_get('zlib.output_compression') || $buffered || $request->get_param('_jsonp')) {
            self::$pending = [];
            return $served;
        }
        try {
            $data = apply_filters('rest_pre_echo_response', $server->response_to_data($result, false), $server, $request);
            $options = (int) apply_filters('rest_json_encode_options', $request->has_param('_pretty') ? JSON_PRETTY_PRINT : 0, $request);
            $json = wp_json_encode($data, $options);
            if (!is_string($json)) { self::$pending = []; return $served; }
            $server->send_header('Content-Length', strlen($json));
            $server->send_header('Connection', 'close');
            echo $json;
            while (ob_get_level()) ob_end_flush();
            flush();
            return true;
        } catch (\Throwable $ignored) {
            self::$pending = [];
            return $served;
        }
    }

    public static function event(array $values): array
    {
        $event = ['event_id' => bin2hex(random_bytes(16)), 'at' => time(), 'stage' => 'repair'];
        foreach (['command_id', 'request_id'] as $field) {
            if (isset($values[$field]) && preg_match('/^[a-f0-9]{32}$/D', (string) $values[$field])) {
                $event[$field] = $values[$field];
            }
        }
        if (isset($values['scan_id']) && is_int($values['scan_id']) && $values['scan_id'] > 0) $event['scan_id'] = $values['scan_id'];
        $event['duration_ms'] = min(3600000, max(0, (int) ($values['duration_ms'] ?? 0)));
        $event['outcome'] = in_array($values['outcome'] ?? '', ['accepted', 'rejected', 'unknown'], true) ? $values['outcome'] : 'unknown';
        $allowed = ['ok', 'monitoring_invalid_command', 'monitoring_busy', 'monitoring_permission_inactive',
            'monitoring_permission_expired', 'monitoring_source_changed', 'monitoring_mapping_changed',
            'monitoring_rate_limited', 'monitoring_update_failed'];
        $event['reason_code'] = in_array($values['reason_code'] ?? '', $allowed, true) ? $values['reason_code'] : 'monitoring_update_failed';
        if (count(self::$pending) < 50) self::$pending[] = $event;
        if (!self::$registered) {
            self::$registered = true;
            // Remote loggers run only from the authenticated diagnostics request.
            // Repairs do no logger I/O. A killed PHP process may lose this buffer.
            register_shutdown_function([__CLASS__, 'flush']);
        }
        return $event;
    }

    private static function scope(): string
    {
        $settings = syncBasalamSettings()->getSettings();
        return hash('sha256', untrailingslashit(get_site_url()) . '|' .
            ($settings[SettingsConfig::VENDOR_ID] ?? '') . '|' . ($settings[SettingsConfig::TOKEN] ?? ''));
    }

    private static function load(): array
    {
        $ring = get_option(self::OPTION, []);
        if (!is_array($ring) || ($ring['scope'] ?? '') !== self::scope()) {
            $scope = self::scope();
            $ring = ['scope' => $scope, 'stream' => substr(hash('sha256', 'diagnostics|' . $scope), 0, 16), 'next' => 1, 'events' => []];
        }
        $ring['events'] = array_values(array_filter($ring['events'], function ($event) {
            return ($event['at'] ?? 0) >= time() - self::MAX_AGE;
        }));
        return $ring;
    }

    public static function flush(bool $pruneOnly = false): void
    {
        if (!self::$pending && !$pruneOnly) return;
        // Finish the HTTP response first on PHP-FPM. Other SAPIs still perform only
        // this bounded local store; an arbitrary Woo logger never runs here.
        if (!$pruneOnly && function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            self::$pending = [];
            return;
        }
        $locked = false;
        try {
            $name = 'woosalam_diag_' . substr(hash('sha256', $wpdb->prefix . get_site_url()), 0, 24);
            // This happens after repair response completion; wait at most two
            // seconds so simultaneous shutdowns do not immediately drop events.
            $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', $name)) === 1;
            if (!$locked) return;
            // Bypass an already-loaded option cache while holding the database lock.
            if (function_exists('wp_cache_delete')) wp_cache_delete(self::OPTION, 'options');
            $ring = self::load();
            foreach (self::$pending as $event) {
                $event['seq'] = $ring['next']++;
                $ring['events'][] = $event;
            }
            while (count($ring['events']) > self::MAX_EVENTS || strlen(json_encode($ring)) > self::MAX_BYTES) array_shift($ring['events']);
            update_option(self::OPTION, $ring, false);
        } catch (\Throwable $ignored) {
            // Diagnostics loss cannot change a repair's response or grant state.
        } finally {
            self::$pending = [];
            if ($locked) {
                try { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); } catch (\Throwable $ignored) {}
            }
        }
    }

    public static function page(string $cursor = ''): array
    {
        self::flush(true); // Persist age/scope pruning even when no new repairs occur.
        $ring = self::load();
        $after = 0;
        $gap = false;
        if ($cursor !== '') {
            if (!preg_match('/^([a-f0-9]{16}):([0-9]{1,16})$/D', $cursor, $parts)) throw new \InvalidArgumentException('Invalid diagnostic cursor');
            if ($parts[1] === $ring['stream']) $after = (int) $parts[2];
            else $gap = true;
        }
        $first = $ring['events'][0]['seq'] ?? $ring['next'];
        if ($after < $first - 1 || $after >= $ring['next']) $gap = true;
        if ($after >= $ring['next']) $after = $first - 1;
        $events = [];
        foreach ($ring['events'] as $event) {
            if ($event['seq'] > $after && count($events) < 50) $events[] = $event;
        }
        $last = $events ? $events[count($events) - 1]['seq'] : max($after, $first - 1);
        $response = ['schema_version' => 1, 'site_url' => untrailingslashit(get_site_url()),
            'vendor_id' => (int) (syncBasalamSettings()->getSettings()[SettingsConfig::VENDOR_ID] ?? 0),
            'stream' => $ring['stream'], 'events' => $events, 'gap' => $gap,
            'next_cursor' => $ring['stream'] . ':' . $last, 'has_more' => $last < $ring['next'] - 1];
        if (strlen(json_encode($response)) > 65536) throw new \RuntimeException('Diagnostic response limit');
        return $response;
    }

    /** Called from diagnostics, never repair: a slow logger affects only diagnostics. */
    public static function logRecovered(array $events): void
    {
        try {
            $logger = self::$logger ?: new WooLogger();
            foreach (array_slice($events, 0, 50) as $event) $logger->info('woosalam_monitoring', $event);
        } catch (\Throwable $ignored) {}
    }
}
