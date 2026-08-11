<?php

namespace SyncBasalam\Utilities;

defined('ABSPATH') || exit;

/**
 * Carries one ticket-flow message across an admin redirect.
 */
class TicketFlashNotice
{
    private const TRANSIENT_PREFIX = 'sync_basalam_ticket_flash_';
    private const ALLOWED_TYPES = ['success', 'error', 'warning', 'info'];

    public static function push(string $message, string $type = 'error'): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) return;

        $type = in_array($type, self::ALLOWED_TYPES, true) ? $type : 'info';
        set_transient(
            self::TRANSIENT_PREFIX . $userId,
            [
                'message' => wp_strip_all_tags($message),
                'type' => $type,
            ],
            2 * MINUTE_IN_SECONDS
        );
    }

    public static function pull(): ?array
    {
        $userId = get_current_user_id();
        if ($userId <= 0) return null;

        $key = self::TRANSIENT_PREFIX . $userId;
        $notice = get_transient($key);
        if ($notice === false) return null;

        delete_transient($key);
        if (!is_array($notice) || empty($notice['message'])) return null;

        $type = isset($notice['type']) && in_array($notice['type'], self::ALLOWED_TYPES, true)
            ? $notice['type']
            : 'info';

        return [
            'message' => (string) $notice['message'],
            'type' => $type,
        ];
    }
}
