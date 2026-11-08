<?php

namespace SyncBasalam\SalamGate\Utils;

use SyncBasalam\SalamGate\Infrastructure\AtomicOptionStore;

defined('ABSPATH') || exit;

/** Database lease: unique insert and compare-and-swap; never get/set a transient. */
final class OptionLock
{
    /** The returned opaque lease must be supplied unchanged to renew/release. */
    public static function acquire(string $key, int $ttl = 600): ?string
    {
        $lease = self::newLease(bin2hex(random_bytes(16)), $ttl);
        $inserted = AtomicOptionStore::exchange($key, null, $lease);
        // Preserve the nullable lease contract on an unavailable datastore; no work is dispatched.
        if ($inserted === AtomicOptionStore::STORAGE_ERROR) { return null; }
        if (AtomicOptionStore::succeeded($inserted)) { return $lease; }
        try { $old = AtomicOptionStore::read($key); }
        catch (\RuntimeException $error) { return null; }
        $decoded = is_string($old) ? json_decode($old, true) : null;
        if (!is_array($decoded) || !isset($decoded['expires_at']) || (int) $decoded['expires_at'] > time()) {
            return null;
        }
        return AtomicOptionStore::exchange($key, $old, $lease) === AtomicOptionStore::SUCCESS ? $lease : null;
    }

    public static function renew(string $key, string $lease, int $ttl = 600): ?string
    {
        $decoded = json_decode($lease, true);
        if (!is_array($decoded) || empty($decoded['owner']) || (int) ($decoded['expires_at'] ?? 0) <= time()) {
            return null;
        }
        $renewed = self::newLease((string) $decoded['owner'], $ttl, (int) $decoded['expires_at']);
        return AtomicOptionStore::exchange($key, $lease, $renewed) === AtomicOptionStore::SUCCESS ? $renewed : null;
    }

    public static function release(string $key, string $lease): bool
    {
        return AtomicOptionStore::exchange($key, $lease, null) === AtomicOptionStore::SUCCESS;
    }

    private static function newLease(string $owner, int $ttl, int $previousExpiry = 0): string
    {
        return (string) json_encode([
            'owner' => $owner,
            'expires_at' => max(time() + max(1, $ttl), $previousExpiry + 1),
        ]);
    }

}
