<?php
if (! defined('ABSPATH')) exit;

$webhook_id = sync_basalam_Admin_Settings::get_settings(sync_basalam_Admin_Settings::WEBHOOK_ID);

if ($webhook_id) {
    $webhook_form = '
<div class="existing-credentials">
    <form id="onboarding-form" action="' . esc_url(admin_url('admin-post.php')) . '" method="post" class="Basalam-form">
        <input type="hidden" name="webhook_id" value="' . esc_attr($webhook_id) . '">
        <input type="hidden" name="redirect_to" value="' . esc_url(admin_url('admin.php?page=basalam-onboarding&step=3')) . '">
        <input type="hidden" name="action" value="basalam_update_setting">
        ' . wp_nonce_field('basalam_update_setting_nonce', '_wpnonce', true, false) . '
    </form>
    <p class="basalam-p">شما قبلاً وب‌هوک را ایجاد کرده‌اید.</p>
    <div class="credential-info">
        <p class="basalam-p"><strong>شناسه وب‌هوک:</strong> ' . esc_html($webhook_id) . '</p>
    </div>

    <form action="' . esc_url(admin_url('admin-post.php')) . '" method="post" class="Basalam-form">
        ' . wp_nonce_field('basalam_update_setting_nonce', '_wpnonce', true, false) . '
        <input type="hidden" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::WEBHOOK_ID) . ']" value="">
        <input type="hidden" name="action" value="basalam_update_setting">
        <button type="submit" class="basalam-primary-button basalam-p basalam-a" style="width: -webkit-fill-available;">
            <span class="dashicons dashicons-trash"></span>
            حذف وب هوک
        </button>
    </form>
</div>';
} else {
    $webhook_form = '
<form id="onboarding-form" action="' . esc_url(admin_url('admin-post.php')) . '" method="post" class="Basalam-form">
    ' . wp_nonce_field('basalam_update_setting_nonce', '_wpnonce', true, false) . '
        <input type="hidden" name="redirect_to" value="' . esc_url(admin_url('admin.php?page=basalam-onboarding&step=3')) . '">
    <input type="hidden" name="action" value="basalam_update_setting">
    <div class="basalam-form-row">
        <div class="basalam-form-group">
            <label class="basalam-label basalam-p">شناسه وب‌هوک (Webhook ID)</label>
            <input type="text" name="sync_basalam_settings[' . esc_attr(sync_basalam_Admin_Settings::WEBHOOK_ID) . ']" class="basalam-input" required style="max-width:100% !important;">
        </div>
    </div>
</form>';
}
?>
<div class="step-content">
    <div>
        <a href="<?php echo esc_url(sync_basalam_Admin_Settings::get_static_settings('url_req_webhook')); ?>" target="_blank" class="basalam-primary-button basalam-p basalam-a">
            <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/create.svg'); ?>" alt="">
            ایجاد وب‌هوک جدید در باسلام
        </a>
        <div class="basalam-step basalam-step-intro" style="margin-top: 10px !important;">
            <div class="basalam-step-content">
                <?php echo wp_kses($webhook_form, Sync_basalam_Admin_UI::allowed_html()); ?>
            </div>
        </div>
    </div>
    <div class="step-instructions">
        <ol>
            <li>روی دکمه ایجاد وب هوک در باسلام کلیک کنید</li>
            <li>وب هوک را در باسلام ایجاد کنید</li>
            <li><code class="basalam-code">Webhook ID</code> را در فرم وارد کنید</li>
            <li>پس از ثبت، به صفحه تایید دسترسی هدایت خواهید شد</li>
        </ol>
    </div>
</div>