<?php
defined('ABSPATH') || exit;

use SyncBasalam\Admin\Settings\SettingsConfig;
use SyncBasalam\Services\ApiServiceManager;

$settings = syncBasalamSettings()->getSettings();
$syncBasalamToken = $settings[SettingsConfig::TOKEN];
$syncBasalamVendorId = $settings[SettingsConfig::VENDOR_ID];

if (!$syncBasalamVendorId && $syncBasalamToken) {
    require_once(syncBasalamPlugin()->templatePath() . "/admin/InfoNotVendor.php");
    return;
}

if (!$syncBasalamToken) {
    require_once(syncBasalamPlugin()->templatePath() . "/admin/InfoNotAuth.php");
    return;
}

$api_url = "https://openapi.basalam.com/v1/vendors/$syncBasalamVendorId";
$api_service = new ApiServiceManager();
$response = $api_service->sendGetRequest($api_url, ['Authorization' => 'Bearer ' . $syncBasalamToken]);
$response = json_decode($response['body'], true);
?>

<div class="wrap">
    <div class="basalam-info-top-section">
        <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/images/basalam-logotype.svg") ?>" alt="Basalam">
    </div>
    <?php require_once(syncBasalamPlugin()->templatePath() . "/admin/InfoConnected.php"); ?>
</div>