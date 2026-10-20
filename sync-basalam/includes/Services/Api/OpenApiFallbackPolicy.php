<?php

namespace SyncBasalam\Services\Api;

defined('ABSPATH') || exit;

/** Site-wide rolling failure window and fixed service-routing cooldown. */
class OpenApiFallbackPolicy
{
    public const OPTION_KEY = 'sync_basalam_openapi_fallback_policy';
    public const FAILURE_THRESHOLD = 10;
    public const WINDOW_SECONDS = 600;
    public const COOLDOWN_SECONDS = 172800;

    /** @var callable */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    public function isActive(): bool
    {
        return ($this->loadState()['fallback_until'] ?? 0) > ($this->clock)();
    }

    public function recordConnectionFailure(): void
    {
        global $wpdb;

        // Include the site's options table: workers share a lock, sites do not.
        $lockName = 'sync_basalam_openapi_' . md5((defined('DB_NAME') ? DB_NAME : '') . ':' . $wpdb->options);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Serialize only the short state update, never an HTTP request.
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 2));
        if ((string) $locked !== '1') return; // Immediate per-request fallback remains available.

        try {
            $now = ($this->clock)();
            $state = $this->loadState();
            // Another worker may have activated routing while this request was in flight.
            if (($state['fallback_until'] ?? 0) > $now) return;

            $failures = array_values(array_filter((array) ($state['failures'] ?? []), static function ($at) use ($now): bool {
                return is_int($at) && $at >= $now - self::WINDOW_SECONDS && $at <= $now;
            }));
            $failures[] = $now;
            $until = count($failures) >= self::FAILURE_THRESHOLD ? $now + self::COOLDOWN_SECONDS : 0;
            $state = ['failures' => $until ? [] : $failures, 'fallback_until' => $until];

            // This private JSON option is read/written directly, bypassing both
            // persistent and request-local option caches to avoid stale worker state.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WordPress-owned table identifier; all values are prepared.
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'",
                self::OPTION_KEY,
                json_encode($state)
            ));
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Always release the connection-scoped advisory lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    private function loadState(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- See the cache/concurrency note above.
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::OPTION_KEY
        ));
        $state = is_string($stored) ? json_decode($stored, true) : null;
        return is_array($state) ? $state : [];
    }
}
