<?php

if (! defined('ABSPATH')) exit;

/** Shared review with separate native-order and independent-parcel funding. */
?>
<div id="sg-bulk-shipping-modal" class="sgb-overlay salam-gate-page" dir="rtl" hidden>
    <section class="sgb-dialog" role="dialog" aria-modal="true" aria-labelledby="sgb-title">
        <header class="sgb-header">
            <span class="sgb-brand" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="M4 8h15v15H4V8Zm15 6h5l4 5v4h-9V14ZM8 26a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm16 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM1 13h7M1 17h5" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/></svg></span>
            <div><h2 id="sgb-title">ارسال گروهی با سلام‌گیت</h2></div>
            <button type="button" class="sgb-close" data-sgb-close aria-label="بستن ارسال گروهی">&times;</button>
        </header>
        <ol class="sgb-steps" aria-label="مراحل ارسال گروهی">
            <li data-sgb-step="review"><span>۱</span>بررسی بسته‌ها</li>
            <li data-sgb-step="quotes"><span>۲</span>روش ارسال و پرداخت</li>
            <li data-sgb-step="results"><span>۳</span>نتیجه ارسال</li>
        </ol>
        <div class="sgb-body">
            <div class="sgb-loading" data-sgb-loading role="status" aria-live="polite" hidden>
                <span class="sg-loading-spinner sgb-motion-truck" aria-hidden="true"></span>
                <strong>در حال دریافت اطلاعات…</strong>
            </div>
            <div class="sgb-feedback" data-sgb-feedback role="status" aria-live="polite" hidden></div>
            <div data-sgb-content hidden>
                <div class="sgb-overview">
                    <strong data-sgb-count></strong>
                    <span data-sgb-stage-hint>وزن نهایی همراه بسته‌بندی را برای هر مرسوله تأیید کنید.</span>
                    <button type="button" class="sgb-refresh" data-sgb-refresh aria-busy="false"><span class="dashicons dashicons-update" aria-hidden="true"></span><span>به‌روزرسانی سفارش‌ها</span></button>
                </div>
                <p class="sgb-edit-hint" data-sgb-edit-hint hidden>سفارش در تب جدید باز می‌شود. پس از ذخیره اصلاحات، به همین پنجره برگردید و «به‌روزرسانی سفارش‌ها» را بزنید.</p>
                <details class="sgb-exclusions" data-sgb-exclusions hidden><summary data-sgb-excluded-count></summary><ul data-sgb-excluded-list></ul></details>
                <section class="sgb-common" data-sgb-common aria-label="تنظیمات مشترک بسته‌ها">
                    <h3><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 9 5v9l-9 5-9-5V8l9-5Zm0 10 9-5M12 13 3 8m9 5v9M7.5 5.5l9 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>تنظیمات همه بسته‌ها</h3>
                    <label>بسته‌بندی همه مرسوله‌ها<select data-sgb-box-all><option value="">بدون تغییر</option></select></label>
                    <label>نوع همه مرسوله‌ها<select data-sgb-category-all><option value="">بدون تغییر</option></select></label>
                </section>
                <div class="sgb-layout">
                    <section class="sgb-rows" data-sgb-rows aria-label="مرسوله‌های انتخاب‌شده"></section>
                    <aside class="sgb-summary" data-sgb-summary hidden aria-label="خلاصه تأیید و پرداخت">
                        <h3><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 6h16v14H4V6Zm0 4h16M8 3v5m8-5v5m-8 7 2 2 5-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>تأیید و پرداخت</h3>
                        <div class="sgb-total"><span>مبلغ نهایی همه مرسوله‌ها</span><strong data-sgb-total>—</strong><small>شامل بیمه، مالیات و هزینه‌های جانبی</small></div>
                        <div class="sgb-native-funding" data-sgb-native-funding hidden>
                            <span class="sgb-native-funding-icon" aria-hidden="true">✓</span>
                            <div><strong>از مبلغ همان سفارش باسلام</strong><span data-sgb-native-total>—</span><small>هزینه هر مرسوله فقط از سفارش خودش کسر می‌شود؛ پس از تأیید نهایی شما.</small></div>
                        </div>
                        <div data-sgb-website-funding>
                        <p class="sgb-funding-heading">پرداخت مرسوله‌های غیرباسلام <strong data-sgb-website-total>—</strong></p>
                        <div class="sgb-payment" role="radiogroup" aria-label="روش پرداخت هزینه ارسال">
                            <label class="sgb-payment-card"><input type="radio" name="sgb-payment" value="20" checked><span class="sgb-payment-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="m3 9 2-5h14l2 5m-18 0v2a3 3 0 0 0 5 2 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 5-2V9H3Zm2 5v6h14v-6M9 20v-5h6v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span><strong>تراز غرفه</strong><small data-sgb-balance="20">در حال دریافت…</small></span><span class="sgb-payment-check" aria-hidden="true">✓</span></label>
                            <label class="sgb-payment-card"><input type="radio" name="sgb-payment" value="21"><span class="sgb-payment-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M20 8V5a2 2 0 0 0-2-2H6a3 3 0 0 0 0 6h14v10H6a3 3 0 0 1-3-3V6m17 7h-4v3h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span><strong>کیف پول باسلام</strong><small data-sgb-balance="21">در حال دریافت…</small></span><span class="sgb-payment-check" aria-hidden="true">✓</span></label>
                        </div>
                        <p class="sgb-shortfall" data-sgb-shortfall hidden></p>
                        <div class="sgb-balance-actions"><a data-sgb-topup hidden href="https://wallet.basalam.com/?from=vendor" target="_blank" rel="noopener noreferrer">شارژ کیف پول <span aria-hidden="true">↗</span></a><button type="button" data-sgb-balances aria-busy="false"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M5.2 8a7 7 0 0 1 11.6-3L20 8M4 16l3.2 3A7 7 0 0 0 18.8 16" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg><span data-sgb-balances-label>تازه‌سازی موجودی</span></button></div>
                        </div>
                        <label class="sgb-consent"><input type="checkbox" data-sgb-consent><span>روش ارسال و مبلغ نهایی همه بسته‌های انتخاب‌شده را تأیید می‌کنم.</span></label>
                    </aside>
                </div>
                <div class="sgb-progress" data-sgb-progress hidden role="status" aria-live="polite"><span data-sgb-progress-text></span><progress data-sgb-progress-bar value="0" max="1"></progress><button type="button" data-sgb-stop>توقف پس از این مرسوله</button></div>
                <section class="sgb-results" data-sgb-results hidden aria-label="نتیجه ارسال گروهی"><strong data-sgb-result-title></strong><p data-sgb-result-text></p><a data-sgb-result-link href="<?php echo esc_url(admin_url('admin.php?page=salam_gate_parcels')); ?>">مشاهده مرسوله‌ها و چاپ لیبل <span aria-hidden="true">↗</span></a></section>
            </div>
        </div>
        <footer class="sgb-footer">
            <p data-sgb-footer-note hidden></p>
            <div><button type="button" class="sgb-button sgb-button--secondary" data-sgb-back hidden>ویرایش اطلاعات</button><span id="sgb-quote-hint" class="sgb-quote-hint" data-sgb-quote-hint hidden>ابتدا وزن مرسوله‌ها را تأیید کنید.</span><button type="button" class="sgb-button sgb-button--primary" data-sgb-quote aria-describedby="sgb-quote-hint" hidden disabled>مرحله بعد</button><button type="button" class="sgb-button sgb-button--primary" data-sgb-pay hidden disabled>تأیید و پرداخت مرسوله‌ها</button><button type="button" class="sgb-button sgb-button--secondary" data-sgb-done hidden>بستن</button></div>
        </footer>
        <div class="sgb-preparation-panel" data-sgb-preparation-panel data-state="working" hidden>
            <section class="sgb-preparation-dialog" role="dialog" aria-modal="true" aria-labelledby="sgb-preparation-title" aria-describedby="sgb-preparation-caption" tabindex="-1">
                <span class="sgb-preparation-art" aria-hidden="true">
                    <span class="sgb-preparation-halo"></span>
                    <span class="sg-loading-spinner sgb-motion-truck"></span>
                    <svg class="sgb-preparation-complete-icon" viewBox="0 0 48 48" fill="none"><path d="m13 24 8 8 15-17" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <h3 id="sgb-preparation-title" data-sgb-preparation-title>آماده‌سازی ارسال</h3>
                <p id="sgb-preparation-caption" data-sgb-preparation-caption>در حال دریافت نرخ‌ها از سلام‌گیت</p>
                <ol class="sgb-preparation-tasks" data-sgb-checklist aria-label="پیشرفت آماده‌سازی نرخ‌ها" aria-live="polite" aria-atomic="false">
                    <?php $taskNumber = 0; foreach (['rates' => 'محاسبه قیمت‌ها', 'discount' => 'در حال اعمال تخفیف ویژه سلام‌گیت', 'funding' => 'محاسبه هزینه هر سفارش باسلام', 'done' => 'تکمیل فرایند'] as $task => $title) : ?>
                        <li data-sgb-task="<?php echo esc_attr($task); ?>" data-state="waiting"<?php echo $task === 'funding' ? ' data-sgb-funding-task hidden' : ''; ?>>
                            <span class="sgb-task-symbol" aria-hidden="true"><span class="sgb-task-number"><?php echo esc_html(['۱', '۲', '۳', '۴'][$taskNumber++]); ?></span><svg viewBox="0 0 20 20" fill="none"><path d="m5 10 3.5 3.5L15 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span class="sgb-task-title"<?php echo $task === 'discount' ? ' data-sgb-discount-label' : ''; ?>><?php echo esc_html($title); ?></span>
                            <span class="sgb-task-status" data-sgb-task-status>در انتظار</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p class="sgb-preparation-error" data-sgb-preparation-error role="alert" hidden></p>
                <button type="button" class="sgb-button sgb-button--secondary" data-sgb-preparation-dismiss hidden>بازگشت به بررسی</button>
            </section>
        </div>
    </section>
</div>
