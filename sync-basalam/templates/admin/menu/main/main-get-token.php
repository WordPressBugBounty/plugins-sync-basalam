<?php
if (! defined('ABSPATH')) exit;
?>
<div class="basalam-setup-wizard">
    <div class="basalam-step active">
        <div class="basalam-step-header">
            <span class="basalam-step-number basalam-p">1</span>
            <h2 class="basalam-h">دریافت دسترسی از باسلام</h2>
        </div>
        <div class="basalam-step-content">
            <p class="basalam-p" style="text-align: justify;margin-top:16px !important;">برای شروع کار با پلاگین ووسلام، ابتدا باید دسترسی لازم را از باسلام دریافت کنید. با کلیک روی دکمه زیر و ورود به حساب باسلام خود، دسترسی‌های لازم را تایید کنید.</p>
            <center>
                <a href="<?php echo esc_url($static_setting['url_req_token']); ?>" class="basalam-primary-button basalam-p basalam-a" style="width: unset;margin: 24px !important;">
                    <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/submit.svg'); ?>" alt="">
                    دریافت دسترسی از باسلام
                </a>
            </center>
        </div>
    </div>
</div>