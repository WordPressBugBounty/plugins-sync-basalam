<?php

namespace SyncBasalam\SalamGate\Utils;

defined('ABSPATH') || exit;

/** Database lease: unique insert and compare-and-swap; never get/set a transient. */
final class OptionLock
{
    /** The returned opaque lease must be supplied unchanged to renew/release. */
    public static function acquire(string $key, int $ttl = 600): ?string
    {
        global $wpdb;

        $lease = self::newLease(bin2hex(random_bytes(16)), $ttl);
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $key,
            $lease,
            'no'
        ));
        self::flushCache($key);
        if ((int) $inserted === 1) {
            return $lease;
        }
        if ($inserted === false) {
            return null;
        }

        // Read the authoritative row, not a possibly stale object-cache entry.
        $old = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1",
            $key
        ));
        $decoded = is_string($old) ? json_decode($old, true) : null;
        if (!is_array($decoded) || !isset($decoded['expires_at']) || (int) $decoded['expires_at'] > time()) {
            return null;
        }

        // A competing worker may replace an expired lease; only one exact CAS wins.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
            $lease,
            $key,
            $old
        ));
        self::flushCache($key);

        return (int) $claimed === 1 ? $lease : null;
    }

    public static function renew(string $key, string $lease, int $ttl = 600): ?string
    {
        global $wpdb;

        $decoded = json_decode($lease, true);
        if (!is_array($decoded) || empty($decoded['owner']) || (int) ($decoded['expires_at'] ?? 0) <= time()) {
            return null;
        }
        // Increase by at least one second so wpdb's affected-row count is reliable.
        $renewed = self::newLease((string) $decoded['owner'], $ttl, (int) $decoded['expires_at']);
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
            $renewed,
            $key,
            $lease
        ));
        self::flushCache($key);

        return (int) $changed === 1 ? $renewed : null;
    }

    public static function release(string $key, string $lease): bool
    {
        global $wpdb;

        // An expired worker can never delete its successor's lease.
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND BINARY option_value = BINARY %s",
            $key,
            $lease
        ));
        self::flushCache($key);

        return (int) $deleted === 1;
    }

    private static function newLease(string $owner, int $ttl, int $previousExpiry = 0): string
    {
        return (string) json_encode([
            'owner' => $owner,
            'expires_at' => max(time() + max(1, $ttl), $previousExpiry + 1),
        ]);
    }

    private static function flushCache(string $key): void
    {
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
