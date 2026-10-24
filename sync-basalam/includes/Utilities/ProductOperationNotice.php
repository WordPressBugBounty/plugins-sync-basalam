<?php

namespace SyncBasalam\Utilities;

defined('ABSPATH') || exit;

/** Carries the actual queue deletion result across the stop redirect. */
class ProductOperationNotice
{
    private const TRANSIENT_PREFIX = 'sync_basalam_product_stop_';

    public static function push(string $operation, int $deletedCount): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0 || !in_array($operation, ['create', 'update'], true)) return;

        set_transient(self::TRANSIENT_PREFIX . $userId, [
            'operation' => $operation,
            'deleted_count' => max(0, $deletedCount),
        ], 2 * MINUTE_IN_SECONDS);
    }

    public static function pull(): ?array
    {
        $userId = get_current_user_id();
        if ($userId <= 0) return null;

        $key = self::TRANSIENT_PREFIX . $userId;
        $notice = get_transient($key);
        if ($notice === false) return null;
        delete_transient($key);

        if (!is_array($notice)
            || !in_array($notice['operation'] ?? '', ['create', 'update'], true)
            || !isset($notice['deleted_count']) || !is_int($notice['deleted_count'])
            || $notice['deleted_count'] < 0) return null;

        return $notice;
    }
}
