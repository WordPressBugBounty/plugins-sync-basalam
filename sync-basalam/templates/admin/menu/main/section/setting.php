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
                <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . "/icons/info-black.svg"); ?>" alt="اطلاعات" style="width: 20px; height: 20px; cursor: pointer;">
            </a>
        </div>
        <h3 class="basalam-h">تنظیمات</h3>
        <p class="basalam-p basalam-p__small">تنظیمات پیشرفته افزونه</p>

        <div class="basalam-form-row">
            <div class="basalam-form-group basalam-p">
                <label class="basalam-label">وزن پیش‌فرض محصولات (گرم)</label>
                <?php sync_basalam_Admin_UI::render_default_weight(); ?>
            </div>
            <div class="basalam-form-group basalam-p">
                <label class="basalam-label">زمان آماده‌سازی پیش‌فرض (روز)</label>
                <?php sync_basalam_Admin_UI::render_default_preparation(); ?>
            </div>
            <div class="basalam-form-group basalam-p">
                <label class="basalam-label">موجودی پیشفرض محصولات</label>
                <?php sync_basalam_Admin_UI::render_default_stock_quantity(); ?>
            </div>
            <div class="basalam-form-group basalam-p">
                <label class="basalam-label">جهت رند کردن قیمت در باسلام</label>
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
</div>
<div id="basalam-modal" class="basalam-modal">
    <section id="sep-section" class="basalam-action-card" style="background: #fff;margin: 5% auto;padding: 20px;width: 90%;max-width: 700px;max-height: 570px;position: relative;overflow-y: auto;padding-top: 40px;">

        <span onclick="document.getElementById('basalam-modal').style.display='none';" style="position:absolute; top:10px; right:15px; cursor:pointer;">✖️</span>

        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="margin-bottom: 20px;">
            <input type="hidden" name="action" value="basalam_update_setting">
            <?php wp_nonce_field('basalam_update_setting_nonce', '_wpnonce'); ?>

            <div class="basalam-form-row" style="grid-template-columns: 1fr 1fr 1fr !important;">
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">وزن پیش‌فرض محصولات (گرم)</label>
                    <?php sync_basalam_Admin_UI::render_default_weight(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">وزن بسته بندی (گرم)</label>
                    <?php sync_basalam_Admin_UI::render_package_weight(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">زمان آماده‌سازی پیش‌فرض (روز)</label>
                    <?php sync_basalam_Admin_UI::render_default_preparation(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">افزایش قیمت در باسلام</label>
                    <?php sync_basalam_Admin_UI::render_default_percentage(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">جهت رند کردن قیمت در باسلام</label>
                    <?php sync_basalam_Admin_UI::render_default_round(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">موجودی پیشفرض محصولات</label>
                    <?php sync_basalam_Admin_UI::render_default_stock_quantity(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">فیلد های ارسالی هنگام آپدیت محصول</label>
                    <?php sync_basalam_Admin_UI::render_sync_product(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">پیشوند نام محصولات</label>
                    <?php sync_basalam_Admin_UI::render_prefix_product_title(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">پسوند نام محصولات</label>
                    <?php sync_basalam_Admin_UI::render_suffix_product_title(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">روش حمل و نقل سفارشات</label>
                    <?php sync_basalam_Admin_UI::render_default_shipping_method($shipping_methods); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">محصولات عمده</label>
                    <?php sync_basalam_Admin_UI::render_wholesale_products(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">ویژگی ها به توضیحات</label>
                    <?php sync_basalam_Admin_UI::render_attr_add_to_desc(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label"> توضیحات کوتاه به توضیحات</label>
                    <?php sync_basalam_Admin_UI::render_short_attr_add_to_desc(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">وضعیت سفارش های باسلام</label>
                    <?php sync_basalam_Admin_UI::render_order_status(); ?>
                </div>
                <div class="basalam-form-group basalam-p">
                    <label class="basalam-label">قیمت محصول در باسلام</label>
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
            <label class="basalam-label">تغییر نام ویژگی دسته بندی:</label>
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