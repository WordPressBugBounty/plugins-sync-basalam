<?php

use SyncBasalam\Admin\Settings\SettingsConfig;

$settings = syncBasalamSettings()->getSettings();
$BasalamAccessToken = $settings[SettingsConfig::TOKEN];
$BasalamRefreshToken = $settings[SettingsConfig::REFRESH_TOKEN];
$syncStatusProduct = $settings[SettingsConfig::SYNC_STATUS_PRODUCT];
$syncStatusOrder = $settings[SettingsConfig::SYNC_STATUS_ORDER];
$autoConfirmOrder = $settings[SettingsConfig::AUTO_CONFIRM_ORDER];
defined('ABSPATH') || exit;
?>
<div class="basalam-container">
    <div class="basalam-header">
        <div class="basalam-header-data">
            <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/images/basalam.svg") ?>" alt="Basalam">
            <p class="basalam-description basalam-p basalam-description-justify-important">این افزونه به شما کمک می‌کند تا محصولات فروشگاه ووکامرس خود را به راحتی
                در باسلام نیز به فروش برسانید. با استفاده از این افزونه، محصولات شما به صورت خودکار در باسلام نیز
                به‌روزرسانی می‌شوند.</p>
        </div>
    </div>

    <?php
    $tokenTemplate = apply_filters('sync_basalam_token_template', null);
    if ($tokenTemplate) {
        require_once($tokenTemplate);
    } elseif (!$BasalamAccessToken || !$BasalamRefreshToken) {
        require_once(syncBasalamPlugin()->templatePath() . "/admin/main/GetToken.php");
    } else {
        require_once(syncBasalamPlugin()->templatePath() . "/admin/main/Connected.php");
    }
    ?>
</div>