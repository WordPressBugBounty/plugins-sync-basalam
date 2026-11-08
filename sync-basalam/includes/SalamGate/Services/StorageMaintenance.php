<?php
namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\SalamGate\Infrastructure\AtomicOptionStore;
use SyncBasalam\SalamGate\Infrastructure\CreationIdentityIndex;
use SyncBasalam\SalamGate\Utils\OptionLock;

defined('ABSPATH') || exit;

/** Bounded local work; never contacts a provider or expires financial evidence. */
final class StorageMaintenance
{
    public const HOOK = 'salam_gate_storage_maintenance';

    public static function register(): void
    {
        add_action('init', [self::class, 'schedule']);
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time() + 60, 'hourly', self::HOOK);
    }

    public static function run(): void
    {
        $key = 'salam_gate_storage_maintenance_lock';
        $lease = OptionLock::acquire($key);
        if ($lease === null) return;
        try {
            self::collect();
            CreationIdentityIndex::backfill();
        } catch (\Throwable $exception) {
            \SyncBasalam\SalamGate\Infrastructure\OperationLogger::failure('storage_maintenance', $exception);
        } finally { OptionLock::release($key, $lease); }
    }

    private static function deleteOldSnapshot(string $name, string $raw, ?string $settings): bool
    {
        global $wpdb;
        // Check credential bytes and cache bytes together: reconnecting cannot lose its display snapshot.
        $guard = $settings === null ? 's.option_name IS NULL' : 'BINARY s.option_value = BINARY %s';
        $arguments = $settings === null ? [$name, $raw] : [$name, $raw, $settings];
        $changed = $wpdb->query($wpdb->prepare(
            "DELETE c FROM `{$wpdb->options}` c LEFT JOIN `{$wpdb->options}` s ON s.option_name = 'sync_basalam_settings' WHERE c.option_name = %s AND BINARY c.option_value = BINARY %s AND {$guard}",
            ...$arguments
        ));
        if ($changed === false) throw new \RuntimeException('Cache snapshot deletion failed.');
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        return (int) $changed === 1;
    }

    public static function collect(int $limit = 100): int
    {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        $cursorKey = 'salam_gate_cache_gc_cursor';
        $cursor = get_option($cursorKey, '');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM `{$wpdb->options}` WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d",
            $wpdb->esc_like('salam_gate_conn_') . '%', is_string($cursor) ? $cursor : '', $limit
        ), ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) throw new \RuntimeException('Cache collection read failed.');
        $deleted = 0;
        foreach ($rows as $row) {
            $name = $row['option_name'];
            $cursor = $name;
            if (!preg_match('/^salam_gate_conn_([a-z_]+)_([a-f0-9]{12})$/D', $name, $match)) continue;
            $value = @unserialize($row['option_value'], ['allowed_classes' => false]);
            if (!is_array($value) || !isset($value['expires_at']) || (int) $value['expires_at'] > time()) continue;
            // Reread saved credentials before each deletion. Current shops also own the last-known UI snapshot.
            $settingsRaw = AtomicOptionStore::read('sync_basalam_settings');
            $settings = is_string($settingsRaw) ? @unserialize($settingsRaw, ['allowed_classes' => false]) : [];
            $token = is_array($settings) ? ($settings['token'] ?? '') : '';
            $current = substr(sha1(is_string($token) && $token !== '' ? $token : 'anonymous'), 0, 12);
            if ($match[1] === 'shops' && $match[2] === $current) continue;
            if ($match[1] === 'shops') {
                if (self::deleteOldSnapshot($name, $row['option_value'], $settingsRaw)) $deleted++;
            } elseif (AtomicOptionStore::succeeded(AtomicOptionStore::exchange($name, $row['option_value'], null))) $deleted++;
        }
        update_option($cursorKey, count($rows) < $limit ? '' : $cursor, false);
        return $deleted;
    }
}
