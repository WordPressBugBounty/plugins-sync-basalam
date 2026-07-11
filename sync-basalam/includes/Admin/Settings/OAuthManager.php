<?php

namespace SyncBasalam\Admin\Settings;

use SyncBasalam\Config\Endpoints;
use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class OAuthManager
{
    /** Prefix for the per-user transient holding a pending OAuth authorization. */
    const OAUTH_STATE_TRANSIENT = 'sync_basalam_oauth_state_';

    /** Lifetime of a pending OAuth authorization — the SSO round-trip window. */
    const OAUTH_STATE_TTL = 600; // 10 * MINUTE_IN_SECONDS

    /**
     * Remember that the current admin has just started an OAuth authorization.
     *
     * This is called only from the nonce-protected initiation flow, so the
     * marker it stores cannot be planted by a forged cross-site request. The
     * callback later requires (and consumes) this marker, which is what turns
     * the token-saving callback from "always forgeable" into "only valid for a
     * flow this admin actually started".
     */
    public static function issueOauthState()
    {
        $state = wp_generate_password(64, false);
        set_transient(self::OAUTH_STATE_TRANSIENT . get_current_user_id(), $state, self::OAUTH_STATE_TTL);

        return $state;
    }

    /**
     * Validate and consume the pending OAuth authorization for the current user.
     *
     * Single use: the marker is deleted whether or not it was present, so a
     * replayed or forged callback cannot reuse it.
     */
    private static function verifyOauthState()
    {
        $key      = self::OAUTH_STATE_TRANSIENT . get_current_user_id();
        $expected = get_transient($key);
        delete_transient($key);

        // The token exchange is routed back through the Hamsalam proxy, which
        // consumes the SSO "state" (the site URL) and does not forward a secret
        // we control. The single-use marker set during the authenticated
        // initiation is therefore the value that authorises the write.
        return ! empty($expected);
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
