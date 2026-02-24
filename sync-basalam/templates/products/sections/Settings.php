<?php

use SyncBasalam\Admin\Product\Category\CategoryOptions;
use SyncBasalam\Admin\Components\SettingPageComponents;
use SyncBasalam\Admin\Components\CommonComponents;

defined('ABSPATH') || exit;

?>
<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
    <input type="hidden" name="action" value="basalam_update_setting">
    <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>

    <div id="sync-basalam-onboarding-settings" class="basalam-action-card basalam-relative">
        <div class="basalam-info-icon basalam-info-icon-small">
            <a href="https://www.aparat.com/v/fdcbuj0" target="_blank">
                <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/icons/info-black.svg"); ?>" alt="اطلاعات" class="basalam-img-22 basalam-cursor-pointer">
            </a>
        </div>
        <h3 class="basalam-h">تنظیمات</h3>
        <p class="basalam-p basalam-p__small">تنظیمات پیشرفته افزونه</p>

        <div class="basalam-form-row">
            <div class="basalam-form-group basalam-form-group-full basalam-p">
                <?php echo CommonComponents::renderLabelWithTooltip('افزایش قیمت در باسلام', 'درصد یا مبلغ ثابتی که به قیمت محصولات در باسلام اضافه می‌شود. می‌تواند به صورت درصد(1-100) یا مبلغ ثابت(101-∞) باشد.'); ?>
                <?php SettingPageComponents::renderDefaultPercentage(); ?>
            </div>
        </div>
        <div class="basalam-form-row basalam-form-row-two-col">
            <div class="basalam-form-group basalam-p">
                <?php echo CommonComponents::renderLabelWithTooltip('موجودی محصولات در باسلام', 'موجودی پیش‌فرضی که برای محصولات ووکامرس بدون موجودی مشخص شده در باسلام نظر گرفته می‌شود.'); ?>
                <?php SettingPageComponents::renderDefaultStockQuantity(); ?>
            </div>
            <div class="basalam-form-group basalam-p">
                <?php echo CommonComponents::renderLabelWithTooltip('قیمت محصول در باسلام', 'انتخاب کنید که قیمت اصلی یا قیمت حراجی محصول به باسلام ارسال شود ، در صورتی که قیمت حراجی را انتخاب کنید و محصولی قیمت حراجی نداشته باشد قیمت اصلی به باسلام ارسال میشود.'); ?>
                <?php SettingPageComponents::renderProductPrice(); ?>
            </div>
        </div>

        <center class="basalam-center">
            <button type="submit" class="basalam-primary-button basalam-p basalam-btn-fill basalam-btn-no-margin-bottom">
                <span class="dashicons dashicons-saved"></span>
                ذخیره تنظیمات
            </button>
        </center>
</form>

<center class="basalam-center-margin">
    <button
        type="button"
        id="sync-basalam-onboarding-advanced-settings"
        class="basalam-secondary-button basalam-p"
        onclick="document.getElementById('basalam-modal').style.display='block';">
        <span class="dashicons dashicons-arrow-down-alt2"></span>
        تنظیمات بیشتر
    </button>
</center>
<div class="basalam-p basalam-flex-responsive">
    <div class="basalam-flex-align-center-33">
        <?php echo CommonComponents::renderLabelWithTooltip('دیباگ', 'حالت دیباگ فقط برای توسعه‌دهندگان توصیه می‌شود.', 'right'); ?>
    </div>
    <?php SettingPageComponents::renderDeveloperMode(); ?>
</div>
</div>

<div id="basalam-modal" class="basalam-modal">
    <section id="sep-section" class="basalam-action-card basalam-bg-modal">

        <span onclick="document.getElementById('basalam-modal').style.display='none';" class="basalam-modal-close-abs">✖️</span>

        <h3 class="basalam-h basalam-modal-title">تنظیمات پیشرفته</h3>

        <!-- Tabs Navigation -->
        <div class="basalam-tabs-nav">
            <button type="button" class="basalam-tab-btn active" data-tab="product-settings">
                <span class="dashicons dashicons-products"></span>
                تنظیمات محصول
            </button>
            <button type="button" class="basalam-tab-btn" data-tab="order-settings">
                <span class="dashicons dashicons-cart"></span>
                تنظیمات سفارشات
            </button>
            <button type="button" class="basalam-tab-btn" data-tab="operation-settings">
                <span class="dashicons dashicons-performance"></span>
                تنظیمات اجرای عملیات
            </button>
        </div>

        <form id="basalam-advanced-settings-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="basalam-margin-bottom-20">
            <input type="hidden" name="action" value="basalam_update_setting">
            <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>

            <!-- Tab Content: Product Settings -->
            <div id="product-settings" class="basalam-tab-content active">
                <div class="basalam-tab-header">
                    <span class="dashicons dashicons-products"></span>
                    <h4 class="basalam-h">تنظیمات محصول</h4>
                </div>
                <div class="basalam-form-row">
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('وزن محصولات (گرم)', 'وزن پیش‌فرض که برای محصولات ووکامرس بدون وزن مشخص شده در باسلام نظر گرفته می‌شود. این مقدار در محاسبه هزینه حمل و نقل باسلام مهم است.'); ?>
                        <?php SettingPageComponents::renderDefaultWeight(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('وزن بسته بندی (گرم)', 'وزن بسته‌بندی که به وزن محصول اضافه می‌شود و در محاسبه حمل و نقل هزینه ارسال باسلام اهمیت دارد. شامل جعبه، برچسب و سایر مواد بسته‌بندی.'); ?>
                        <?php SettingPageComponents::renderPackageWeight(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('زمان آماده‌سازی (روز)', 'تعداد روزهایی که برای آماده‌سازی و بسته‌بندی محصولات نیاز دارید. این زمان به مشتریان باسلام نمایش داده می‌شود.'); ?>
                        <?php SettingPageComponents::renderDefaultPreparation(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('افزایش قیمت در باسلام', 'درصد یا مبلغ ثابتی که به قیمت محصولات در باسلام اضافه می‌شود. می‌تواند به صورت درصد(1-100) یا مبلغ ثابت(101-∞) باشد.'); ?>
                        <?php SettingPageComponents::renderDefaultPercentage(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('جهت رند کردن قیمت در باسلام', 'نحوه رند کردن قیمت‌ها در باسلام. می‌توانید قیمت را به بالا، پایین یا بدون رند تنظیم کنید.'); ?>
                        <?php SettingPageComponents::renderDefaultRound(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('موجودی محصولات', 'موجودی پیش‌فرضی که برای محصولات ووکامرس بدون موجودی مشخص شده در باسلام در نظر گرفته می‌شود.'); ?>
                        <?php SettingPageComponents::renderDefaultStockQuantity(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('موجودی امن', 'اگر موجودی محصول در ووکامرس برابر یا کمتر از این عدد باشد، محصول در باسلام به صورت ناموجود نمایش داده می‌شود.'); ?>
                        <?php SettingPageComponents::renderSafeStock(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('فیلد های ارسالی هنگام آپدیت محصول', 'انتخاب کنید که هنگام آپدیت محصول چه اطلاعاتی به باسلام ارسال شود. حالت سفارشی امکان انتخاب دقیق اطلاعات را می‌دهد.'); ?>
                        <?php SettingPageComponents::renderSyncProduct(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('پیشوند نام محصولات', 'متنی که به ابتدای نام همه محصولات در باسلام اضافه می‌شود. برای مثال: "فروشگاه من -"'); ?>
                        <?php SettingPageComponents::renderPrefixProductTitle(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('پسوند نام محصولات', 'متنی که به انتهای نام همه محصولات در باسلام اضافه می‌شود. برای مثال: "- اصل و کیفیت تضمین"'); ?>
                        <?php SettingPageComponents::renderSuffixProductTitle(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('پسوند از ویژگی محصول', 'با فعال کردن این گزینه، می‌توانید یکی از ویژگی‌های محصول را به عنوان پسوند به نام محصول اضافه کنید (مثلا نام ناشر کتاب).'); ?>
                        <?php SettingPageComponents::renderAttributeSuffixEnabled(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p basalam-attribute-suffix-container">
                        <?php echo CommonComponents::renderLabelWithTooltip('نام ویژگی برای پسوند', 'نام ویژگی محصول که می‌خواهید به عنوان پسوند به نام محصول اضافه شود.'); ?>
                        <?php SettingPageComponents::renderAttributeSuffixPriority(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('محصولات عمده', 'مشخص کنید که آیا همه محصولات به صورت عمده به باسلام ارسال شوند یا اینکه فقط برخی یا هیچ کدام ، از صفحه ویرایش محصول در ووکامرس میتوانید وضعیت عمده محصول را در باسلام مشخص کنید.'); ?>
                        <?php SettingPageComponents::renderWholesaleProducts(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('ویژگی ها به توضیحات', 'آیا ویژگی‌های محصول به توضیحات محصول در باسلام اضافه شود یا خیر.'); ?>
                        <?php SettingPageComponents::renderAttrAddToDesc(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('توضیحات کوتاه به توضیحات', 'آیا توضیحات کوتاه محصول به توضیحات کامل محصول در باسلام اضافه شود یا خیر.'); ?>
                        <?php SettingPageComponents::renderShortAttrAddToDesc(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('قیمت محصول در باسلام', 'انتخاب کنید که قیمت اصلی یا قیمت حراجی محصول به باسلام ارسال شود ، در صورتی که قیمت حراجی را انتخاب کنید و محصولی قیمت حراجی نداشته باشد قیمت اصلی به باسلام ارسال میشود.'); ?>
                        <?php SettingPageComponents::renderProductPrice(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip(
                            'مدت زمان تخفیف محصول',
                            'در باسلام هر تخفیف بازه زمانی مشخصی دارد. از این بخش می‌توانید مدت اعتبار تخفیف محصولات را تعیین کنید. با هر بار بروزرسانی محصول، این زمان نیز به‌روز خواهد شد.'
                        ); ?>
                        <?php SettingPageComponents::renderProductDiscountDuration(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip(
                            'کاهش درصد تخفیف',
                            'این مقدار هنگام اعمال قیمت خط خورده در باسلام استفاده میشود. اگر درصد تخفیف محصول از این عدد بیشتر باشد، همین عدد از آن کم می‌شود. مثال: اگر این مقدار 10 باشد و تخفیف محصول 25٪ باشد، درصد تخفیف در باسلام 15% میشود. اگر تخفیف محصول 8٪ باشد (کمتر یا مساوی 10)، بدون تغییر همان 8٪ ارسال می‌شود.'
                        ); ?>
                        <?php SettingPageComponents::renderDiscountReductionPercent(); ?>
                    </div>
                </div>
                <div id="Basalam-custom-fields" class="basalam-element-hidden basalam-custom-fields-box">
                    <label class="basalam-label basalam-p">فیلدهایی که هنگام آپدیت محصول به باسلام ارسال میشوند </label><br>
                    <?php SettingPageComponents::renderSyncProductFields(); ?>
                </div>

                <!-- Category Mapping Section -->
                <div class="basalam-form-group basalam-p basalam-margin-top-25-bottom-10">
                    <?php echo CommonComponents::renderLabelWithTooltip('تغییر نام ویژگی دسته بندی', 'امکان تعریف مترادف برای ویژگی‌های محصول بین ووکامرس و باسلام ، برای مثال "چاپ کننده" در ووکامرس به "ناشر" در باسلام تبدیل شود.'); ?>
                    <?php SettingPageComponents::renderMapOptionsProduct(); ?>
                </div>
                <div>
                    <?php
                    global $wpdb;
                    $categoryOptionsManager = new CategoryOptions($wpdb);
                    $data = $categoryOptionsManager->getAll();
                    SettingPageComponents::renderCategoryOptionsMapping($data); ?>
                </div>
            </div>

            <!-- Tab Content: Order Settings -->
            <div id="order-settings" class="basalam-tab-content">
                <div class="basalam-tab-header">
                    <span class="dashicons dashicons-cart"></span>
                    <h4 class="basalam-h">تنظیمات سفارشات</h4>
                </div>
                <div class="basalam-form-row">
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('وضعیت سفارش های باسلام', ' در صورتی که وضعیت سفارش ، وضعیت های اختصاصی ووسلام باشد امکان مدیریت سفارش(تایید سفارش ، لغو سفارش ، ارسال کد رهگیری و...) از صفحه ویرایش سفارش وجود دارد ، در غیر این صورت سفارشات باسلام با وضعیت پیشفرض ووکارس "در حال انجام" به ووکامرس اضافه میشود.'); ?>
                        <?php SettingPageComponents::renderOrderStatus(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('روش حمل و نقل سفارشات', 'روش حمل و نقل پیش‌فرض برای سفارشات باسلام. "حمل و نقل باسلام" نام روش را از باسلام می‌گیرد. یا می‌توانید یکی از روش‌های حمل و نقل فعال ووکامرس را انتخاب کنید.'); ?>
                        <?php SettingPageComponents::renderShippingMethod(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('پیشوند نام سفارش‌دهنده', 'متنی که به ابتدای نام کوچک (نام) سفارش‌دهنده در ووکامرس اضافه می‌شود. برای مثال: "آقای" یا "خانم"'); ?>
                        <?php SettingPageComponents::renderCustomerPrefixName(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip('پسوند نام سفارش‌دهنده', 'متنی که به انتهای نام خانوادگی (فامیلی) سفارش‌دهنده در ووکامرس اضافه می‌شود. برای مثال: "عزیز"'); ?>
                        <?php SettingPageComponents::renderCustomerSuffixName(); ?>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Operation Settings -->
            <div id="operation-settings" class="basalam-tab-content">
                <div class="basalam-tab-header">
                    <span class="dashicons dashicons-performance"></span>
                    <h4 class="basalam-h">تنظیمات اجرای عملیات</h4>
                </div>
                <div class="basalam-form-row">
                    <div class="basalam-form-group basalam-p">
                        <?php echo CommonComponents::renderLabelWithTooltip(
                            'تشخیص خودکار سرعت',
                            'فعال کردن تشخیص خودکار: سیستم بر اساس منابع سرور (رم، CPU، دیسک، شبکه) به طور خودکار بهترین سرعت را تعیین می‌کند. غیرفعال کردن: شما مقدار را دستی تنظیم کنید.'
                        ); ?>
                        <?php SettingPageComponents::renderTasksPerMinuteAutoToggle(); ?>
                    </div>
                    <div class="basalam-form-group basalam-p basalam-tasks-manual-container">
                        <?php echo CommonComponents::renderLabelWithTooltip(
                            'تعداد تسک در دقیقه (دستی)',
                            'تعداد تسک‌هایی که در هر دقیقه اجرا می‌شوند. این تنظیم بر سرعت پردازش محصولات و عملیات‌های پس‌زمینه تأثیر می‌گذارد. مقدار بالاتر = سرعت بیشتر (بین 1 تا 60)'
                        ); ?>
                        <?php SettingPageComponents::renderTasksPerMinute(); ?>
                    </div>
                    <?php SettingPageComponents::renderTasksPerMinuteInfo(); ?>
                </div>
            </div>

            <center class="basalam-center-block basalam-submit-section basalam-submit-section-hidden" id="basalam-advanced-submit-section">
                <button type="submit" class="basalam-primary-button basalam-p basalam-btn-fill">
                    <span class="dashicons dashicons-saved"></span>
                    ذخیره تنظیمات
                </button>
            </center>
        </form>
    </section>
</div>