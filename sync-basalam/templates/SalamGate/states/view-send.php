<?php

if (! defined('ABSPATH')) exit;

/**
 * view=send — صفحه ارسال مستقل مرسوله (پارت ۶).
 * فرم مستقل در بخش‌های مبدأ سفارش، گیرنده و فرستنده، مشخصات بسته و اقلام.
 * قراردادهای داده برای ثبت، انتخاب نرخ و پرداخت حفظ می‌شوند.
 *
 * شکاف شناخته‌شده: فرم مرجع فیلد مستقل اقلام ندارد ولی API items غیرخالی می‌خواهد.
 * اقلام اینجا از کاربر گرفته می‌شود — mapping پنهان frontend مرجع معلوم نیست.
 *
 * @var array $data
 */

$backUrl = admin_url('admin.php?page=salam_gate');
$parcelsUrl = admin_url('admin.php?page=salam_gate_parcels');
?>
<div class="sg-view sg-view--send" data-view="send">
    <div class="sg-card sg-send">
        <h3 class="sg-card__title">ارسال سفارش با سلام‌گیت</h3>
        <ol class="sg-send__steps" aria-label="مراحل ثبت و ارسال مرسوله">
            <li class="sg-send__step is-active" data-send-stage="1" aria-current="step">
                <button type="button" class="sg-send__step-button" data-send-go-stage="1" aria-controls="sg-send-stage-1" aria-current="step" aria-label="مرحله ۱: اطلاعات مرسوله">
                    <span class="sg-send__step-number" aria-hidden="true">۱</span><span>اطلاعات</span>
                </button>
            </li>
            <li class="sg-send__step" data-send-stage="2">
                <button type="button" class="sg-send__step-button" data-send-go-stage="2" aria-controls="sg-send-stage-2" aria-label="مرحله ۲: انتخاب روش ارسال" disabled>
                    <span class="sg-send__step-number" aria-hidden="true">۲</span><span>انتخاب روش ارسال</span>
                </button>
            </li>
            <li class="sg-send__step" data-send-stage="3">
                <button type="button" class="sg-send__step-button" data-send-go-stage="3" aria-controls="sg-send-stage-3" aria-label="مرحله ۳: تأیید و پرداخت" disabled>
                    <span class="sg-send__step-number" aria-hidden="true">۳</span><span>تأیید و پرداخت</span>
                </button>
            </li>
        </ol>

        <div class="sg-send__stage-panel" id="sg-send-stage-1" data-send-stage-panel="1">
            <p class="sg-send__edit-notice" data-send-edit-notice style="display:none;" role="status">پس از ویرایش اطلاعات، برای ادامه دوباره نرخ‌های ارسال را دریافت کنید.</p>
            <form data-sg-send-form novalidate>
                <section class="sg-send__section" aria-labelledby="sg-send-source-title">
                    <h4 class="sg-send__section-title" id="sg-send-source-title" data-send-stage-heading="1" tabindex="-1">مبدأ سفارش <span class="sg-req">*</span></h4>
                    <p class="sg-send__section-desc">سفارش را از کدام بستر دریافت کرده‌اید؟</p>
                    <div class="sg-field">
                        <div class="sg-send__markets" data-send-markets role="radiogroup" aria-label="مبدأ سفارش" aria-describedby="sg-send-market-error"></div>
                        <p class="sg-field__error" id="sg-send-market-error" data-error-for="market"></p>
                    </div>
                    <div class="sg-send__website-mode" data-send-website-modes role="group" aria-label="روش ثبت سفارش ووکامرس" hidden>
                        <button type="button" class="sg-button sg-button--secondary" data-send-website-mode="orders" aria-pressed="true"><span class="dashicons dashicons-list-view" aria-hidden="true"></span>انتخاب از سفارش‌ها</button>
                        <button type="button" class="sg-button sg-button--secondary" data-send-website-mode="manual" aria-pressed="false"><span class="dashicons dashicons-edit" aria-hidden="true"></span>ورود دستی</button>
                    </div>
                </section>

                <div data-send-direct-fields>
                <section class="sg-send__section" aria-labelledby="sg-send-addresses-title">
                    <h4 class="sg-send__section-title" id="sg-send-addresses-title">گیرنده و فرستنده</h4>
                    <div class="sg-send__addresses">
                        <div class="sg-send__address-card sg-send__recipient-card">
                            <h5 class="sg-send__address-title"><svg class="sg-send__address-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M3 12h5l2 3h4l2-3h5"/><path d="M12 3v8m-4-4 4 4 4-4"/></svg><span>گیرنده <span class="sg-req">*</span></span></h5>
                            <p class="sg-field__hint sg-send__address-hint">مشخصات گیرنده را همین‌جا وارد کنید.</p>
                            <div class="sg-send__receiver-fields" data-send-receiver-fields>
                                <div class="sg-field">
                                    <label class="sg-field__label" for="src-name">نام و نام خانوادگی <span class="sg-req">*</span></label>
                                    <input class="sg-field__input" id="src-name" name="name" data-send-receiver-name type="text" aria-describedby="src-name-error" required minlength="3" data-sg-validate data-sg-label="نام و نام خانوادگی">
                                    <p class="sg-field__error" id="src-name-error" data-receiver-error-for="name"></p>
                                </div>
                                <div class="sg-wizard__row2">
                                    <div class="sg-field">
                                        <label class="sg-field__label" for="src-province">استان <span class="sg-req">*</span></label>
                                        <select class="sg-field__select" id="src-province" aria-describedby="src-province-error"></select>
                                        <p class="sg-field__error" id="src-province-error" data-receiver-error-for="province"></p>
                                    </div>
                                    <div class="sg-field">
                                        <label class="sg-field__label" for="src-city">شهر <span class="sg-req">*</span></label>
                                        <select class="sg-field__select" id="src-city" name="city_id" data-send-receiver-city aria-describedby="src-city-error"></select>
                                        <p class="sg-field__error" id="src-city-error" data-receiver-error-for="city"></p>
                                    </div>
                                </div>
                                <div class="sg-field">
                                    <label class="sg-field__label" for="src-address">آدرس پستی <span class="sg-req">*</span></label>
                                    <textarea class="sg-field__input" id="src-address" name="address" data-send-receiver-address rows="3" maxlength="512" aria-describedby="src-address-error" required minlength="10" data-sg-validate data-sg-label="آدرس پستی"></textarea>
                                    <p class="sg-field__hint"><span data-addr-count>۰</span> / ۵۱۲ کاراکتر</p>
                                    <p class="sg-field__error" id="src-address-error" data-receiver-error-for="address"></p>
                                </div>
                                <div class="sg-wizard__row2">
                                    <div class="sg-field">
                                        <label class="sg-field__label" for="src-postal">کد پستی <span class="sg-req">*</span></label>
                                        <input class="sg-field__input" id="src-postal" name="postal_code" data-send-receiver-postal type="text" dir="ltr" inputmode="numeric" maxlength="10" aria-describedby="src-postal-error" required data-sg-length="10" data-sg-validate data-sg-label="کد پستی">
                                        <p class="sg-field__error" id="src-postal-error" data-receiver-error-for="postal"></p>
                                    </div>
                                    <div class="sg-field">
                                        <label class="sg-field__label" for="src-mobile">شماره موبایل <span class="sg-req">*</span></label>
                                        <input class="sg-field__input" id="src-mobile" name="mobile" data-send-receiver-mobile type="tel" dir="ltr" inputmode="tel" placeholder="09xxxxxxxxx" aria-describedby="src-mobile-error" required data-sg-length="11" data-sg-validate data-sg-label="شماره موبایل">
                                        <p class="sg-field__error" id="src-mobile-error" data-receiver-error-for="mobile"></p>
                                    </div>
                                </div>
                            </div>
                            <p class="sg-field__error" id="sg-send-receiver-error" data-error-for="receiver"></p>
                        </div>
                        <div class="sg-send__address-card sg-send__sender-card">
                            <h5 class="sg-send__address-title"><svg class="sg-send__address-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M3 12h5l2 3h4l2-3h5"/><path d="M12 11V3m-4 4 4-4 4 4"/></svg><span>فرستنده</span></h5>
                            <p class="sg-field__hint sg-send__address-hint">مشخصات فرستنده ثبت‌شده برای این مرسوله</p>
                            <input type="hidden" id="sg-send-sender" name="provider_shop_id" data-send-sender>
                            <div class="sg-send__sender-summary" data-send-sender-summary role="status"></div>
                            <p class="sg-field__error" id="sg-send-sender-error" data-error-for="sender"></p>
                        </div>
                    </div>
                </section>

                <section class="sg-send__section" aria-labelledby="sg-send-package-title">
                    <h4 class="sg-send__section-title" id="sg-send-package-title">مشخصات بسته</h4>
                    <p class="sg-send__section-desc">وزن و ارزش کل بسته را برای محاسبه هزینه ارسال وارد کنید.</p>
                    <div class="sg-send__package-fields">
                        <!-- وزن + ارزش -->
                        <div class="sg-wizard__row2">
                            <div class="sg-field">
                                <label class="sg-field__label" for="send-weight">وزن نهایی مرسوله با بسته‌بندی (گرم) <span class="sg-req">*</span></label>
                                <input class="sg-field__input" id="send-weight" name="weight" type="number" min="50" max="30000" step="1" placeholder="مثلاً 850" aria-describedby="sg-send-weight-error" required data-sg-validate data-sg-label="وزن مرسوله" data-sg-min-message="حداقل وزن مجاز مرسوله ۵۰ گرم است." data-sg-unit="گرم" data-sg-integer="1">
                                <p class="sg-field__hint">حداکثر ۳۰٬۰۰۰ گرم (۳۰ کیلوگرم).</p>
                                <p class="sg-field__error" id="sg-send-weight-error" data-error-for="weight"></p>
                            </div>
                            <div class="sg-field">
                                <label class="sg-field__label" for="send-value">ارزش مرسوله (تومان) <span class="sg-req">*</span></label>
                                <input class="sg-field__input" id="send-value" name="value_toman" type="number" min="2000" max="1000000000" step="1" placeholder="مثلاً 500000" aria-describedby="sg-send-value-error" required data-sg-validate data-sg-label="ارزش مرسوله" data-sg-min-message="حداقل ارزش مرسوله ۲٬۰۰۰ تومان است." data-sg-unit="تومان" data-sg-integer="1">
                                <p class="sg-field__hint">ارزش کل بسته را جدا وارد کنید؛ از قیمت اقلام محاسبه نمی‌شود.</p>
                                <p class="sg-field__error" id="sg-send-value-error" data-error-for="value"></p>
                            </div>
                        </div>

                        <!-- بسته‌بندی + نوع مرسوله -->
                        <div class="sg-wizard__row2">
                            <div class="sg-field">
                                <label class="sg-field__label" for="sg-send-box">نوع بسته‌بندی <span class="sg-req">*</span></label>
                                <select class="sg-field__select" id="sg-send-box" name="box_type_id" data-send-box aria-describedby="sg-send-box-error"></select>
                                <p class="sg-field__error" id="sg-send-box-error" data-error-for="box"></p>
                            </div>
                            <div class="sg-field">
                                <label class="sg-field__label" for="sg-send-category">نوع مرسوله <span class="sg-req">*</span></label>
                                <select class="sg-field__select" id="sg-send-category" name="category_id" data-send-category aria-describedby="sg-send-category-error"></select>
                                <p class="sg-field__error" id="sg-send-category-error" data-error-for="category"></p>
                            </div>
                        </div>

                        <!-- ابعاد (شرطی: فقط کارتن بزرگتر از سایز ۹) -->
                        <div class="sg-field sg-send__dims" data-send-dims style="display:none;">
                            <span class="sg-field__label">ابعاد بسته — طول × عرض × ارتفاع (سانتی‌متر) <span class="sg-req">*</span></span>
                            <div class="sg-dims-inputs">
                                <?php foreach (['length' => 'طول', 'width' => 'عرض', 'height' => 'ارتفاع'] as $dimension => $dimensionLabel) : ?>
                                    <div>
                                        <label class="sg-dimension-label" for="sg-send-dimension-<?php echo esc_attr($dimension); ?>"><?php echo esc_html($dimensionLabel); ?><small>سانتی‌متر</small></label>
                                        <input class="sg-field__input" id="sg-send-dimension-<?php echo esc_attr($dimension); ?>" name="dimension_<?php echo esc_attr($dimension); ?>" type="number" min="1" max="2147483647" step="1" placeholder="<?php echo esc_attr($dimensionLabel); ?>" aria-describedby="sg-send-dimension-<?php echo esc_attr($dimension); ?>-error" required data-sg-validate data-sg-label="<?php echo esc_attr($dimensionLabel); ?> بسته" data-sg-unit="سانتی‌متر" data-sg-integer="1">
                                        <p class="sg-field__error" id="sg-send-dimension-<?php echo esc_attr($dimension); ?>-error" data-error-for="dimension_<?php echo esc_attr($dimension); ?>"></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- ویژگی‌های بسته -->
                        <div class="sg-field">
                            <span class="sg-field__label">بسته‌بندی غیر استاندارد</span>
                            <div class="sg-check-row">
                                <label class="sg-check"><input type="checkbox" name="is_liquid" value="1"> <span>مایعات</span></label>
                                <label class="sg-check"><input type="checkbox" name="is_fragile" value="1"> <span>شکستنی</span></label>
                                <label class="sg-check"><input type="checkbox" data-send-out-of-size> <span>خارج از اندازه پستی</span></label>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="sg-send__section sg-send__section--items" aria-labelledby="sg-send-items-title">
                    <h4 class="sg-send__section-title" id="sg-send-items-title">اقلام مرسوله <span class="sg-req">*</span></h4>
                    <div class="sg-send__items-help">
                        <p class="sg-send__section-desc"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><span>حداقل یک قلم وارد کنید. وزن و قیمت اقلام، مشخصات کالا هستند و نرخ ارسال را تغییر نمی‌دهند؛ مبنای نرخ، وزن نهایی و ارزش کل بسته است.</span></p>
                        <p class="sg-field__hint" data-send-edit-note style="display:none;"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span>می‌توانید اقلام را ویرایش کنید؛ با ذخیره تغییرات، روش ارسال را دوباره انتخاب کنید.</span></p>
                    </div>
                    <div class="sg-field">
                        <div class="sg-send__items-wrap" role="region" aria-label="اقلام مرسوله" tabindex="0">
                            <table class="sg-items-table">
                                <thead><tr><th scope="col">عنوان</th><th scope="col">تعداد</th><th scope="col">وزن خالص (گرم)</th><th scope="col">قیمت (تومان)</th><th scope="col"><span class="screen-reader-text">حذف قلم</span></th></tr></thead>
                                <tbody data-send-items></tbody>
                            </table>
                        </div>
                        <div class="sg-send__items-actions">
                            <button type="button" class="sg-button sg-button--secondary" data-send-add-item>+ افزودن قلم</button>
                        </div>
                        <p class="sg-field__error" id="sg-send-items-error" data-error-for="items"></p>
                    </div>
                </section>

                <div class="sg-wizard__notice" data-send-notice style="display:none;" role="alert"></div>
                </div>
            </form>

            <?php include __DIR__ . '/../partials/basalam-orders-picker.php'; ?>
            <?php include __DIR__ . '/../partials/order-preview-modal.php'; ?>
            <div class="salam-gate-order-box sg-menu-order-box" data-sg-menu-order-host data-order-id="" hidden></div>

            <!-- دکمه اقدام اصلی (ایجاد مرسوله — پیش از نرخ‌ها) -->
            <div class="sg-wizard__nav" data-send-main-action>
                <button type="button" class="sg-button sg-button--primary sg-button--lg" data-send-submit>
                    مرحله بعد
                </button>
            </div>
        </div>

        <section class="sg-send__stage-panel" id="sg-send-stage-2" data-send-stage-panel="2" style="display:none;" aria-labelledby="sg-send-method-title">
            <h4 class="sg-send__section-title" id="sg-send-method-title" data-send-stage-heading="2" tabindex="-1">انتخاب روش ارسال و پرداخت</h4>
            <!-- نرخ‌ها بعد از ثبت -->
            <div class="sg-send__quotes" data-send-quotes style="display:none;" role="region" aria-label="نرخ‌های ارسال"></div>
            <div class="sg-wizard__notice sg-send__quote-notice" data-send-quote-notice style="display:none;" role="alert"></div>

            <!-- روش پرداخت (فقط بازارهای غیر باسلام — پارت ۷) -->
            <fieldset class="sg-send__pay-method" data-send-pay-method style="display:none;" aria-describedby="sg-send-payment-help">
                <legend class="sg-send__payment-title">روش پرداخت هزینه ارسال <span class="sg-req" aria-hidden="true">*</span></legend>
                <p class="sg-send__section-desc" id="sg-send-payment-help">
                    روش کسر هزینه را انتخاب کنید. پرداخت از موجودی حساب باسلام انجام می‌شود و انتقال به درگاه بانکی ندارد.
                </p>
                <div class="sg-send-payment-options" data-send-pay-methods></div>
                <p class="sg-field__error" data-error-for="pay_method"></p>
            </fieldset>
            <div class="sg-wizard__nav sg-send__stage-actions">
                <button type="button" class="sg-button sg-button--secondary" data-send-back-to-information>مرحله قبل</button>
                <button type="button" class="sg-button sg-button--primary sg-button--lg" data-send-review-next disabled>ثبت سفارش و پرداخت</button>
            </div>
        </section>

        <div class="sg-send__stage-panel" id="sg-send-stage-3" data-send-stage-panel="3" style="display:none;">
            <!-- تأیید نهایی و پرداخت (پارت ۷) -->
            <section class="sg-send__confirm" data-send-confirm style="display:none;" aria-labelledby="sg-send-confirm-title">
                <h4 class="sg-send__section-title" id="sg-send-confirm-title" data-send-stage-heading="3" tabindex="-1">بررسی نهایی و پرداخت</h4>
                <p class="sg-send__payment-help">اطلاعات زیر را بررسی کنید. با تأیید، هزینه ارسال از موجودی روش انتخاب‌شده کسر می‌شود.</p>
                <dl class="sg-wizard__review" data-send-review></dl>
                <div class="sg-send__inline-status" data-send-pay-status style="display:none;" role="status" aria-live="polite" aria-atomic="true"></div>
                <div class="sg-wizard__notice" data-send-confirm-notice style="display:none;" role="alert"></div>
                <p data-send-finance-recovery style="display:none;">
                    <button type="button" class="sg-button sg-button--secondary" data-send-recheck-payment>بررسی دوباره وضعیت پرداخت</button>
                    <a class="sg-button sg-button--secondary" href="<?php echo esc_url($parcelsUrl); ?>">بررسی وضعیت در فهرست مرسوله‌ها</a>
                </p>
                <div class="sg-wizard__nav sg-send__payment-actions">
                    <button type="button" class="sg-button sg-button--secondary" data-send-back-to-quotes>بازگشت به نرخ‌ها</button>
                    <button type="button" class="sg-button sg-button--primary sg-button--lg" data-send-pay>
                        تأیید و پرداخت هزینه ارسال
                    </button>
                </div>
            </section>

            <!-- نتیجه پرداخت (پارت ۷) -->
            <div class="sg-send__result" data-send-result style="display:none;" role="status" aria-live="polite"></div>
        </div>
    </div>
    <div class="sg-modal-overlay sg-remove-overlay" data-send-remove-modal style="display:none;">
        <section class="sg-modal sg-remove-dialog sg-remove-dialog--compact" role="dialog" aria-modal="true" aria-labelledby="sg-remove-title" aria-describedby="sg-remove-description">
            <div class="sg-modal__header"><h3 id="sg-remove-title">حذف قلم مرسوله</h3><button type="button" class="sg-modal__close" data-close-modal aria-label="بستن">×</button></div>
            <div class="sg-modal__body">
                <span class="sg-remove-dialog__icon dashicons dashicons-trash" aria-hidden="true"></span>
                <p id="sg-remove-description" data-send-remove-message></p>
                <p class="sg-field__hint" data-send-remove-hint>این قلم از اطلاعات مرسوله حذف می‌شود.</p>
            </div>
            <div class="sg-modal__footer"><button type="button" class="sg-button sg-button--secondary" data-close-modal autofocus>انصراف</button><button type="button" class="sg-button sg-button--primary" data-send-remove-confirm>حذف قلم</button></div>
        </section>
    </div>
</div>
<?php
// Reuse the very same reviewed-weight/quote/payment UI as Woo order screens.
// Loading its reference data is read-only. Pure template test renderers omit the service.
if (class_exists(\SyncBasalam\SalamGate\Services\ReferenceDataService::class)) {
    include_once __DIR__ . '/../parcel-modal.php';
}
