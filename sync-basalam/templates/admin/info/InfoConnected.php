<?php
use SyncBasalam\Admin\Components\SettingPageComponents;
use SyncBasalam\Services\VendorInfoService;

$vendorInfo = (new VendorInfoService())->getVendorInfo();

defined('ABSPATH') || exit;
?>
<div class="basalam-info-container">
    <div class="vendor-info">
        <p class="basalam-p basalam-info-text-right">اطلاعات غرفه</p>
        <div class="vendor-info-grid">
            <div class="info-item">
                <div class="info-label">نام غرفه</div>
                <div class="info-value"><?php echo esc_html($vendorInfo['title'] ?? ''); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">شناسه غرفه</div>
                <div class="info-value"><?php echo esc_html($vendorInfo['id'] ?? ''); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">صاحب غرفه</div>
                <div class="info-value"><?php echo esc_html($vendorInfo['user']['name'] ?? ''); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">شهر غرفه</div>
                <div class="info-value"><?php echo esc_html($vendorInfo['city']['name'] ?? ''); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">وضعیت غرفه</div>
                <div class="info-value"><?php echo esc_html($vendorInfo['status']['name'] ?? ''); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">محصولات فعال غرفه</div>
                <div class="info-value"><?php echo esc_html($vendorInfo['product_count'] ?? ''); ?></div>
            </div>
        </div>
    </div>
    <center>
        <div class="basalam-danger-zone">
            <div class="basalam-card">
                <p class="basalam-p basalam-danger-text">حذف دسترسی</p>
                <p class="basalam-p basalam-p__small basalam-padding-justify">با حذف دسترسی اتصال شما به باسلام قطع خواهد شد و نیاز به تنظیم مجدد خواهید داشت.</p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="Basalam-form">
                    <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>
                    <?php esc_html(SettingPageComponents::renderDeleteAccess()); ?>
                    <input type="hidden" name="action" value="basalam_update_setting">
                    <button type="submit" class="basalam-p basalam-danger-button">
                        <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . '/icons/trash.svg'); ?>" alt="">
                        حذف دسترسی
                    </button>
                </form>
                <div class="Basalam-contact-us-section">
                    <div class="basalam-contact-container basalam-contact-padding-top">
                        <a href="https://t.me/woosalam" target="_blank">
                            <div class="basalam-contact-btn basalam-btn-contact__blue">
                                <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl('/images/telegram.png')); ?>" alt="telegram" class="basalam-contact-img-40">
                            </div>
                        </a>
                        <a href="https://www.aparat.com/playlist/20857018" target="_blank">
                            <div class="basalam-contact-btn basalam-btn-contact__red">
                                <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl('/images/aparat.png')); ?>" alt="aparat" class="basalam-contact-img-40">
                            </div>
                        </a>
                        <a href="https://wp.hamsalam.ir/help" target="_blank">
                            <div class="basalam-contact-btn basalam-btn-contact__blue">
                                <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl('/images/help.svg')); ?>" alt="help page" class="basalam-contact-img-70">
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </center>
</div>