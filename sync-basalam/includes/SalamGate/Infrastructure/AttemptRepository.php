<?php

namespace SyncBasalam\SalamGate\Infrastructure;

defined('ABSPATH') || exit;

/** Persistent attempt evidence. No TTL, key migration or automatic deletion. */
final class AttemptRepository
{
    public static function read(string $key): ?array
    {
        $raw = AtomicOptionStore::read($key);
        $record = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : $raw;
        return is_array($record) ? $record : null;
    }

    public static function exchange(string $key, ?array $previous, ?array $next): bool
    {
        return AtomicOptionStore::succeeded(AtomicOptionStore::exchange(
            $key, $previous === null ? null : serialize($previous), $next === null ? null : serialize($next)
        ));
    }
}
