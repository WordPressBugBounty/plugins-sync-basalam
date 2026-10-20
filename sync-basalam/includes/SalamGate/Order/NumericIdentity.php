<?php

namespace SyncBasalam\SalamGate\Order;

defined('ABSPATH') || exit;

/** Numeric API identities shared by Woo orders and durable standalone attempts. */
final class NumericIdentity
{
    /** Keep IDs exact in PHP, JSON and JavaScript, and outside ordinary local order IDs. */
    public static function forKey(string $key): ?int
    {
        if ($key === '' || !function_exists('home_url')) {
            return null;
        }
        $site = rtrim((string) home_url('/'), '/');
        if ($site === '') {
            return null;
        }
        $blog = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        $hash = hash('sha256', 'woosalam-salamgate-v1|' . $site . '|' . $blog . '|' . $key);

        // 52 digest bits plus a fixed high bit: 2^52 <= id <= 2^53-1.
        // Validate the complete value before returning an integer; never truncate an ID.
        $id = filter_var(sprintf('%.0f', hexdec('1' . substr($hash, 0, 13))), FILTER_VALIDATE_INT);
        return $id === false ? null : $id;
    }
}
