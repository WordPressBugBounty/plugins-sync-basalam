<?php
defined('ABSPATH') || exit;

use SyncBasalam\Admin\Settings\OAuthManager;

$oauthUrls = OAuthManager::getOAuthUrls();

?>
<div class="basalam-setup-wizard">
    <div class="basalam-step active">
        <div class="basalam-step-header">
            <span class="basalam-step-number basalam-p">1</span>
            <h2 class="basalam-h">دریافت دسترسی از باسلام</h2>
        </div>
        <div class="basalam-step-content">
            <p class="basalam-p basalam-token-paragraph">برای شروع کار با پلاگین ووسلام، ابتدا باید دسترسی لازم را از باسلام دریافت کنید. با کلیک روی دکمه زیر و ورود به حساب باسلام خود، دسترسی‌های لازم را تایید کنید.</p>
            <div class="basalam-center">
                <a href="<?php echo esc_url($oauthUrls['url_req_token']); ?>" class="basalam-primary-button basalam-p basalam-a basalam-token-button">
                    <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/submit.svg'); ?>" alt="">
                    دریافت دسترسی از باسلام
                </a>
            </div>
        </div>
    </div>
</div>