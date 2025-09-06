<?php
if (! defined('ABSPATH')) exit;
?>
<div class="step-content step-complete">
    <div class="success-icon">
        <svg width="80" height="80" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="12" cy="12" r="10" stroke="#00a84f" stroke-width="2" fill="none" />
            <path d="M8 12l2 2 4-4" stroke="#00a84f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </div>
    <h3>تبریک! نصب با موفقیت انجام شد</h3>
    <p class="basalam-p">پلاگین ووسلام آماده استفاده است.</p>
    <a href="<?php echo esc_url(admin_url('admin.php?page=sync_basalam')); ?>" class="basalam-primary-button basalam-p basalam-a">
        رفتن به داشبورد
    </a>
</div>