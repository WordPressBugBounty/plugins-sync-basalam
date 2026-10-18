<?php

if (! defined('ABSPATH')) exit;

/**
 * داشبورد محیط فعال — اقدام اصلی ثبت مرسوله و کارت‌های گزارش عملکرد.
 * آمار از AJAX واقعی — هیچ عدد نمایشی ساختگی نیست.
 *
 * @var array $data
 */

$sendUrl = admin_url('admin.php?page=salam_gate&view=send');
?>
<div class="sg-state sg-state--active" data-state="active">
    <section class="sg-card sg-dashboard__main" aria-labelledby="sg-dashboard-title">

    <section class="sg-dashboard__intro" aria-labelledby="sg-dashboard-title">
        <div class="sg-dashboard__intro-text">
            <h2 class="sg-dashboard__title" id="sg-dashboard-title">ارسال سفارش‌های فروشگاه</h2>
            <p class="sg-dashboard__description">با سلام‌گیت، سفارش‌های ووکامرس و شبکه‌های اجتماعی را از همین‌جا برای ارسال پستی آماده کنید.</p>
            <ul class="sg-dashboard__benefits" aria-label="امکانات سلام‌گیت">
                <li><span class="dashicons dashicons-calculator" aria-hidden="true"></span>محاسبه هزینه</li>
                <li><span class="dashicons dashicons-printer" aria-hidden="true"></span>پرداخت و چاپ برچسب</li>
                <li><span class="dashicons dashicons-location-alt" aria-hidden="true"></span>پیگیری مرسوله</li>
            </ul>
        </div>
        <a class="sg-button sg-button--primary sg-button--lg" href="<?php echo esc_url($sendUrl); ?>">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
            ثبت مرسوله
        </a>
    </section>

    <!-- گزارش عملکرد -->
    <h3 class="sg-dash-section-title">گزارش عملکرد</h3>
    <div class="sg-stats sg-stats--cards" data-sg-stats>
        <article class="sg-stats__card">
            <span class="sg-stats__card-icon dashicons dashicons-archive" aria-hidden="true"></span>
            <div class="sg-stats__card-content">
                <h4 class="sg-stats__label">تعداد مرسوله‌ها</h4>
                <p class="sg-stats__value"><span data-stat="order_count">—</span><span class="sg-stats__unit">مرسوله</span></p>
            </div>
        </article>
        <article class="sg-stats__card">
            <span class="sg-stats__card-icon dashicons dashicons-money-alt" aria-hidden="true"></span>
            <div class="sg-stats__card-content">
                <h4 class="sg-stats__label">میانگین هزینه ارسال</h4>
                <p class="sg-stats__value"><span data-stat="avg_shipping_cost_toman">—</span><span class="sg-stats__unit">تومان</span></p>
            </div>
        </article>
        <article class="sg-stats__card">
            <span class="sg-stats__card-icon dashicons dashicons-chart-line" aria-hidden="true"></span>
            <div class="sg-stats__card-content">
                <h4 class="sg-stats__label">سود ارسال</h4>
                <p class="sg-stats__value"><span data-stat="total_profit_toman">—</span><span class="sg-stats__unit">تومان</span></p>
            </div>
        </article>
    </div>
    <p class="sg-stats__state" data-sg-stats-state></p>
    </section>
</div>
