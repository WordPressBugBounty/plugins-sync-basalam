<?php
if (! defined('ABSPATH')) exit;
?>
<div class="basalam-info-container">
    <div class="vendor-info">
        <p class="basalam-p" style="text-align: right;font-weight:bold;">اطلاعات غرفه</p>
        <div class="vendor-info-grid">
            <div class="info-item">
                <div class="info-label">نام غرفه</div>
                <div class="info-value"><?php echo esc_html($response['data']['title'] ?? ''); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">شناسه غرفه</div>
                <div class="info-value"><?php echo esc_html($sync_basalam_vendor_id); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">صاحب غرفه</div>
                <div class="info-value"><?php echo esc_html($response['data']['user']['name'] ?? ''); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">شهر غرفه</div>
                <div class="info-value"><?php echo esc_html($response['data']['city']['name'] ?? ''); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">وضعیت غرفه</div>
                <div class="info-value"><?php echo esc_html($response['data']['status']['name'] ?? ''); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">محصولات فعال غرفه</div>
                <div class="info-value"><?php echo esc_html($response['data']['product_count'] ?? ''); ?></div>
            </div>
        </div>
    </div>
    <center>
        <div class="basalam-danger-zone">
            <div class="basalam-card">
                <p class="basalam-p" style="color: var(--basalam-danger-color);">حذف دسترسی</p>
                <p class="basalam-p basalam-p__small" style="padding:0;margin: 16px !important;text-align: justify;">با حذف دسترسی اتصال شما به باسلام قطع خواهد شد و نیاز به تنظیم مجدد خواهید داشت.</p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form">
                    <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
                    <?php esc_html(sync_basalam_Admin_UI::render_sync_basalam_delete_access()); ?>
                    <input type="hidden" name="action" value="basalam_update_setting">
                    <button type="submit" class="basalam-p basalam-danger-button">
                        <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . '/icons/trash.svg'); ?>" alt="">
                        حذف دسترسی
                    </button>
                </form>
                <div class="Basalam-contact-us-section">
                    <div class="basalam-contact-container" style="padding-top: 12px;">
                        <a href="https://t.me/woosalam" target="_blank">
                            <div class="basalam-contact-btn basalam-btn-contact__blue">
                                <img src="<?php echo esc_url(sync_basalam_configure()->assets_url('/images/telegram.png')); ?>" alt="telegram" style="width: 40px;">
                            </div>
                        </a>
                        <a href="https://ble.ir/join/9XayvXfnEj" target="_blank">
                            <div class="basalam-contact-btn basalam-btn-contact__green">
                                <img src="<?php echo esc_url(sync_basalam_configure()->assets_url('/images/bale.png')); ?>" alt="bale" style="width: 40px;">
                            </div>
                        </a>
                        <a href="https://www.aparat.com/playlist/20857018" target="_blank">
                            <div class="basalam-contact-btn basalam-btn-contact__red">
                                <img src="<?php echo esc_url(sync_basalam_configure()->assets_url('/images/aparat.png')); ?>" alt="aparat" style="width: 40px;">
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </center>
</div>