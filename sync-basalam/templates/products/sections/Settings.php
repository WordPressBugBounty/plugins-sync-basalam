<?php

use SyncBasalam\Admin\Product\Category\CategoryOptions;
use SyncBasalam\Admin\Components;

defined('ABSPATH') || exit;

?>
<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
    <input type="hidden" name="action" value="basalam_update_setting">
    <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>

    <div class="basalam-action-card basalam-relative">
        <div class="basalam-info-icon basalam-info-icon-small">
            <a href="https://www.aparat.com/v/fdcbuj0" target="_blank">
                <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/icons/info-black.svg"); ?>" alt="اطلاعات" class="basalam-img-22 basalam-cursor-pointer">
            </a>
        </div>
        <h3 class="basalam-h">تنظیمات</h3>
        <p class="basalam-p basalam-p__small">تنظیمات پیشرفته افزونه</p>

        <div class="basalam-form-row">
            <div class="basalam-form-group basalam-p">
                <?php echo Components::renderLabelWithTooltip('وزن محصولات (گرم)', 'وزن پیش‌فرض که برای محصولات ووکامرس بدون وزن مشخص شده در باسلام نظر گرفته می‌شود. این مقدار در محاسبه هزینه حمل و نقل باسلام مهم است.'); ?>
                <?php Components::renderDefaultWeight(); ?>
            </div>
            <div class="basalam-form-group basalam-p">
                <?php echo Components::renderLabelWithTooltip('زمان آماده‌سازی(روز)', 'تعداد روزهایی که برای آماده‌سازی و بسته‌بندی محصولات نیاز دارید. این زمان به مشتریان باسلام نمایش داده می‌شود.'); ?>
                <?php Components::renderDefaultPreparation(); ?>
            </div>
            <div class="basalam-form-group basalam-p">
                <?php echo Components::renderLabelWithTooltip('موجودی محصولات در باسلام', 'موجودی پیش‌فرضی که برای محصولات ووکامرس بدون موجودی مشخص شده در باسلام نظر گرفته می‌شود.'); ?>
                <?php Components::renderDefaultStockQuantity(); ?>
            </div>
            <div class="basalam-form-group basalam-p">
                <?php echo Components::renderLabelWithTooltip('قیمت محصول در باسلام', 'انتخاب کنید که قیمت اصلی یا قیمت حراجی محصول به باسلام ارسال شود ، در صورتی که قیمت حراجی را انتخاب کنید و محصولی قیمت حراجی نداشته باشد قیمت اصلی به باسلام ارسال میشود.'); ?>
                <?php Components::renderProductPrice(); ?>
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
    <button type="button" class="basalam-secondary-button basalam-p" onclick="document.getElementById('basalam-modal').style.display='block';">
        <span class="dashicons dashicons-arrow-down-alt2"></span>
        تنظیمات بیشتر
    </button>
</center>
<div class="basalam-p basalam-flex-responsive">
    <div class="basalam-flex-align-center-33">
        <?php echo Components::renderLabelWithTooltip('دیباگ', 'حالت دیباگ فقط برای توسعه‌دهندگان توصیه می‌شود.', 'right'); ?>
    </div>
    <?php Components::renderDeveloperMode(); ?>
</div>
</div>

<div id="basalam-modal" class="basalam-modal">
    <section id="sep-section" class="basalam-action-card basalam-bg-modal">

        <span onclick="document.getElementById('basalam-modal').style.display='none';" class="basalam-modal-close-abs">✖️</span>

        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="basalam-margin-bottom-20">
            <input type="hidden" name="action" value="basalam_update_setting">
            <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>

            <div class="basalam-form-row">
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('وزن محصولات (گرم)', 'وزن پیش‌فرض که برای محصولات ووکامرس بدون وزن مشخص شده در باسلام نظر گرفته می‌شود. این مقدار در محاسبه هزینه حمل و نقل باسلام مهم است.'); ?>
                    <?php Components::renderDefaultWeight(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('وزن بسته بندی (گرم)', 'وزن بسته‌بندی که به وزن محصول اضافه می‌شود و در محاسبه حمل و نقل هزینه ارسال باسلام اهمیت دارد. شامل جعبه، برچسب و سایر مواد بسته‌بندی.'); ?>
                    <?php Components::renderPackageWeight(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('زمان آماده‌سازی (روز)', 'تعداد روزهایی که برای آماده‌سازی و بسته‌بندی محصولات نیاز دارید. این زمان به مشتریان باسلام نمایش داده می‌شود.'); ?>
                    <?php Components::renderDefaultPreparation(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('افزایش قیمت در باسلام', 'درصد یا مبلغ ثابتی که به قیمت محصولات در باسلام اضافه می‌شود. می‌تواند به صورت درصد(1-100) یا مبلغ ثابت(101-∞) باشد.'); ?>
                    <?php Components::renderDefaultPercentage(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('جهت رند کردن قیمت در باسلام', 'نحوه رند کردن قیمت‌ها در باسلام. می‌توانید قیمت را به بالا، پایین یا بدون رند تنظیم کنید.'); ?>
                    <?php Components::renderDefaultRound(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('موجودی محصولات', 'موجودی پیش‌فرضی که برای محصولات ووکامرس بدون موجودی مشخص شده در باسلام در نظر گرفته می‌شود.'); ?>
                    <?php Components::renderDefaultStockQuantity(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('موجودی امن', 'اگر موجودی محصول در ووکامرس برابر یا کمتر از این عدد باشد، محصول در باسلام به صورت ناموجود نمایش داده می‌شود.'); ?>
                    <?php Components::renderSafeStock(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('فیلد های ارسالی هنگام آپدیت محصول', 'انتخاب کنید که هنگام آپدیت محصول چه اطلاعاتی به باسلام ارسال شود. حالت سفارشی امکان انتخاب دقیق اطلاعات را می‌دهد.'); ?>
                    <?php Components::renderSyncProduct(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('پیشوند نام محصولات', 'متنی که به ابتدای نام همه محصولات در باسلام اضافه می‌شود. برای مثال: "فروشگاه من -"'); ?>
                    <?php Components::renderPrefixProductTitle(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('پسوند نام محصولات', 'متنی که به انتهای نام همه محصولات در باسلام اضافه می‌شود. برای مثال: "- اصل و کیفیت تضمین"'); ?>
                    <?php Components::renderSuffixProductTitle(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('پسوند از ویژگی محصول', 'با فعال کردن این گزینه، می‌توانید یکی از ویژگی‌های محصول را به عنوان پسوند به نام محصول اضافه کنید (مثلا نام ناشر کتاب).'); ?>
                    <?php Components::renderAttributeSuffixEnabled(); ?>
                </div>
                <div class="basalam-form-group basalam-p basalam-attribute-suffix-container">
                    <?php echo Components::renderLabelWithTooltip('نام ویژگی برای پسوند', 'نام ویژگی محصول که می‌خواهید به عنوان پسوند به نام محصول اضافه شود.'); ?>
                    <?php Components::renderAttributeSuffixPriority(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('محصولات عمده', 'مشخص کنید که آیا همه محصولات به صورت عمده به باسلام ارسال شوند یا اینکه فقط برخی یا هیچ کدام ، از صفحه ویرایش محصول در ووکامرس میتوانید وضعیت عمده محصول را در باسلام مشخص کنید.'); ?>
                    <?php Components::renderWholesaleProducts(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('ویژگی ها به توضیحات', 'آیا ویژگی‌های محصول به توضیحات محصول در باسلام اضافه شود یا خیر.'); ?>
                    <?php Components::renderAttrAddToDesc(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('توضیحات کوتاه به توضیحات', 'آیا توضیحات کوتاه محصول به توضیحات کامل محصول در باسلام اضافه شود یا خیر.'); ?>
                    <?php Components::renderShortAttrAddToDesc(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('وضعیت سفارش های باسلام', ' در صورتی که وضعیت سفارش ، وضعیت های اختصاصی ووسلام باشد امکان مدیریت سفارش(تایید سفارش ، لغو سفارش ، ارسال کد رهگیری و...) از صفحه ویرایش سفارش وجود دارد ، در غیر این صورت سفارشات باسلام با وضعیت پیشفرض ووکارس "در حال انجام" به ووکامرس اضافه میشود.'); ?>
                    <?php Components::renderOrderStatus(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('قیمت محصول در باسلام', 'انتخاب کنید که قیمت اصلی یا قیمت حراجی محصول به باسلام ارسال شود ، در صورتی که قیمت حراجی را انتخاب کنید و محصولی قیمت حراجی نداشته باشد قیمت اصلی به باسلام ارسال میشود.'); ?>
                    <?php Components::renderProductPrice(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip('روش همگام سازی محصولات', 'نحوه بروزرسانی و افزودن محصولات را انتخاب کنید.
                    بهینه (پیشنهادی): عملیات از طریق WP-Cron با کمی تأخیر انجام می‌شود و هیچ تاثیری روی سرعت سایت وارد نمی‌کند.
                    در لحظه: عملیات بلافاصله انجام می‌شود. ممکن است تأثیر لحظه‌ای روی سرعت سایت داشته باشد. اگر از افزونه‌های بهینه‌سازی و سیستم کشینگ استفاده می‌کنید، گزینه در‌لحظه مناسب تر است.'); ?>
                    <?php Components::renderProductOperationType(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip(
                        'مدت زمان تخفیف محصول',
                        'در باسلام هر تخفیف بازه زمانی مشخصی دارد. از این بخش می‌توانید مدت اعتبار تخفیف محصولات را تعیین کنید. با هر بار بروزرسانی محصول، این زمان نیز به‌روز خواهد شد.'
                    ); ?>
                    <?php Components::renderProductDiscountDuration(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo Components::renderLabelWithTooltip(
                        'تشخیص خودکار سرعت',
                        'فعال کردن تشخیص خودکار: سیستم بر اساس منابع سرور (رم، CPU، دیسک، شبکه) به طور خودکار بهترین سرعت را تعیین می‌کند. غیرفعال کردن: شما مقدار را دستی تنظیم کنید.'
                    ); ?>
                    <?php Components::renderTasksPerMinuteAutoToggle(); ?>
                </div>
                <div class="basalam-form-group basalam-p basalam-tasks-manual-container">
                    <?php echo Components::renderLabelWithTooltip(
                        'تعداد تسک در دقیقه (دستی)',
                        'تعداد تسک‌هایی که در هر دقیقه اجرا می‌شوند. این تنظیم بر سرعت پردازش محصولات و عملیات‌های پس‌زمینه تأثیر می‌گذارد. مقدار بالاتر = سرعت بیشتر (بین 1 تا 60)'
                    ); ?>
                    <?php Components::renderTasksPerMinute(); ?>
                </div>
                <?php Components::renderTasksPerMinuteInfo(); ?>
            </div>
            <div id="Basalam-custom-fields" class="basalam-element-hidden">
                <label class="basalam-label basalam-p">فیلدهایی که هنگام آپدیت محصول به باسلام ارسال میشوند </label><br>
                <?php Components::renderSyncProductFields(); ?>
            </div>

            <center class="basalam-center-block">
                <button type="submit" class="basalam-primary-button basalam-p basalam-btn-fill">
                    <span class="dashicons dashicons-saved"></span>
                    ذخیره تنظیمات
                </button>
            </center>
        </form>

        <div class="basalam-form-group basalam-p basalam-margin-top-25-bottom-10">
            <?php echo Components::renderLabelWithTooltip('تغییر نام ویژگی دسته بندی', 'امکان تعریف مترادف برای ویژگی‌های محصول بین ووکامرس و باسلام ، برای مثال "چاپ کننده" در ووکامرس به "ناشر" در باسلام تبدیل شود.'); ?>
            <?php Components::renderMapOptionsProduct(); ?>
        </div>
        <div>
            <?php
            global $wpdb;
            $categoryOptionsManager = new CategoryOptions($wpdb);
            $data = $categoryOptionsManager->getAll();
            Components::renderCategoryOptionsMapping($data); ?>

        </div>
    </section>
</div>