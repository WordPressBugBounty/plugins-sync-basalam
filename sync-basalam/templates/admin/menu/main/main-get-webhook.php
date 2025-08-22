<?php
if (! defined('ABSPATH')) exit;
?>
<div class="basalam-setup-wizard">
    <div class="basalam-step active">
        <div class="basalam-step-header">
            <span class="basalam-step-number basalam-p">1</span>
            <h2 class="basalam-h">تنظیم وب‌هوک</h2>
        </div>
        <div class="basalam-step-content">
            <p class="basalam-p" style="text-align: justify;margin-top: 16px !important;">برای دریافت خودکار تغییرات محصولات و سفارشات، نیاز به ایجاد وب‌هوک دارید. پس از ایجاد وب‌هوک، شناسه آن را در فرم زیر وارد کنید.</p>
            <center>
                <a href=<?php echo esc_url($static_setting['url_req_webhook']); ?> target=' _blank' class=' basalam-primary-button basalam-p basalam-a' style='width: unset;margin:auto;margin: 24px !important;'>
                    <img src=" <?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/create.svg'); ?>" alt="">
                    ایجاد وب‌هوک جدید در باسلام
                </a>
            </center>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form">
                <input type="hidden" name="action" value="basalam_update_setting">
                <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
                <center>
                    <div class="basalam-form-group">
                        <label class="basalam-label basalam-p">شناسه وب‌هوک</label>
                        <?php sync_basalam_Admin_UI::render_sync_basalam_webhook_id(); ?>
                    </div>
                </center>
                <div class="basalam-form-actions">
                    <button type="submit" name="submit_webhook" class="basalam-primary-button basalam-p" style="width: unset;margin: 12px !important;">
                        <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/submit.svg'); ?>" alt="">
                        ذخیره وب هوک و ادامه
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div class="basalam-step-header basalam-step completed" style="padding: 16px;">
        <span class="basalam-step-number basalam-p" style="background-color: #727171;">2</span>
        <h2 class="basalam-h">تایید دسترسی</h2>
    </div>
</div>