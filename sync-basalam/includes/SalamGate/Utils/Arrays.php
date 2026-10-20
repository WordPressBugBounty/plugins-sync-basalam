<?php

namespace SyncBasalam\SalamGate\Utils;

defined('ABSPATH') || exit;

final class Arrays
{
    /** Sequential integer keys, without requiring PHP 8.1 or a core polyfill. */
    public static function isList(array $items): bool
    {
        $expectedKey = 0;
        foreach ($items as $key => $value) {
            if ($key !== $expectedKey++) {
                return false;
            }
        }
        return true;
    }
}
