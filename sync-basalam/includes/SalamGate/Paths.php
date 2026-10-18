<?php

namespace SyncBasalam\SalamGate;

defined('ABSPATH') || exit;

/** Resolve the module's resources without depending on the installation folder name. */
final class Paths
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function assetPath(string $relative): string
    {
        return self::root() . '/assets/salam-gate/' . ltrim($relative, '/');
    }

    public static function assetsUrl(string $relative): string
    {
        return plugins_url('assets/salam-gate/' . ltrim($relative, '/'), self::root() . '/sync-basalam.php');
    }

    public static function templatePath(string $relative): string
    {
        return self::root() . '/templates/' . ltrim($relative, '/');
    }
}
