<?php

use SyncBasalam\Admin\Faq;
use SyncBasalam\Admin\Components\CommonComponents;

defined('ABSPATH') || exit;
?>
<div class="basalam-container basalam-gap-20">
    <!-- Header Section -->
    <div class="basalam-header basalam-max-width-none basalam-width-75">
        <div class="basalam-header-data">
            <img src="<?php echo esc_url(syncBasalamPlugin()->assetsUrl() . "/images/basalam.svg") ?>" alt="Basalam">
            <div>
                <h1 class="basalam-h basalam-text-justify">راهنمای استفاده از افزونه باسلام</h1>
                <p class="basalam-p basalam-margin-top-17 basalam-text-right">در این صفحه می‌توانید پاسخ سوالات متداول و راهنمای استفاده از افزونه را مشاهده کنید.</p>
                <p class="basalam-p basalam-text-justify basalam-text-right">برای مشاهده ویدیو های آموزشی پلاگین <b> <a href="https://wp.hamsalam.ir/help" target="_blank">کلیک</a></b> کنید.</p>
            </div>
        </div>
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
            <?php CommonComponents::renderFaqByCategory(Faq::getCategories()) ?>
        </div>
    </div>

</div>