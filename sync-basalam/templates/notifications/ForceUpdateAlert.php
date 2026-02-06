<?php
defined('ABSPATH') || exit;

$plugin_file = 'sync-basalam/sync-basalam.php';

$update_url = wp_nonce_url(
    self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($plugin_file)),
    'upgrade-plugin_' . $plugin_file
);
?>

<div class="notice notice-error basalam-notice-flex" style="border-left: 4px solid #dc3232; padding: 15px; background: #fff; border-radius: 4px;">
    <div style="display: flex; align-items: center; gap: 15px;">
        <div style="font-size: 24px;">⚠️</div>
        <div style="flex: 1;">
            <h3 style="margin: 0 0 10px 0; color: #dc3232; font-size: 16px;">
                <strong>هشدار: آپدیت فوری افزونه ووسلام</strong>
            </h3>
            <p style="margin: 0; color: #444; line-height: 1.6;">
                افزونه ووسلام نیازمند <strong>آپدیت فوری</strong> است.
                تا زمانی که آپدیت انجام نشود، <b>تمامی قابلیت‌های ووسلام غیرفعال می‌باشد</b>.
                لطفاً روی دکمه زیر کلیک کنید تا افزونه آپدیت شود.
            </p>
        </div>
        <div>
            <a href="<?php echo esc_url($update_url); ?>" class="button-primary" style="padding: 10px 20px; font-size: 14px; height: auto; display: inline-block; text-decoration: none; line-height: normal;">
                هم‌اکنون به‌روزرسانی نمایید
            </a>
        </div>
    </div>
</div>