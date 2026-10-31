<?php

namespace SyncBasalam\SalamGate\Services;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\SalamGate\Contracts\ShopStatuses;
use SyncBasalam\SalamGate\SalamGateException;

defined('ABSPATH') || exit;

/** Local eligibility for read-only reconciliation, never authorization for shipping/payment. */
final class StatusSyncAccess
{
    public const CACHE_NAME = 'status_sync_failure';
    public const AUTH_BACKOFF_TTL = 21600;

    /** No client creation, OAuth refresh, account probe or remote request on this path. */
    public static function snapshot(?string $expectedIdentity = null): array
    {
        if (!SiteActivation::isEnabled()) return ['reason' => 'site_disabled', 'identity' => null];
        $settings = get_option('sync_basalam_settings', []);
        $token = is_array($settings) ? ($settings[SettingsConfig::TOKEN] ?? null) : null;
        if (!is_string($token) || trim($token) === '') return ['reason' => 'no_token', 'identity' => null];
        $identity = substr(sha1($token), 0, 12);
        if ($expectedIdentity !== null && $expectedIdentity !== $identity) {
            return ['reason' => 'connection_changed', 'identity' => $identity];
        }
        if (ShopCheckCache::forToken($token) !== null) {
            return ['reason' => 'account_unavailable', 'identity' => $identity];
        }
        if (ConnectionCache::getForToken(self::CACHE_NAME, $token) !== null) {
            return ['reason' => 'auth_backoff', 'identity' => $identity];
        }

        // Requiring the ten-minute page cache to be fresh would stop an hourly
        // worker whenever the merchant closes the dashboard. A last verified
        // active snapshot only permits an authenticated GET attempt; the API
        // still enforces access, and any known denial above takes precedence.
        $cached = ConnectionCache::lastForTokenForDisplay(ShopsService::CACHE_NAME, $token);
        foreach (is_array($cached['data']['shops'] ?? null) ? $cached['data']['shops'] : [] as $shop) {
            if (is_array($shop) && (int) ($shop['status_id'] ?? 0) === ShopStatuses::ACTIVE
                && (int) ($shop['id'] ?? 0) > 0) {
                return ['reason' => '', 'identity' => $identity];
            }
        }
        return ['reason' => 'account_unavailable', 'identity' => $identity];
    }

    /** A generic parcel 403 pauses sync only; it is not proof of missing OAuth scope. */
    public static function remember(SalamGateException $error, string $identity): void
    {
        if (!in_array($error->getStatus(), [401, 403], true) || $error->isDefinitelyNotDispatched()) return;
        ConnectionCache::assertCurrentIdentity($identity);
        if ($error->getStatus() === 401 || $error->errorsAt('auth.scope') !== []) {
            // Explicit account failures also invalidate the active UI snapshot.
            ShopCheckCache::remember($error, $identity);
        }
        ConnectionCache::setForIdentity(self::CACHE_NAME, [
            'status' => $error->getStatus(), 'retry_at' => time() + self::AUTH_BACKOFF_TTL,
        ], self::AUTH_BACKOFF_TTL, $identity);
    }

    /** Called only after a real successful account read for this exact token. */
    public static function clear(string $identity): void
    {
        ConnectionCache::assertCurrentIdentity($identity);
        ConnectionCache::deleteForIdentity(self::CACHE_NAME, $identity);
    }
}
