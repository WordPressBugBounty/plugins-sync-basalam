<?php
if (! defined('ABSPATH')) exit;
?>
<div class="basalam-status-card">
    <div style="display: flex;gap: 5px;align-items: flex-start;">
        <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/error.svg'); ?>" alt="" style="width: 20px;">
        <p class="basalam-p basalam-p__right-align basalam-p__error">
            حسابی که با آن فرآیند تأیید دسترسی را انجام داده‌اید، فاقد غرفه در باسلام است.
        </p>
    </div>
    <p class="basalam-p basalam-p__right-align">
        لطفاً ابتدا با کلیک روی دکمه‌ی زیر یک غرفه جدید در باسلام ایجاد کنید، سپس روی دکمه "احراز هویت" کلیک نمایید.
    </p>
    <p class="basalam-p basalam-p__right-align" style="font-size: 12px;font-weight: bold;">
        چنانچه تمایل دارید با حساب دیگری فرآیند دریافت دسترسی را انجام دهید،در <a class="basalam-a" target="_blank" style="color: black !important;" href="https://basalam.com">باسلام</a> با حساب دارای غرفه وارد شوید و روی گزینه احراز هویت کلیک کنید.
    </p>
    <div style="display: flex;max-width: max-content;gap: 10px;margin: auto;align-items: center;">
        <button class="basalam-button basalam-p" style="width: unset;margin:auto !important;height: 35px;">
            <a class="basalam-a" href="https://vendor.basalam.com/create-vendor" target="_blank">ایجاد غرفه</a>
        </button>
        <img style="width: 40px;rotate: 180deg;height: 30px;" src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/arrow.svg'); ?>" alt="">
        <button class="basalam-button basalam-p" style="width: unset;margin:auto !important;height: 35px;">
            <a class="basalam-a" href="<?php echo esc_url($static_setting['url_req_token']); ?>">احراز هویت</a>
        </button>
    </div>
</div>