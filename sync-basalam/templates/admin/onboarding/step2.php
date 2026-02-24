<?php

use SyncBasalam\Admin\Settings\OAuthManager;

defined('ABSPATH') || exit;

$OAuthManger = new OAuthManager();
$oauthUrls = $OAuthManger->getOAuthUrls();

?>
<div class="step-content">
    <div>
        <a href="<?php echo esc_url($oauthUrls['url_req_token']); ?>" class="basalam-primary-button basalam-p basalam-a">
            <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/create.svg'); ?>" alt="">
            دریافت دسترسی از باسلام
        </a>
    </div>
    <div class="step-instructions">
        <ol>
            <li>با کلیک روی گزینه دریافت دسترسی از basalam.com به باسلام هدایت خواهید شد و با کلیک روی گزینه دسترسی میدهم ، فرایند دریافت دسترسی انجام خواهد شد.</li>
        </ol>
    </div>
</div>