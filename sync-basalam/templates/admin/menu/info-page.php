<?php
if (! defined('ABSPATH')) exit;
$sync_basalam_token = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::TOKEN);
$sync_basalam_vendor_id = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::VENDOR_ID);
$logo_filename = 'basalam-logotype.svg';

if (!$sync_basalam_vendor_id && $sync_basalam_token) {
    require_once(sync_basalam_configure()->template_path() . "/admin/menu/info/info-not-vendor.php");
    return;
}

if (!$sync_basalam_token) {
    require_once(sync_basalam_configure()->template_path() . "/admin/menu/info/info-not-auth.php");
    return;
}
// Get vendor details from Basalam API if we have vendor_id and token
$api_url = "https://core.basalam.com/v3/vendors/$sync_basalam_vendor_id";
$api_service = new sync_basalam_External_API_Service();
$response = $api_service->send_get_request($api_url, [
    'Authorization' => 'Bearer ' . $sync_basalam_token
]);
?>

<div class="wrap">
    <div class="basalam-info-top-section">
        <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . "/images/" . $logo_filename) ?>" alt="Basalam">
    </div>
    <?php
    require_once(sync_basalam_configure()->template_path() . "/admin/menu/info/info-connected.php");
    ?>
</div>