<?php

namespace SyncBasalam\Admin\Settings;

use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class OAuthManager
{
    private static $oauthCache = null;

    public static function getOauthData($forceRefresh = false)
    {
        if (!$forceRefresh && self::$oauthCache !== null) {
            return self::$oauthCache;
        }

        $apiservice = new ApiServiceManager();
        $request = $apiservice->sendGetRequest('https://api.hamsalam.ir/api/v1/basalam-proxy/wp-oauth-data');
        $clientId = $request['body']['client_id'] ?? 779;
        $redirectUri = $request['body']['redirect_uri'] ?? 'https://api.hamsalam.ir/api/v1/basalam-proxy/wp-get-token';

        self::$oauthCache = [
            'client_id'    => $clientId,
            'redirect_uri' => $redirectUri,
        ];

        return self::$oauthCache;
    }

    public static function saveOauthData()
    {
        $isVendor = isset($_GET['is_vendor']) ? sanitize_text_field(wp_unslash($_GET['is_vendor'])) : true;
        $vendorId = sanitize_text_field(isset($_GET['vendor_id'])) ? sanitize_text_field(intval($_GET['vendor_id'])) : null;
        $hamsalamToken = sanitize_text_field(isset($_GET['hamsalam_token'])) ? sanitize_text_field(wp_unslash($_GET['hamsalam_token'])) : null;
        $hamsalamBusinessId = sanitize_text_field(isset($_GET['hamsalam_business_id'])) ? sanitize_text_field(wp_unslash($_GET['hamsalam_business_id'])) : null;
        $accessToken = sanitize_text_field(isset($_GET['access_token'])) ? sanitize_text_field(wp_unslash($_GET['access_token'])) : null;
        $refreshToken = sanitize_text_field(isset($_GET['refresh_token'])) ? sanitize_text_field(wp_unslash($_GET['refresh_token'])) : null;
        $expiresIn = sanitize_text_field(isset($_GET['expires_in'])) ? sanitize_text_field(intval($_GET['expires_in'])) : null;

        if ($isVendor == 'false') {
            $data = [SettingsConfig::IS_VENDOR => false];
            SettingsManager::updateSettings($data);
            wp_redirect(admin_url('admin.php?page=sync_basalam'));
            exit();
        }

        if (!$vendorId || !$accessToken || !$refreshToken || !$hamsalamToken || !$hamsalamBusinessId || !$expiresIn) return false;

        $data = [
            SettingsConfig::VENDOR_ID         => $vendorId,
            SettingsConfig::IS_VENDOR         => true,
            SettingsConfig::TOKEN             => $accessToken,
            SettingsConfig::REFRESH_TOKEN     => $refreshToken,
            SettingsConfig::HAMSALAM_TOKEN => $hamsalamToken,
            SettingsConfig::HAMSALAM_BUSINESS_ID => $hamsalamBusinessId,
            SettingsConfig::EXPIRE_TOKEN_TIME => $expiresIn,
        ];

        SettingsManager::updateSettings($data);

        return true;
    }

    public static function getOAuthUrls()
    {
        $oauthData = self::getOauthData();
        $siteUrl = get_site_url();

        $scopes = "vendor.product.write vendor.parcel.write customer.profile.read vendor.profile.read vendor.parcel.read";

        return [
            'redirect_uri' => $oauthData['redirect_uri'],
            'url_req_token' => "https://basalam.com/accounts/sso?client_id={$oauthData['client_id']}&scope=$scopes&redirect_uri={$oauthData['redirect_uri']}&state=$siteUrl",
        ];
    }
}
