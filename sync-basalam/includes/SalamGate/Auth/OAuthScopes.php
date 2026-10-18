<?php

namespace SyncBasalam\SalamGate\Auth;

defined('ABSPATH') || exit;

/** Add ordinary SalamGate consent to the base plugin's existing OAuth flow. */
final class OAuthScopes
{
    public const REQUIRED = ['vendor.salamgate.read', 'vendor.salamgate.write'];

    public static function register(): void
    {
        add_filter('sync_basalam_oauth_scopes', [self::class, 'includeRequired'], 20);
    }

    public static function includeRequired(string $scopes): string
    {
        $requested = preg_split('/\s+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY);
        foreach (self::REQUIRED as $scope) {
            if (!in_array($scope, $requested, true)) {
                $requested[] = $scope;
            }
        }

        // Keep all caller-supplied grants. Never request .admin scopes ourselves,
        // refresh a token, or change stored credentials merely to build a URL.
        return implode(' ', $requested);
    }
}
