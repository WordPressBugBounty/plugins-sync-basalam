<?php
if (! defined('ABSPATH')) exit;
$get_shipping_methods = new sync_basalam_Get_Shipping_Methods();
$shipping_methods = $get_shipping_methods->get_woo_shipping_methods();

?>
<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
    <input type="hidden" name="action" value="basalam_update_setting">
    <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>

    <div class="basalam-action-card" style="position: relative;">
        <div class="basalam-info-icon" style="position: absolute;top: 10px;left: 10px;border: 1px solid;border-radius: 70%;width: 22px;height: 22px;">
            <a href="https://www.aparat.com/v/fdcbuj0" target="_blank">
                <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . "/icons/info-black.svg"); ?>" alt="اطلاعات" style="width: 22px; height: 20px; cursor: pointer;">
            </a>
        </div>
        <h3 class="basalam-h">تنظیمات</h3>
        <p class="basalam-p basalam-p__small">تنظیمات پیشرفته افزونه</p>

        <div class="basalam-form-row">
            <div class="basalam-form-group basalam-p">
                <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('وزن محصولات (گرم)', 'وزن پیش‌فرض که برای محصولات ووکامرس بدون وزن مشخص شده در باسلام نظر گرفته می‌شود. این مقدار در محاسبه هزینه حمل و نقل باسلام مهم است.'); ?>
                <?php sync_basalam_Admin_UI::render_default_weight(); ?>
            </div>
            <div class="basalam-form-group basalam-p">
                <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('زمان آماده‌سازی(روز)', 'تعداد روزهایی که برای آماده‌سازی و بسته‌بندی محصولات نیاز دارید. این زمان به مشتریان باسلام نمایش داده می‌شود.'); ?>
                <?php sync_basalam_Admin_UI::render_default_preparation(); ?>
            </div>
            <div class="basalam-form-group basalam-p">
                <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('موجودی محصولات', 'موجودی پیش‌فرضی که برای محصولات ووکامرس بدون موجودی مشخص شده در باسلام نظر گرفته می‌شود.'); ?>
                <?php sync_basalam_Admin_UI::render_default_stock_quantity(); ?>
            </div>
            <div class="basalam-form-group basalam-p">
                <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('جهت رند کردن قیمت در باسلام', 'نحوه رند کردن قیمت‌ها در باسلام. می‌توانید قیمت را به بالا، پایین یا بدون رند تنظیم کنید.'); ?>
                <?php sync_basalam_Admin_UI::render_default_round(); ?>
            </div>
        </div>

        <center style="margin-top: 20px;">
            <button type="submit" class="basalam-primary-button basalam-p" style="width:-webkit-fill-available;margin-bottom:0px !important;">
                <span class="dashicons dashicons-saved"></span>
                ذخیره تنظیمات
            </button>
        </center>
</form>

<center style="margin-top: 10px;">
    <button type="button" class="basalam-secondary-button basalam-p" onclick="document.getElementById('basalam-modal').style.display='block';">
        <span class="dashicons dashicons-arrow-down-alt2"></span>
        تنظیمات بیشتر
    </button>
</center>
<div class=" basalam-p" style="display: flex;margin-top: 13px !important;">
    <div style="display: flex; align-items: center; width: 33%;">
        <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('دیباگ', 'حالت دیباگ فقط برای توسعه‌دهندگان توصیه می‌شود.', 'right'); ?>
    </div>
    <?php sync_basalam_Admin_UI::render_developer_mode(); ?>
</div>
</div>


<div id="basalam-modal" class="basalam-modal">
    <section id="sep-section" class="basalam-action-card" style="background: #fff;margin: 5% auto;padding: 20px;width: 90%;max-width: 700px;max-height: 570px;position: relative;overflow-y: auto;padding-top: 40px;">

        <span onclick="document.getElementById('basalam-modal').style.display='none';" style="position:absolute; top:10px; right:15px; cursor:pointer;">✖️</span>

        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="margin-bottom: 20px;">
            <input type="hidden" name="action" value="basalam_update_setting">
            <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>

            <div class="basalam-form-row" style="grid-template-columns: 1fr 1fr 1fr !important;">
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('وزن محصولات (گرم)', 'وزن پیش‌فرض که برای محصولات ووکامرس بدون وزن مشخص شده در باسلام نظر گرفته می‌شود. این مقدار در محاسبه هزینه حمل و نقل باسلام مهم است.'); ?>
                    <?php sync_basalam_Admin_UI::render_default_weight(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('وزن بسته بندی (گرم)', 'وزن بسته‌بندی که به وزن محصول اضافه می‌شود و در محاسبه حمل و نقل هزینه ارسال باسلام اهمیت دارد. شامل جعبه، برچسب و سایر مواد بسته‌بندی.'); ?>
                    <?php sync_basalam_Admin_UI::render_package_weight(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('زمان آماده‌سازی (روز)', 'تعداد روزهایی که برای آماده‌سازی و بسته‌بندی محصولات نیاز دارید. این زمان به مشتریان باسلام نمایش داده می‌شود.'); ?>
                    <?php sync_basalam_Admin_UI::render_default_preparation(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('افزایش قیمت در باسلام', 'درصد یا مبلغ ثابتی که به قیمت محصولات در باسلام اضافه می‌شود. می‌تواند به صورت درصد(1-100) یا مبلغ ثابت(101-∞) باشد.'); ?>
                    <?php sync_basalam_Admin_UI::render_default_percentage(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('جهت رند کردن قیمت در باسلام', 'نحوه رند کردن قیمت‌ها در باسلام. می‌توانید قیمت را به بالا، پایین یا بدون رند تنظیم کنید.'); ?>
                    <?php sync_basalam_Admin_UI::render_default_round(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('موجودی محصولات', 'موجودی پیش‌فرضی که برای محصولات ووکامرس بدون موجودی مشخص شده در باسلام در نظر گرفته می‌شود.'); ?>
                    <?php sync_basalam_Admin_UI::render_default_stock_quantity(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('فیلد های ارسالی هنگام آپدیت محصول', 'انتخاب کنید که هنگام آپدیت محصول چه اطلاعاتی به باسلام ارسال شود. حالت سفارشی امکان انتخاب دقیق اطلاعات را می‌دهد.'); ?>
                    <?php sync_basalam_Admin_UI::render_sync_product(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('پیشوند نام محصولات', 'متنی که به ابتدای نام همه محصولات در باسلام اضافه می‌شود. برای مثال: "فروشگاه من -"'); ?>
                    <?php sync_basalam_Admin_UI::render_prefix_product_title(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('پسوند نام محصولات', 'متنی که به انتهای نام همه محصولات در باسلام اضافه می‌شود. برای مثال: "- اصل و کیفیت تضمین"'); ?>
                    <?php sync_basalam_Admin_UI::render_suffix_product_title(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('روش حمل و نقل سفارشات', 'روش حمل و نقلی که برای سفارشات دریافتی از باسلام در ووکامرس اعمال می‌شود ، در صورتی که روشی انتخاب نشود سفارشات باسلام بدون روش و هزینه حمل و نقل در ووکامرس ثبت میشوند.'); ?>
                    <?php sync_basalam_Admin_UI::render_default_shipping_method($shipping_methods); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('محصولات عمده', 'مشخص کنید که آیا همه محصولات به صورت عمده به باسلام ارسال شوند یا اینکه فقط برخی یا هیچ کدام ، از صفحه ویرایش محصول در ووکامرس میتوانید وضعیت عمده محصول را در باسلام مشخص کنید.'); ?>
                    <?php sync_basalam_Admin_UI::render_wholesale_products(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('ویژگی ها به توضیحات', 'آیا ویژگی‌های محصول به توضیحات محصول در باسلام اضافه شود یا خیر.'); ?>
                    <?php sync_basalam_Admin_UI::render_attr_add_to_desc(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('توضیحات کوتاه به توضیحات', 'آیا توضیحات کوتاه محصول به توضیحات کامل محصول در باسلام اضافه شود یا خیر.'); ?>
                    <?php sync_basalam_Admin_UI::render_short_attr_add_to_desc(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('وضعیت سفارش های باسلام', ' در صورتی که وضعیت سفارش ، وضعیت های اختصاصی ووسلام باشد امکان مدیریت سفارش(تایید سفارش ، لغو سفارش ، ارسال کد رهگیری و...) از صفحه ویرایش سفارش وجود دارد ، در غیر این صورت سفارشات باسلام با وضعیت پیشفرض ووکارس "در حال انجام" به ووکامرس اضافه میشود.'); ?>
                    <?php sync_basalam_Admin_UI::render_order_status(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('قیمت محصول در باسلام', 'انتخاب کنید که قیمت اصلی یا قیمت حراجی محصول به باسلام ارسال شود ، در صورتی که قیمت حراجی را انتخاب کنید و محصولی قیمت حراجی نداشته باشد قیمت اصلی به باسلام ارسال میشود.'); ?>
                    <?php sync_basalam_Admin_UI::render_product_price(); ?>
                </div>

            </div>
            <div id="Basalam-custom-fields" style="display:none; margin-top:15px;margin-bottom: 20px;">
                <label class="basalam-label basalam-p">فیلدهایی که هنگام آپدیت محصول به باسلام ارسال میشوند </label><br>
                <?php sync_basalam_Admin_UI::render_sync_product_fields(); ?>
            </div>

            <center>
                <button type="submit" class="basalam-primary-button basalam-p" style="width:-webkit-fill-available;">
                    <span class="dashicons dashicons-saved"></span>
                    ذخیره تنظیمات
                </button>
            </center>
        </form>

        <div class="basalam-form-group basalam-p" style="margin-top: 25px; margin-bottom: 10px !important;">
            <?php echo sync_basalam_Admin_UI::render_label_with_tooltip('تغییر نام ویژگی دسته بندی', 'امکان تعریف مترادف برای ویژگی‌های محصول بین ووکامرس و باسلام ، برای مثال "چاپ کننده" در ووکامرس به "ناشر" در باسلام تبدیل شود.'); ?>
            <?php sync_basalam_Admin_UI::render_map_options_product(); ?>
        </div>
        <div>
            <?php
            global $wpdb;
            $categoryOptionsManager = new sync_basalam_Manage_Category_Options($wpdb);
            $data = $categoryOptionsManager->get_all();
            sync_basalam_Admin_UI::render_category_options_mapping($data); ?>

        </div>
    </section>
</div>