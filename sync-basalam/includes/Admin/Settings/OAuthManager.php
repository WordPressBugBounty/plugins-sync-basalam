<?php

namespace SyncBasalam\Admin\Settings;

use SyncBasalam\Services\ApiServiceManager;

defined('ABSPATH') || exit;

class OAuthManager
{
    public function getOauthData()
    {
        $oauthDataUrl = apply_filters('sync_basalam_oauth_data_url', 'https://api.hamsalam.ir/api/v1/basalam-proxy/wp-oauth-data');
        $defaultClientId = apply_filters('sync_basalam_oauth_default_client_id', 779);
        $defaultRedirectUri = apply_filters('sync_basalam_oauth_default_redirect_uri', 'https://api.hamsalam.ir/api/v1/basalam-proxy/wp-get-token');

        try {
            $apiservice = new ApiServiceManager();
            $request = $apiservice->sendGetRequest($oauthDataUrl);
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
            $data = apply_filters('sync_basalam_oauth_non_vendor_data', $data, $vendorId , $accessToken, $refreshToken, $extraData);
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

        $scopes = "vendor.product.write vendor.parcel.write customer.profile.read vendor.profile.read vendor.parcel.read vendor.profile.write";

        return [
            'redirect_uri' => $oauthData['redirect_uri'],
            'url_req_token' => "https://basalam.com/accounts/sso?client_id={$oauthData['client_id']}&scope=$scopes&redirect_uri={$oauthData['redirect_uri']}&state=$siteUrl",
        ];
    }
}
