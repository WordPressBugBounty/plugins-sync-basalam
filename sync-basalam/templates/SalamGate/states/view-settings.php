<?php

if (! defined('ABSPATH')) exit;

/**
 * view=settings — تنظیمات محیط فعال (پارت ۵).
 * ساختار: لینک مراکز و ماشین‌حساب، کارت‌های آدرس فرستنده با radio
 * و تاریخ درخواست جلالی/وضعیت/ویرایش، ماشین‌حساب، FAQ و قوانین.
 *
 * @var array $data
 */

$postOfficesUrl = admin_url('admin.php?page=salam_gate&view=post-offices');
$calculatorUrl = admin_url('admin.php?page=salam_gate&view=calculator');
$trackingUrl = admin_url('admin.php?page=salam_gate&view=tracking');
?>
<div class="sg-view sg-view--settings" data-view="settings">
    <div class="sg-settings" data-sg-settings>
        <section class="sg-card sg-settings__shortcuts" aria-labelledby="sg-settings-shortcuts-title">
            <h3 class="sg-card__title" id="sg-settings-shortcuts-title">دسترسی سریع به تنظیمات</h3>
            <nav class="sg-settings__nav" aria-label="بخش‌های تنظیمات">
                <a href="#sg-settings-account"><span class="dashicons dashicons-admin-home" aria-hidden="true"></span><span><strong>آدرس‌های فرستنده</strong><small>نشانی و مرکز تحویل مرسوله</small></span><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></a>
                <a href="#sg-settings-tools"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span><span><strong>ابزارهای ارسال</strong><small>مراکز تحویل و محاسبه هزینه</small></span><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></a>
                <a href="#sg-settings-help"><span class="dashicons dashicons-book-alt" aria-hidden="true"></span><span><strong>پرسش‌های متداول و قوانین</strong><small>پاسخ پرسش‌ها و شرایط استفاده</small></span><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></a>
            </nav>
        </section>

        <section class="sg-card sg-settings__group" id="sg-settings-account" aria-labelledby="sg-settings-account-title">
            <h3 class="sg-card__title" id="sg-settings-account-title"><span class="dashicons dashicons-location-alt" aria-hidden="true"></span> آدرس‌های فرستنده</h3>
            <p class="sg-card__detail">نشانی فرستنده و مرکز تحویل مرسوله‌ها را مدیریت کنید؛ آدرس دلخواه را به‌عنوان پیش‌فرض انتخاب کنید.</p>
            <div class="sg-settings__addresses" data-sg-addresses>
                <div class="sg-settings__loading"><span class="sg-loading-spinner" aria-hidden="true"></span> در حال دریافت اطلاعات…</div>
            </div>
            <div class="sg-wizard__notice" data-sg-settings-notice style="display:none;" role="status" aria-live="polite"></div>
        </section>

        <section class="sg-card sg-settings__group sg-settings__activation" aria-labelledby="sg-settings-activation-title">
            <div>
                <h3 class="sg-card__title" id="sg-settings-activation-title">وضعیت سلام‌گیت در این سایت</h3>
                <p class="sg-card__detail">با غیرفعال‌سازی، امکانات سلام‌گیت از سفارش‌های ووکامرس پنهان می‌شود. حساب پستی و مرسوله‌های قبلی حذف نمی‌شوند و فعال‌سازی دوباره هزینه ندارد.</p>
            </div>
            <details class="sg-settings__deactivate-confirm">
                <summary class="sg-button sg-button--secondary">غیرفعال‌سازی سلام‌گیت</summary>
                <p class="sg-card__detail">سلام‌گیت فقط در همین سایت غیرفعال می‌شود؛ پرداخت یا ارسال قبلی لغو نخواهد شد. ادامه می‌دهید؟</p>
                <button type="button" class="sg-button sg-button--secondary" data-sg-site-enabled="0">بله، غیرفعال شود</button>
            </details>
            <p class="sg-site-activation-message" data-sg-site-activation-message role="status" aria-live="polite" hidden></p>
        </section>

        <section class="sg-card sg-settings__group sg-settings__help" id="sg-settings-help" aria-labelledby="sg-settings-help-title">
            <h3 class="sg-card__title" id="sg-settings-help-title"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span> ابزارها، پرسش‌های متداول و قوانین</h3>
            <!-- ماشین‌حساب و مراکز -->
            <section class="sg-section sg-section--tight sg-settings__toolbox" id="sg-settings-tools" aria-labelledby="sg-settings-tools-title">
                <h4 class="sg-section__title" id="sg-settings-tools-title">ابزارهای موردنیاز شما</h4>
                <nav class="sg-settings__tools" aria-label="ابزارهای سلام‌گیت">
                    <a href="<?php echo esc_url($postOfficesUrl); ?>"><span class="dashicons dashicons-location-alt" aria-hidden="true"></span><span><strong>مراکز تحویل</strong><small>پیداکردن مرکز پستی مناسب</small></span><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></a>
                    <a href="<?php echo esc_url($calculatorUrl); ?>"><span class="dashicons dashicons-calculator" aria-hidden="true"></span><span><strong>ماشین‌حساب هزینه ارسال</strong><small>برآورد هزینه پیش از ثبت</small></span><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></a>
                    <a href="<?php echo esc_url($trackingUrl); ?>"><span class="dashicons dashicons-search" aria-hidden="true"></span><span><strong>پیگیری مرسوله</strong><small>مشاهده وضعیت با کد رهگیری</small></span><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></a>
                </nav>
            </section>

            <!-- FAQ -->
            <section class="sg-section sg-section--tight">
                <h4 class="sg-section__title">پرسش‌های متداول</h4>
                <div class="sg-faq">
                    <?php
                    $faqs = [
                        ['فرق پست پیشتاز، اکسپرس و ویژه چیست؟', [
                            'پیشتاز: گزینه اقتصادی‌تر برای ارسال‌های معمول است؛ زمان تحویل آن معمولاً از اکسپرس و ویژه بیشتر است.',
                            'اکسپرس: برای ارسال سریع‌تر مناسب است؛ نسبت به پیشتاز زمان تحویل کوتاه‌تر و معمولاً هزینه بیشتری دارد.',
                            'ویژه: سریع‌ترین گزینه در میان این سه سرویس برای ارسال‌های فوری است و معمولاً هزینه بیشتری دارد.',
                            'زمان تحویل از پذیرش بسته در پست محاسبه می‌شود و به مسیر، زمان پذیرش و روزهای کاری بستگی دارد. سرویس‌های قابل انتخاب و هزینه نهایی همان مرسوله را در مرحله «انتخاب روش ارسال و پرداخت» بررسی کنید.',
                        ]],
                        ['مرکز تحویل چیست و چگونه تغییر می‌کند؟', 'مرکز تحویل نقطه تحویل مرسوله‌ها به پست است؛ از «ویرایش فرستنده» می‌توانید منطقه پستی و مرکز تحویل دیگری در استان فرستنده انتخاب کنید.'],
                        ['تغییر آدرس فرستنده چه اثری دارد؟', 'اطلاعات فرستنده را از بخش «ویرایش فرستنده» تغییر دهید. مرسوله‌های قبلی، آدرس زمان ثبت خود را حفظ می‌کنند.'],
                        ['هزینه ارسال چگونه تسویه می‌شود؟', 'هزینه هر مرسوله هنگام ثبت نمایش و همان‌جا پرداخت می‌شود؛ فاکتور و تراکنش‌ها در سوابق ثبت می‌شود.'],
                        ['کد رهگیری را کِی می‌گیرم؟', 'پس از پرداخت هزینه ارسال و صدور بارکد پستی، در جزئیات مرسوله در دسترس است.'],
                    ];
                    foreach ($faqs as [$q, $a]) :
                        ?>
                        <details class="sg-faq__item">
                            <summary class="sg-faq__q"><?php echo esc_html($q); ?></summary>
                            <?php foreach ((array) $a as $paragraph) : ?>
                                <p class="sg-faq__a"><?php echo esc_html($paragraph); ?></p>
                            <?php endforeach; ?>
                        </details>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php
            $sgTermsCompact = true;
            include dirname(__DIR__) . '/terms-of-use.php';
            unset($sgTermsCompact);
            ?>
        </section>
    </div>
</div>

<!-- Sender settings: address content and delivery-center edits use their own API contracts. -->
<div class="sg-modal-overlay" data-sg-address-edit-modal style="display:none;">
    <div class="sg-modal sg-sender-editor" role="dialog" aria-modal="true" aria-labelledby="sg-sender-editor-title">
        <div class="sg-modal__header">
            <h4 id="sg-sender-editor-title">ویرایش فرستنده</h4>
            <button type="button" class="sg-modal__close" data-close-modal aria-label="بستن">&times;</button>
        </div>
        <div class="sg-modal__body sg-load-region" data-sg-load data-sg-load-state="ready">
            <?php include dirname(__DIR__) . '/partials/loading-region.php'; ?>
            <div data-sg-load-content>
            <dl class="sg-sender-editor__identity" aria-label="اطلاعات هویتی فرستنده">
                <div><dt>کد ملی</dt><dd data-sender-national-code>—</dd></div>
                <div><dt>سریال کارت ملی</dt><dd data-sender-national-serial>—</dd></div>
            </dl>
            <section class="sg-sender-editor__address" aria-labelledby="sg-sender-address-title">
                <div class="sg-sender-editor__address-heading">
                    <h5 id="sg-sender-address-title"><span class="dashicons dashicons-location-alt" aria-hidden="true"></span> آدرس فرستنده</h5>
                    <button type="button" class="sg-button sg-button--secondary" data-sender-edit-address><span class="dashicons dashicons-edit" aria-hidden="true"></span> ویرایش آدرس</button>
                </div>
                <strong data-sender-summary-name>—</strong>
                <p class="sg-card__detail" data-sender-summary-address>—</p>
                <p class="sg-card__detail" data-sender-summary-contact>—</p>
            </section>
            <div data-sender-delivery-fields>
                <div class="sg-field">
                    <label class="sg-field__label" for="sgae-location">منطقه پستی <span class="sg-req">*</span></label>
                    <select class="sg-field__select" id="sgae-location" data-sender-location disabled><option value="">در حال دریافت مناطق پستی…</option></select>
                </div>
                <div class="sg-field">
                    <label class="sg-field__label" for="sgae-node">مرکز تحویل و نقطه مبادله پستی <span class="sg-req">*</span></label>
                    <select class="sg-field__select" id="sgae-node" data-sender-node disabled><option value="">ابتدا منطقه پستی را انتخاب کنید</option></select>
                </div>
                <p class="sg-field__hint">تغییر مرکز تحویل ممکن است حساب ارسال را به «در انتظار تأیید» برگرداند.</p>
            </div>
            <div class="sg-wizard__notice" data-addr-edit-notice style="display:none;" role="status" aria-live="polite"></div>
            </div>
        </div>
        <div class="sg-modal__footer">
            <button type="button" class="sg-button sg-button--secondary" data-close-modal>بستن</button>
            <button type="button" class="sg-button sg-button--primary" data-save-address-edit disabled>ذخیره مرکز تحویل</button>
        </div>
    </div>
</div>

<!-- Address changes submit only fields explicitly edited by the user. -->
<div class="sg-modal-overlay" data-sg-address-details-modal style="display:none;">
    <div class="sg-modal sg-sender-editor sg-address-details" role="dialog" aria-modal="true" aria-labelledby="sg-address-details-title">
        <div class="sg-modal__header">
            <h4 id="sg-address-details-title">ویرایش آدرس فرستنده</h4>
            <button type="button" class="sg-modal__close" data-close-modal aria-label="بستن">&times;</button>
        </div>
        <div class="sg-modal__body">
            <input type="hidden" name="address_id" data-addr-edit-id>
            <dl class="sg-address-details__location" aria-label="موقعیت ثبت‌شده آدرس">
                <div><dt>شهر</dt><dd data-addr-edit-city>—</dd></div>
                <div><dt>کد پستی</dt><dd data-addr-edit-postal>—</dd></div>
            </dl>
            <div class="sg-field">
                <label class="sg-field__label" for="sgae-name">نام و نام خانوادگی <span class="sg-req">*</span></label>
                <input class="sg-field__input" id="sgae-name" name="full_name" type="text" autocomplete="name" required minlength="3" data-sg-validate data-sg-label="نام و نام خانوادگی">
            </div>
            <div class="sg-field">
                <div class="sg-address-details__field-heading"><label class="sg-field__label" for="sgae-address">آدرس پستی <span class="sg-req">*</span></label><small data-sg-address-length aria-live="polite">۰ کاراکتر</small></div>
                <textarea class="sg-field__input" id="sgae-address" name="address" rows="3" autocomplete="street-address" required minlength="10" data-sg-validate data-sg-label="آدرس پستی"></textarea>
            </div>
            <div class="sg-field">
                <label class="sg-field__label" for="sgae-phone">شماره موبایل <span class="sg-req">*</span></label>
                <input class="sg-field__input" id="sgae-phone" name="phone" type="tel" dir="ltr" inputmode="tel" autocomplete="tel" required data-sg-length="11" data-sg-validate data-sg-label="شماره موبایل">
            </div>
            <div class="sg-wizard__notice" data-addr-details-notice style="display:none;" role="status" aria-live="polite"></div>
        </div>
        <div class="sg-modal__footer">
            <button type="button" class="sg-button sg-button--secondary" data-close-modal>انصراف</button>
            <button type="button" class="sg-button sg-button--primary" data-save-address-details>ذخیره اطلاعات فرستنده</button>
        </div>
    </div>
</div>
