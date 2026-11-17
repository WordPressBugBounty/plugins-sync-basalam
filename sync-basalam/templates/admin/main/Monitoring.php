<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) { wp_die('دسترسی مدیریتی لازم است.', '', ['response' => 403]); }
?>
<main class="basalam-monitoring-page" dir="rtl" aria-label="پایشگر ووسلام">
 <div class="app-shell">
  <div class="breadcrumb"><span>ووسلام</span><span aria-hidden="true">/</span><span>پایشگر</span><bdi dir="ltr"><?php echo esc_html(wp_parse_url(get_site_url(), PHP_URL_HOST)); ?></bdi></div>
  <header class="page-head"><div class="heading-line"><span class="brand-mark"><span class="icon" data-icon="chart-line"></span></span><h1>پایشگر ووسلام</h1></div><button class="button secondary small" id="monitoring-help" hidden type="button" data-action="help"><span class="icon" data-icon="book"></span>راهنمای پایشگر</button></header>
  <nav class="tabs" role="tablist" aria-label="بخش‌های پایشگر">
   <button id="tab-overview" type="button" role="tab" data-tab="overview" aria-controls="panel-overview" aria-selected="true"><span class="icon" data-icon="chart-line"></span>نمای کلی</button>
   <button id="tab-history" type="button" role="tab" data-tab="history" aria-controls="panel-history" aria-selected="false" tabindex="-1"><span class="icon" data-icon="time"></span>تاریخچهٔ پایش</button>
   <button id="tab-coverage" type="button" role="tab" data-tab="coverage" aria-controls="panel-coverage" aria-selected="false" tabindex="-1"><span class="icon" data-icon="box"></span>محصولات در حال پایش</button>
   <button id="tab-settings" type="button" role="tab" data-tab="settings" aria-controls="panel-settings" aria-selected="false" tabindex="-1"><span class="icon" data-icon="wrench"></span>اصلاح خودکار</button>
   <button id="tab-subscription" type="button" role="tab" data-tab="subscription" aria-controls="panel-subscription" aria-selected="false" tabindex="-1"><span class="icon" data-icon="wallet"></span>اشتراک</button>
  </nav>
  <div class="report-toolbar" data-tour="refresh"><div id="freshness"></div><button class="button secondary small" type="button" data-action="refresh"><span class="icon" data-icon="refresh"></span><span>دریافت آخرین گزارش</span></button></div>
  <div id="page-notice" aria-live="polite"></div><p class="request-status" id="request-status" role="status" aria-live="polite"></p>
  <section id="panel-overview" role="tabpanel" aria-labelledby="tab-overview"><p role="status">در حال دریافت وضعیت فروشگاه…</p></section>
  <section id="panel-history" role="tabpanel" aria-labelledby="tab-history" hidden></section>
  <section id="panel-coverage" role="tabpanel" aria-labelledby="tab-coverage" hidden></section>
  <section id="panel-settings" role="tabpanel" aria-labelledby="tab-settings" hidden></section>
  <section id="panel-subscription" role="tabpanel" aria-labelledby="tab-subscription" hidden></section>
 </div>

<dialog id="detail-dialog" aria-labelledby="dialog-title"><header class="dialog-head"><div><p id="dialog-kicker"></p><h2 id="dialog-title"></h2></div><button type="button" class="icon-button" data-action="close-dialog" aria-label="بستن جزئیات"><span class="icon" data-icon="close"></span></button></header><div id="dialog-body" class="dialog-body"></div></dialog>
<dialog id="coverage-dialog" class="coverage-dialog" aria-labelledby="coverage-dialog-title" aria-describedby="coverage-dialog-description"><header class="coverage-dialog-head"><div><h2 id="coverage-dialog-title">جایگزینی محصول</h2><p id="coverage-dialog-description">یک محصول بدون پایش را جایگزین محصول فعلی کنید.</p></div><button type="button" class="icon-button" data-action="coverage-cancel" aria-label="بستن جایگزینی"><span class="icon" data-icon="close"></span></button></header><div id="coverage-dialog-body"></div></dialog>
<div id="toast" hidden></div>
<div id="payment-toast" hidden></div>
<noscript><p>برای دریافت گزارش و مدیریت پایشگر، جاوااسکریپت مرورگر را فعال کنید.</p></noscript>
</main>
