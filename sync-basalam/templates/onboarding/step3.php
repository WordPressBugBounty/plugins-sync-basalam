<?php defined('ABSPATH') || exit; ?>
<div class="step-content step-complete">
    <div class="success-icon-wrapper">
        <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . 'icons/success.svg'); ?>"
            alt="Success"
            class="success-icon-img">
    </div>

    <div>
        <p class="celebration-title" style="font-weight: bold;">دریافت دسترسی با موفقیت انجام شد.</p>
        <p class="basalam-p success-description">پلاگین ووسلام آماده استفاده است. همین حالا شروع کنید!</p>
    </div>
    <div class="celebration-button-wrapper">
        <a href="<?php echo esc_url(admin_url('admin.php?page=sync_basalam')); ?>" class="basalam-primary-button celebration-button basalam-a">
            رفتن به داشبورد
        </a>
    </div>
</div>