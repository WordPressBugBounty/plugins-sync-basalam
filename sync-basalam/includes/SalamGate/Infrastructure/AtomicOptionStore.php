<?php

namespace SyncBasalam\SalamGate\Infrastructure;

defined('ABSPATH') || exit;

/** Raw option bytes; callers own serialization and the lifetime of their records. */
final class AtomicOptionStore
{
    public const SUCCESS = 'success';
    public const CONFLICT = 'conflict';
    public const STORAGE_ERROR = 'storage_error';

    public static function read(string $key)
    {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->options)) {
            throw new \RuntimeException('Option datastore is unavailable.');
        }
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", $key
        ));
        if (!empty($wpdb->last_error)) {
            throw new \RuntimeException('Option record could not be read.');
        }
        return $raw;
    }

    /** A null previous value means unique insert; a null next value means exact delete. */
    public static function exchange(string $key, ?string $previous, ?string $next): string
    {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->options) || ($previous === null && $next === null)) {
            return self::STORAGE_ERROR;
        }
        $sql = $previous === null
            ? $wpdb->prepare("INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)", $key, $next, 'no')
            : ($next === null
                ? $wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND BINARY option_value = BINARY %s", $key, $previous)
                : $wpdb->prepare("UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $next, $key, $previous));
        $changed = $wpdb->query($sql);
        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        if ($changed === false) { return self::STORAGE_ERROR; }
        return (int) $changed === 1 ? self::SUCCESS : self::CONFLICT;
    }

    /** Never mistake a failed datastore for another worker winning a claim. */
    public static function succeeded(string $result): bool
    {
        if ($result === self::STORAGE_ERROR) {
            throw new \RuntimeException('Option record could not be persisted.');
        }
        return $result === self::SUCCESS;
    }
}
