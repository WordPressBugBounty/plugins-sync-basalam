<?php
if (! defined('ABSPATH')) exit;
?>
<div class="basalam-setup-wizard">
    <div class="basalam-step completed">
        <div class="basalam-step-header">
            <span class="basalam-step-number"><span class="dashicons dashicons-yes"></span></span>
            <h2 class="basalam-h">تنظیم وب‌هوک</h2>
        </div>
    </div>

    <div class="basalam-step active">
        <div class="basalam-step-header">
            <span class="basalam-step-number basalam-p">2</span>
            <h2 class="basalam-h">تایید دسترسی</h2>
        </div>
        <div class="basalam-step-content">
            <p class="basalam-p" style="text-align: justify;margin-top:16px !important;">برای اتمام فرآیند و شروع همگام‌سازی، نیاز به تایید دسترسی دارید. با کلیک روی دکمه زیر و ورود به حساب باسلام خود، دسترسی‌های لازم را تایید کنید.</p>
            <center>
                <a href="<?php echo esc_url($static_setting['url_req_token']); ?>" class="basalam-primary-button basalam-p basalam-a" style="width: unset;margin: 24px !important;">
                    <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/submit.svg'); ?>" alt="">
                    ورود و تایید دسترسی
                </a>
            </center>
        </div>
    </div>
</div>