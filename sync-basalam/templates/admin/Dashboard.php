<?php

use SyncBasalam\Admin\Settings\SettingsConfig;

$settings = syncBasalamSettings()->getSettings();
$current_default_weight = $settings[SettingsConfig::DEFAULT_WEIGHT];
$current_preparation_time = $settings[SettingsConfig::DEFAULT_PREPARATION];
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
    if (!$BasalamAccessToken || !$BasalamRefreshToken):
        require_once(syncBasalamPlugin()->templatePath() . "/admin/main/GetToken.php");
    else:
        require_once(syncBasalamPlugin()->templatePath() . "/admin/main/Connected.php");
    endif; ?>

</div>