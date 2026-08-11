<?php

namespace SyncBasalam\Utilities;

defined('ABSPATH') || exit;

class TicketExtraInfoFormatter
{
    public static function sanitizeContent($value): string
    {
        $value = wp_check_invalid_utf8(wp_unslash((string) $value));

        if (strpos($value, '<') !== false) {
            $value = wp_pre_kses_less_than($value);
            $value = wp_strip_all_tags($value, false);
            $value = str_replace("<\n", "&lt;\n", $value);
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        return trim($value);
    }

    public static function appendFromRequest(string $content, array $request): string
    {
        // Kept as a compatibility shim for third-party callers. Access data is
        // intentionally ignored and must be sent with TicketAccessData instead.
        return trim($content);
    }
}
