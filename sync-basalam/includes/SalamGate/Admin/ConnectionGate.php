<?php

namespace SyncBasalam\SalamGate\Admin;

use SyncBasalam\Admin\Settings\SettingsConfig;

defined('ABSPATH') || exit;

/**
 * Connection presentation override for the WooSalam dashboard's existing hook.
 * Does not refresh, delete or rotate credentials, or change OAuth contracts.
 */
final class ConnectionGate
{
    // Read-only Basalam account identity endpoint.
    private const IDENTITY_URL = 'https://core.basalam.com/v3/users/me';
    private const CACHE_PREFIX = 'woosalam_connection_ui_';
    private const CACHE_TTL = 60;

    public static function register(): void
    {
        // Runs after the non-vendor PRO template filter; a stale is_vendor value
        // must not show account operations when connection credentials are absent.
        add_filter('sync_basalam_token_template', [self::class, 'filterWoosalamTemplate'], 20);
    }

    public static function filterWoosalamTemplate(?string $template): ?string
    {
        // Never probe from init or unrelated admin pages, or for unauthorized users.
        if (($_GET['page'] ?? '') !== 'sync_basalam' || !current_user_can('manage_options')) {
            return $template;
        }

        $state = self::woosalamState();
        if ($state === '') {
            return $template;
        }

        // Request-local state only. No credential or provider response enters HTML.
        self::$state = $state;

        return dirname(__DIR__, 3) . '/templates/Woosalam/connection-lock.php';
    }

    private static string $state = 'not_connected';

    public static function currentState(): string
    {
        return self::$state;
    }

    private static function woosalamState(): string
    {
        $settings = syncBasalamSettings();
        $access = $settings->getSettings(SettingsConfig::TOKEN);
        $refresh = $settings->getSettings(SettingsConfig::REFRESH_TOKEN);
        if (!is_string($access) || trim($access) === '' || !is_string($refresh) || trim($refresh) === '') {
            return 'not_connected';
        }

        // Bind even a negative result to this exact connection. Cache only a
        // short status, never the profile, token or a raw API/error response.
        $connection = strlen($access) . ':' . $access . strlen($refresh) . ':' . $refresh;
        $key = self::CACHE_PREFIX . substr(hash_hmac('sha256', $connection, wp_salt('auth')), 0, 32);
        $cached = get_transient($key);
        if (in_array($cached, ['available', 'error_auth', 'unverified'], true)) {
            return $cached === 'error_auth' ? 'error_auth' : '';
        }

        try {
            $response = wp_remote_get(self::IDENTITY_URL, [
                'timeout' => 5,
                'redirection' => 0,
                'sslverify' => true,
                'headers' => ['Authorization' => 'Bearer ' . $access, 'Accept' => 'application/json'],
            ]);
            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        } catch (\Throwable $exception) {
            $code = 0;
        }

        // 403 can mean a missing endpoint scope, not an invalid connection.
        // Network/5xx/429/other failures must never be represented as expiry.
        $status = $code === 401 ? 'error_auth' : ($code === 200 ? 'available' : 'unverified');
        set_transient($key, $status, self::CACHE_TTL);

        return $status === 'error_auth' ? 'error_auth' : '';
    }
}
