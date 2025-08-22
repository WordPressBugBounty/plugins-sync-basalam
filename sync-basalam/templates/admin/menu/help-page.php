<?php
if (! defined('ABSPATH')) exit;
$logo_filename = "logo.svg";
?>
<div class="basalam-container">
    <!-- Header Section -->
    <div class="basalam-header" style="max-width:none;width:75%;">
        <div class="basalam-header-data">
            <img src="<?php echo esc_url(sync_basalam_configure()->assets_url() . "/images/" . $logo_filename) ?>" alt="Basalam">
            <div>
                <h1 style="text-align: justify;" class="basalam-h">راهنمای استفاده از افزونه باسلام</h1>
                <p class="basalam-p" style="margin-top: 17px !important;">در این صفحه می‌توانید پاسخ سوالات متداول و راهنمای استفاده از افزونه را مشاهده کنید.</p>
                <p class="basalam-p" style="text-align: justify;">برای مشاهده ویدیو های آموزشی پلاگین <b> <a href="https://www.aparat.com/playlist/20857018" target="_blank">کلیک</a></b> کنید</p>
            </div>
        </div>
    </div>

    <!-- Search Section -->
    <div class="basalam-help-search">
        <input disabled type="text" id="basalam-faq-search" class="basalam-input" placeholder="جستجو در سوالات متداول...">
        <div id="basalam-search-results" class="basalam-search-results"></div>
    </div>

    <!-- Categories and FAQs -->
    <div class="basalam-help-content">
        <!-- Category Tabs -->
        <div class="basalam-help-categories">
            <button class="basalam-category-tab active" data-category="عمومی">اطلاعات کلی</button>
            <button class="basalam-category-tab" data-category="تنظیمات">نصب و راه‌اندازی</button>
            <button class="basalam-category-tab" data-category="محصولات">مدیریت محصولات</button>
            <button class="basalam-category-tab" data-category="سفارشات">مدیریت سفارشات</button>
            <button class="basalam-category-tab" data-category="همگام‌سازی">همگام‌سازی</button>
        </div>

        <div class="basalam-faq-sections">
            <?php
            sync_basalam_Admin_UI::render_faq_by_category(sync_basalam_Admin_Help::get_categories())
            ?>
        </div>
    </div>

</div>