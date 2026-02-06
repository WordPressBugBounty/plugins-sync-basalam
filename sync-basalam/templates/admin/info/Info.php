<?php
defined('ABSPATH') || exit;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\ApiServiceManager;

$settings = syncBasalamSettings()->getSettings();
$BasalamAccessToken = $settings[SettingsConfig::TOKEN];
$syncBasalamVendorId = $settings[SettingsConfig::VENDOR_ID];

if (!$syncBasalamVendorId && $BasalamAccessToken) {
    require_once(syncBasalamPlugin()->templatePath() . "/admin/info/InfoNotVendor.php");
    return;
}

$apiUrl = "https://openapi.basalam.com/v1/vendors/$syncBasalamVendorId";
$apiService = new ApiServiceManager();
$response = $apiService->sendGetRequest($apiUrl, ['Authorization' => 'Bearer ' . $BasalamAccessToken]);
$response = json_decode($response['body'], true);
?>

<div class="wrap">
    <div class="basalam-info-top-section">
        <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/images/basalam-logotype.svg") ?>" alt="Basalam">
    </div>
    <?php require_once(syncBasalamPlugin()->templatePath() . "/admin/info/InfoConnected.php"); ?>
</div>