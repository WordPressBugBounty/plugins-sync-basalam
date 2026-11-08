<?php

namespace SyncBasalam\SalamGate\Infrastructure;

use SyncBasalam\Plugin;

defined('ABSPATH') || exit;

/** Identify the ordinary plugin consistently across shipping HTTP clients. */
final class HttpIdentity
{
    public static function userAgent(): string
    {
        return 'Woosalam/' . Plugin::VERSION;
    }
}
