<?php

namespace SyncBasalam\Admin\Settings;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class OAuthManager
{
    /** Signed, browser-bound proof that an administrator started OAuth. */
    const OAUTH_STATE_COOKIE = 'sync_basalam_oauth_state';

    /** Lifetime of a pending OAuth authorization — the SSO round-trip window. */
    const OAUTH_STATE_TTL = 600; // 10 * MINUTE_IN_SECONDS

    /**
     * Remember that the current admin has just started an OAuth authorization.
     *
     * This is called only from the nonce-protected initiation flow, so the
     * cookie it stores cannot be planted by a forged cross-site request. The
     * callback later requires (and consumes) this cookie, which is what turns
     * the token-saving callback from "always forgeable" into "only valid for a
     * flow this admin actually started".
     *
     * The proof deliberately lives in a signed HttpOnly cookie instead of a
     * WordPress transient. Sites with a persistent object-cache drop-in route
     * transients through Redis/Memcached, where a failed write, eviction, or
     * cache flush during the OAuth round trip would otherwise invalidate a
     * legitimate callback.
     */
    public static function issueOauthState()
    {
        $userId = get_current_user_id();
        if ($userId <= 0) return false;

        $state     = wp_generate_password(64, false);
        $expiresAt = time() + self::OAUTH_STATE_TTL;
        $value     = self::buildOauthStateCookieValue($state, $userId, $expiresAt);

        if (! self::writeOauthStateCookie($value, $expiresAt)) return false;

        return $state;
    }

    /**
     * Validate and consume the pending OAuth authorization for the current user.
     *
     * Single use: the cookie is deleted whether or not it was valid, so a
     * replayed or forged callback cannot reuse it.
     */
    private static function verifyOauthState()
    {
        $value = isset($_COOKIE[self::OAUTH_STATE_COOKIE])
            ? (string) wp_unslash($_COOKIE[self::OAUTH_STATE_COOKIE])
            : '';

        self::clearOauthStateCookie();

        return self::isOauthStateCookieValid(
            $value,
            get_current_user_id(),
            time()
        );
    }

    private static function buildOauthStateCookieValue($state, $userId, $expiresAt)
    {
        $payload = json_encode([
            'state'      => (string) $state,
            'user_id'    => (int) $userId,
            'expires_at' => (int) $expiresAt,
        ]);

        if (! is_string($payload)) return '';

        $encodedPayload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature      = hash_hmac('sha256', $encodedPayload, wp_salt('auth'));

        return $encodedPayload . '.' . $signature;
    }

    private static function isOauthStateCookieValid($value, $userId, $now)
    {
        if (! is_string($value) || $value === '' || (int) $userId <= 0) return false;

        $parts = explode('.', $value, 2);
        if (count($parts) !== 2) return false;

        [$encodedPayload, $signature] = $parts;
        $expectedSignature = hash_hmac('sha256', $encodedPayload, wp_salt('auth'));

        if (! hash_equals($expectedSignature, $signature)) return false;

        $padding = strlen($encodedPayload) % 4;
        if ($padding !== 0) $encodedPayload .= str_repeat('=', 4 - $padding);

        $payload = base64_decode(strtr($encodedPayload, '-_', '+/'), true);
        $data    = is_string($payload) ? json_decode($payload, true) : null;

        if (! is_array($data)) return false;

        return ! empty($data['state'])
            && (int) ($data['user_id'] ?? 0) === (int) $userId
            && (int) ($data['expires_at'] ?? 0) >= (int) $now;
    }

    private static function writeOauthStateCookie($value, $expiresAt)
    {
        if (! is_string($value) || $value === '' || headers_sent()) return false;

        $written = setcookie(self::OAUTH_STATE_COOKIE, $value, [
            'expires'  => (int) $expiresAt,
            'path'     => self::oauthStateCookiePath(),
            'domain'   => self::oauthStateCookieDomain(),
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Keep the current request internally consistent for callers and tests.
        if ($written) $_COOKIE[self::OAUTH_STATE_COOKIE] = $value;

        return $written;
    }

    private static function clearOauthStateCookie()
    {
        unset($_COOKIE[self::OAUTH_STATE_COOKIE]);

        if (headers_sent()) return;

        setcookie(self::OAUTH_STATE_COOKIE, '', [
            'expires'  => time() - HOUR_IN_SECONDS,
            'path'     => self::oauthStateCookiePath(),
            'domain'   => self::oauthStateCookieDomain(),
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function oauthStateCookiePath()
    {
        return defined('ADMIN_COOKIE_PATH') && ADMIN_COOKIE_PATH
            ? ADMIN_COOKIE_PATH
            : '/wp-admin';
    }

    private static function oauthStateCookieDomain()
    {
        return defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : '';
    }

    public function getOauthData()
    {
        $oauthDataUrl = apply_filters('sync_basalam_oauth_data_url', Endpoints::HAMSALAM_OAUTH_DATA);
        $defaultClientId = apply_filters('sync_basalam_oauth_default_client_id', 779);
        $defaultRedirectUri = apply_filters('sync_basalam_oauth_default_redirect_uri', Endpoints::HAMSALAM_OAUTH_TOKEN);

        try {
            $apiservice = syncBasalamContainer()->get(ApiServiceManager::class);
            $request = $apiservice->get($oauthDataUrl);
            $clientId = $request['body']['client_id'] ?? $defaultClientId;
            $redirectUri = $request['body']['redirect_uri'] ?? $defaultRedirectUri;
        } catch (\Throwable $th) {
            $clientId = $defaultClientId;
            $redirectUri = $defaultRedirectUri;
        }

        return [
            'client_id'    => $clientId,
            'redirect_uri' => $redirectUri,
        ];
    }

    public static function saveOauthData()
    {
        // CSRF protection: this callback performs a state-changing write from a
        // plain GET, so it must be tied to an OAuth flow the current admin
        // actually initiated. Without this an attacker could lure a logged-in
        // admin to the callback URL and overwrite the stored Basalam credentials.
        if (! current_user_can('manage_options') || ! self::verifyOauthState()) {
            wp_die(
                esc_html__('درخواست نامعتبر است.', 'sync-basalam'),
                esc_html__('خطای امنیتی', 'sync-basalam'),
                ['response' => 403]
            );
        }

        $isVendor = isset($_GET['is_vendor']) ? sanitize_text_field(wp_unslash($_GET['is_vendor'])) : true;
        $vendorId = isset($_GET['vendor_id']) ? sanitize_text_field(intval($_GET['vendor_id'])) : null;
        $hamsalamToken = isset($_GET['hamsalam_token']) ? sanitize_text_field(wp_unslash($_GET['hamsalam_token'])) : null;
        $hamsalamBusinessId = isset($_GET['hamsalam_business_id']) ? sanitize_text_field(wp_unslash($_GET['hamsalam_business_id'])) : null;
        $accessToken = isset($_GET['access_token']) ? sanitize_text_field(wp_unslash($_GET['access_token'])) : null;
        $refreshToken = isset($_GET['refresh_token']) ? sanitize_text_field(wp_unslash($_GET['refresh_token'])) : null;
        $expiresIn = isset($_GET['expires_in']) ? sanitize_text_field(intval($_GET['expires_in'])) : null;

        // Allow pro version to handle custom fields
        $extraData = apply_filters('sync_basalam_oauth_save_extra_data', []);

        if ($isVendor == 'false') {
            $data = [SettingsConfig::IS_VENDOR => false];
            $data = apply_filters('sync_basalam_oauth_non_vendor_data', $data, $vendorId, $accessToken, $refreshToken, $extraData);
            SettingsManager::updateSettings($data);
            return true;
        }

        $data = [
            SettingsConfig::VENDOR_ID         => $vendorId,
            SettingsConfig::IS_VENDOR         => $isVendor,
            SettingsConfig::TOKEN             => $accessToken,
            SettingsConfig::REFRESH_TOKEN     => $refreshToken,
            SettingsConfig::HAMSALAM_TOKEN => $hamsalamToken,
            SettingsConfig::HAMSALAM_BUSINESS_ID => $hamsalamBusinessId,
            SettingsConfig::EXPIRE_TOKEN_TIME => $expiresIn,
        ];

        $data = array_merge($data, $extraData);

        SettingsManager::updateSettings($data);

        return true;
    }

    public function getOAuthUrls()
    {
        $oauthData = $this->getOauthData();
        $siteUrl = get_site_url();

        $scopes = apply_filters('sync_basalam_oauth_scopes', "vendor.product.write vendor.parcel.write customer.profile.read vendor.profile.read vendor.parcel.read vendor.profile.write customer.chat.read customer.chat.write customer.identity.read");

        return [
            'redirect_uri' => $oauthData['redirect_uri'],
            'url_req_token' => Endpoints::oauthLoginUrl(
                $oauthData['client_id'],
                $scopes,
                $oauthData['redirect_uri'],
                $siteUrl
            ),
        ];
    }
}
