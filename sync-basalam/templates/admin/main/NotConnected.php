<?php defined('ABSPATH') || exit; ?>
<div class="basalam--no-token" style="height: 300px; display: flex; align-items: center; justify-content: center; margin: 50px auto;">
    <div class="basalam--no-token-content" style="text-align: center; padding: 40px;">
        <div class="basalam-error-message" style="width: max-content;">
            <div class="basalam-error-icon">
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke="currentColor" stroke-width="2" fill="none" />
                    <path d="M7 11V7a5 5 0 0110 0v4" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none" />
                    <circle cx="12" cy="16" r="1" fill="currentColor" />
                </svg>
            </div>
            <p class="basalam-error-text basalam-p" style="font-size: 16px; margin: 0; color: currentColor; line-height: 1.6;">برای دسترسی به این بخش ابتدا توکن دریافت نمایید.</p>
            <a class="basalam-p basalam-access-button" href="/wp-admin/admin.php?page=sync_basalam">
                <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/submit.svg'); ?>" alt="">
                دریافت دسترسی
            </a>
        </div>
    </div>
</div>