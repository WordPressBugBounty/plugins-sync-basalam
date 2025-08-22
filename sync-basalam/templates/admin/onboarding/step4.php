<?php
if (! defined('ABSPATH')) exit;

$required_settings = [
    sync_basalam_Admin_Settings::WEBHOOK_ID => 'شناسه وب‌هوک',
    sync_basalam_Admin_Settings::TOKEN => 'توکن دسترسی',
    sync_basalam_Admin_Settings::VENDOR_ID => 'شناسه فروشنده'
];

$missing_fields = [];
foreach ($required_settings as $setting_key => $label) {
    $value = sync_basalam_Admin_Settings::get_settings($setting_key);
    if (empty($value)) {
        $missing_fields[] = $label;
    }
}

if (empty($missing_fields)): ?>
    <div class="finish-content  basalam-p">
        <div class="basalam-success-message">
            <p>تبریک! دسترسی شما با موفقیت ایجاد شد</p>
            <p>تمامی اطلاعات مورد نیاز با موفقیت ثبت شد.</p>
        </div>
        <p>اکنون می‌توانید با کلیک روی دکمه‌های زیر، غرفه باسلام خود را مدیریت کنید.</p>
        <div class="basalam-action-buttons">
            <a href="<?php echo esc_url(admin_url('admin.php?page=sync_basalam')); ?>" class="basalam-button basalam-a">رفتن به تنظیمات</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sync_basalam_vendor_info')); ?>" class="basalam-button basalam-a">اطلاعات غرفه و راهنمایی</a>
        </div>
    </div>
<?php else: ?>
    <div class="finish-content">
        <div class="basalam-error-message">
            <p>برخی از اطلاعات ناقص است</p>
            <p>در صورت وجود مشکل با پشتیبانی ارتباط بگیرید</p>
            <p>لطفاً موارد زیر را بررسی و تکمیل کنید:</p>
            <ul>
                <?php foreach ($missing_fields as $field): ?>
                    <li><?php echo esc_html($field); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="basalam-action-buttons">
            <a href="<?php echo esc_url(admin_url('admin.php?page=basalam-onboarding&step=3')); ?>" class="basalam-button basalam-a">بازگشت به مراحل قبل</a>
        </div>
    </div>
<?php endif; ?>