<?php

if (! defined('ABSPATH')) exit;
?>
<section class="sg-basalam-orders sg-load-region" data-sg-load data-sg-load-state="ready" data-sg-basalam-orders data-website-orders-url="<?php echo esc_url(admin_url((class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order')); ?>" hidden aria-labelledby="sg-basalam-orders-title">
    <?php require __DIR__ . '/loading-region.php'; ?>
    <div data-sg-load-content>
    <div class="sg-basalam-orders__heading">
        <span class="sg-basalam-orders__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 2-5h14l2 5M3 9v2a3 3 0 0 0 5 2 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 5-2V9H3ZM5 14v6h14v-6M9 20v-5h6v5"/></svg>
        </span>
        <div>
            <h4 class="sg-send__section-title" id="sg-basalam-orders-title">انتخاب سفارش باسلام</h4>
        </div>
    </div>

    <div class="sg-basalam-orders__toolbar">
        <div class="sg-field sg-basalam-orders__search">
            <label class="sg-field__label" for="sg-basalam-order-search">شماره سفارش، نام گیرنده یا نام محصول</label>
            <div class="sg-basalam-orders__search-controls">
                <input class="sg-field__input" id="sg-basalam-order-search" data-sg-bo-search type="text" dir="rtl" maxlength="120" autocomplete="off" placeholder="مثلاً علی رضایی، چای یا 84160814" aria-describedby="sg-basalam-order-search-help sg-basalam-order-search-error">
                <button type="button" class="sg-button sg-button--secondary" data-sg-bo-search-submit>جست‌وجو</button>
                <button type="button" class="sg-button sg-button--secondary sg-basalam-orders__refresh" data-sg-bo-refresh>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M6.1 6.1A8 8 0 0 1 20 12M4 12a8 8 0 0 0 13.9 5.9"/></svg>
                    تازه‌سازی سفارش‌ها
                </button>
            </div>
            <p class="sg-field__hint" id="sg-basalam-order-search-help">برای دیدن همه سفارش‌ها، جست‌وجو را خالی بگذارید.</p>
            <p class="sg-field__error" id="sg-basalam-order-search-error" data-sg-bo-search-error hidden></p>
        </div>
    </div>

    <div class="sg-basalam-orders__feedback" data-sg-bo-feedback role="status" aria-live="polite" hidden>
        <span data-sg-bo-message></span>
        <button type="button" class="sg-button sg-button--secondary" data-sg-bo-retry hidden>تلاش دوباره</button>
    </div>
    <div class="sg-basalam-orders__empty" data-sg-bo-empty hidden>
        <strong>سفارشی پیدا نشد</strong>
        <p data-sg-bo-empty-message>هنوز سفارشی برای این غرفه در این فهرست وجود ندارد.</p>
        <a data-sg-bo-orders-link href="https://vendor.basalam.com/orders" target="_blank" rel="noopener noreferrer">مشاهده سفارش‌ها در باسلام <span aria-hidden="true">↗</span></a>
    </div>
    <div class="sg-basalam-orders__list" data-sg-bo-list role="list" aria-label="سفارش‌های باسلام"></div>
    <nav class="sg-basalam-orders__pagination" data-sg-bo-pagination hidden aria-label="صفحه‌بندی سفارش‌های باسلام">
        <button type="button" class="sg-button sg-button--secondary" data-sg-bo-prev disabled>صفحه قبل</button>
        <span data-sg-bo-page aria-live="polite"></span>
        <button type="button" class="sg-button sg-button--secondary" data-sg-bo-next disabled>صفحه بعد</button>
    </nav>

    <section class="sg-basalam-orders__confirmation" data-sg-bo-import hidden aria-labelledby="sg-basalam-import-title">
        <h5 id="sg-basalam-import-title">افزودن این سفارش به ووکامرس</h5>
        <p data-sg-bo-import-order></p>
        <p>با ادامه، این سفارش یک‌بار به سفارشات ووکامرس اضافه می‌شود. همگام‌سازی می‌تواند موجودی کالا و اعلان‌های سفارش را تغییر دهد.</p>
        <div class="sg-basalam-orders__confirmation-actions">
            <button type="button" class="sg-button sg-button--primary" data-sg-bo-import-confirm>تأیید افزودن و ارسال مرسوله</button>
            <button type="button" class="sg-button sg-button--secondary" data-sg-bo-import-cancel>انصراف</button>
        </div>
    </section>
    </div>
</section>
