<?php

if (! defined('ABSPATH')) exit;

use SyncBasalam\SalamGate\Parcel\ParcelPayloadBuilder;
use SyncBasalam\SalamGate\Services\ReferenceDataService;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;

$referenceData = new ReferenceDataService();

try {
    $boxTypes = $referenceData->getBoxTypes();
    $provinceTree = $referenceData->getProvinceTree();
} catch (\Throwable $exception) {
    $boxTypes = [];
    $provinceTree = [];
}

?>
<div class="salam-gate-modal-overlay salam-gate-page" id="salam-gate-parcel-modal" style="display:none;">
    <div class="salam-gate-modal" role="dialog" aria-modal="true" aria-labelledby="salam-gate-parcel-title">
        <div class="salam-gate-modal__header">
            <div class="sg-order-modal-heading">
                <span class="sg-order-modal-icon dashicons dashicons-archive" aria-hidden="true"></span>
                <div>
                    <h3 class="basalam-h basalam-modal-title" id="salam-gate-parcel-title">ارسال با سلام‌گیت</h3>
                </div>
            </div>
            <button type="button" class="basalam-modal-close-abs salam-gate-modal__close" aria-label="بستن">&times;</button>
        </div>

        <div class="salam-gate-modal__body">
            <div class="sg-order-loading" data-sg-order-loading role="status" aria-live="polite" hidden>
                <span class="sg-order-loading__spinner" aria-hidden="true"></span>
                <p data-sg-order-loading-message>در حال دریافت اطلاعات…</p>
                <button type="button" class="basalam-secondary-button" data-sg-order-loading-retry hidden>تلاش دوباره</button>
            </div>
            <div data-sg-order-content>
            <ol class="sg-order-steps" aria-label="مراحل ارسال مرسوله">
                <?php foreach ([1 => 'اطلاعات', 2 => 'انتخاب روش ارسال', 3 => 'تأیید و پرداخت'] as $step => $title) : ?>
                    <li data-sg-order-step="<?php echo esc_attr($step); ?>"><button type="button" data-sg-order-go-step="<?php echo esc_attr($step); ?>" <?php echo $step > 1 ? 'disabled' : ''; ?>><span aria-hidden="true"><?php echo esc_html($step); ?></span><?php echo esc_html($title); ?></button></li>
                <?php endforeach; ?>
            </ol>
            <div class="salam-gate-notice salam-gate-notice--error salam-gate-modal__banner" role="alert" style="display:none;"></div>
            <div class="salam-gate-notice salam-gate-notice--success salam-gate-modal__warn" style="display:none;"></div>

            <!-- مرحله ۱: مشخصات -->
            <div class="salam-gate-modal__stage" data-stage="form">
                <p class="sg-order-edit-warning" data-sg-order-edit-warning role="status" hidden>اطلاعات تغییر کرده است؛ برای ادامه روش ارسال و پرداخت را دوباره انتخاب کنید.</p>
                <!-- اطلاعات اصلی مرسوله -->
                <fieldset class="salam-gate-fieldset sg-order-parcel-fields">
                    <legend class="sg-order-section-title"><span class="dashicons dashicons-archive" aria-hidden="true"></span>مشخصات بسته</legend>
                    <input id="sg-order-contents" type="hidden" class="sg-f-contents" form="">
                    <div class="basalam-form-row sg-order-primary-fields">
                        <div class="basalam-form-group basalam-p">
                            <label class="basalam-label" for="sg-order-weight">وزن نهایی مرسوله با بسته‌بندی (گرم)</label>
                            <input id="sg-order-weight" type="number" class="basalam-input sg-f-weight" min="50" max="30000" step="1" required form="" aria-describedby="sg-order-weight-limit" data-sg-validate data-sg-label="وزن مرسوله" data-sg-min-message="حداقل وزن مجاز مرسوله ۵۰ گرم است." data-sg-unit="گرم" data-sg-integer="1">
                            <p id="sg-order-weight-limit" class="sg-order-helper sg-order-weight-hint">حداکثر وزن مجاز: ۳۰٬۰۰۰ گرم (۳۰ کیلوگرم).</p>
                            <p class="sg-order-helper sg-order-weight-hint" data-sg-order-weight-message></p>
                        </div>
                        <div class="basalam-form-group basalam-p">
                            <label class="basalam-label" for="sg-order-box">نوع بسته‌بندی</label>
                            <select id="sg-order-box" class="basalam-select sg-f-box">
                                <?php foreach ($boxTypes as $boxType) : ?>
                                    <option value="<?php echo esc_attr((string) ($boxType['id'] ?? '')); ?>"><?php echo esc_html((string) ($boxType['title'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="basalam-form-group basalam-p"><label class="basalam-label" for="sg-order-category">نوع مرسوله</label><select id="sg-order-category" class="basalam-select sg-f-category"></select></div>
                    </div>
                    <div class="sg-order-weight-confirmation" data-sg-order-weight-confirmation>
                        <label for="sg-order-weight-confirm-form"><input id="sg-order-weight-confirm-form" type="checkbox" form="" data-sg-order-confirm-weight aria-describedby="sg-order-weight-confirm-form-error"><span data-sg-order-weight-confirm-label>وزن بسته با بسته‌بندی را بررسی کرده‌ام و تأیید می‌کنم.</span></label>
                        <p id="sg-order-weight-confirm-form-error" class="sg-order-weight-error" data-sg-order-weight-error role="alert" hidden></p>
                    </div>
                </fieldset>

                <div class="sg-order-information-panels">
                    <!-- بخش گیرنده -->
                    <fieldset class="salam-gate-fieldset sg-order-receiver">
                        <legend class="sg-order-section-title sg-order-receiver__header"><span><span class="dashicons dashicons-download" aria-hidden="true"></span>گیرنده</span><button type="button" class="basalam-secondary-button sg-order-receiver-edit" aria-controls="sg-order-receiver-fields" aria-expanded="false">ویرایش گیرنده</button></legend>
                        <dl class="sg-order-receiver-summary" aria-live="polite" aria-label="مشخصات گیرنده">
                            <?php foreach (['name' => 'نام گیرنده', 'destination' => 'شهر و استان', 'mobile' => 'موبایل', 'postal' => 'کد پستی', 'address' => 'نشانی'] as $field => $label) : ?><div<?php echo $field === 'address' ? ' class="sg-order-receiver-summary__wide"' : ''; ?>><dt><?php echo esc_html($label); ?></dt><dd data-sg-order-summary="<?php echo esc_attr($field); ?>">—</dd></div><?php endforeach; ?>
                        </dl>
                        <div class="sg-order-receiver-fields" id="sg-order-receiver-fields" style="display:none;">

                        <div class="basalam-form-row basalam-form-row-two-col">
                            <div class="basalam-form-group basalam-p">
                                <label class="basalam-label" for="sg-order-name">نام و نام خانوادگی گیرنده</label>
                                <input id="sg-order-name" type="text" class="basalam-input sg-f-name" placeholder="مثلاً علی محمدی" autocomplete="shipping name" required minlength="3" form="" data-sg-validate data-sg-label="نام گیرنده">
                            </div>
                            <div class="basalam-form-group basalam-p">
                                <label class="basalam-label" for="sg-order-mobile">شماره موبایل گیرنده</label>
                                <input id="sg-order-mobile" type="tel" class="basalam-input sg-f-mobile" placeholder="09xxxxxxxxx" dir="ltr" autocomplete="shipping tel" required data-sg-length="11" form="" data-sg-validate data-sg-label="شماره موبایل">
                            </div>
                        </div>

                        <div class="basalam-form-row basalam-form-row-two-col">
                            <div class="basalam-form-group basalam-p">
                                <label class="basalam-label" for="sg-order-province">استان</label>
                                <select id="sg-order-province" class="basalam-select sg-f-province"></select>
                            </div>
                            <div class="basalam-form-group basalam-p">
                                <label class="basalam-label" for="sg-order-city">شهر</label>
                                <select id="sg-order-city" class="basalam-select sg-f-city"></select>
                            </div>
                        </div>

                        <div class="basalam-form-row sg-order-address-row">
                            <div class="basalam-form-group basalam-p">
                                <label class="basalam-label" for="sg-order-address">آدرس پستی</label>
                                <textarea id="sg-order-address" class="basalam-input sg-f-address" rows="2" placeholder="خیابان، کوچه، پلاک، واحد" autocomplete="shipping street-address" required minlength="10" form="" data-sg-validate data-sg-label="آدرس پستی"></textarea>
                            </div>
                            <div class="basalam-form-group basalam-p">
                                <label class="basalam-label" for="sg-order-postal">کد پستی</label>
                                <input id="sg-order-postal" type="text" class="basalam-input sg-f-postal" placeholder="1234567890" dir="ltr" inputmode="numeric" autocomplete="shipping postal-code" required data-sg-length="10" form="" data-sg-validate data-sg-label="کد پستی">
                            </div>
                        </div>
                        </div>
                    </fieldset>

                    <fieldset class="salam-gate-fieldset sg-order-sender"><legend class="sg-order-section-title"><span class="dashicons dashicons-upload" aria-hidden="true"></span>فرستنده</legend><dl class="sg-order-sender-details" data-sg-order-sender aria-label="مشخصات فرستنده"></dl></fieldset>

                    <fieldset class="salam-gate-fieldset sg-order-extra-fields">
                        <legend class="sg-order-section-title"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span>جزئیات مرسوله</legend>
                        <div class="sg-order-extra-content">
                        <div class="basalam-form-row">
                            <div class="basalam-form-group basalam-p">
                                <label class="basalam-label sg-f-value-label" for="sg-order-value">ارزش مرسوله (تومان)</label>
                                <input id="sg-order-value" type="number" class="basalam-input sg-f-value" min="2000" max="1000000000" step="1" required form="" data-sg-validate data-sg-label="ارزش مرسوله" data-sg-min-message="حداقل ارزش مرسوله ۲٬۰۰۰ تومان است." data-sg-unit="تومان" data-sg-integer="1">
                                <p class="sg-order-helper sg-order-value-hint" data-sg-order-value-hint hidden>ارزش اعلامی مرسوله برای بیمه و هزینه ارسال است؛ مقدار پیش‌فرض با تخفیف کالای غرفه محاسبه می‌شود و قابل ویرایش است. قیمت کالاهای سفارش تغییر نمی‌کند.</p>
                            </div>
                        </div>
                        <div class="basalam-form-row">
                            <div class="basalam-form-group basalam-p sg-liquid-fragile-group">
                                <span class="basalam-label sg-packaging-title">بسته‌بندی غیر استاندارد</span>
                                <div class="sg-packaging-options">
                                    <label class="sg-check-label"><input type="checkbox" class="sg-f-liquid"> <span>مایعات</span></label>
                                    <label class="sg-check-label"><input type="checkbox" class="sg-f-fragile"> <span>شکستنی</span></label>
                                    <label class="sg-check-label sg-out-of-size"><input type="checkbox" data-sg-order-out-of-size form=""> <span>خارج از اندازه پستی</span></label>
                                </div>
                            </div>
                        </div>

                        <!-- ابعاد (فقط برای کارتن بزرگ) -->
                        <div class="basalam-form-row sg-dims-row" style="display:none;">
                            <div class="basalam-form-group basalam-form-group-full basalam-p">
                                <span class="basalam-label">ابعاد بسته (سانتی‌متر)</span>
                                <div class="sg-dims-inputs">
                                    <?php foreach (['length' => 'طول', 'width' => 'عرض', 'height' => 'ارتفاع'] as $dimension => $dimensionLabel) : ?>
                                    <div><label class="sg-dimension-label" for="sg-order-dimension-<?php echo esc_attr($dimension); ?>"><?php echo esc_html($dimensionLabel); ?><small>سانتی‌متر</small></label><input id="sg-order-dimension-<?php echo esc_attr($dimension); ?>" type="number" class="basalam-input sg-f-dim-<?php echo esc_attr($dimension); ?>" min="1" max="2147483647" step="1" placeholder="<?php echo esc_attr($dimensionLabel); ?>" required form="" data-sg-validate data-sg-label="<?php echo esc_attr($dimensionLabel); ?> بسته" data-sg-unit="سانتی‌متر" data-sg-integer="1"></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div></fieldset>
                </div>

                <!-- اقلام -->
                <fieldset class="salam-gate-fieldset sg-order-items-panel" data-sg-send-form>
                    <legend class="sg-order-section-title"><span class="dashicons dashicons-list-view" aria-hidden="true"></span>اقلام مرسوله</legend>
                    <p class="sg-order-helper">وزن و قیمت اقلام، مشخصات کالا هستند؛ نرخ ارسال از وزن نهایی و ارزش کل مرسوله محاسبه می‌شود.</p>
                    <div class="basalam-form-row">
                        <div class="basalam-form-group basalam-form-group-full basalam-p">
                            <table class="sg-items-table">
                                <thead><tr><th scope="col">عنوان</th><th scope="col">تعداد</th><th scope="col">وزن خالص (گرم)</th><th scope="col">قیمت (تومان)</th><th scope="col"><span class="screen-reader-text">عملیات قلم</span></th></tr></thead>
                                <tbody class="sg-f-items"></tbody>
                            </table>
                            <div class="sg-order-items-actions"><button type="button" class="basalam-secondary-button" data-sg-order-add-item>+ افزودن قلم</button></div>
                        </div>
                    </div>
                </fieldset>

                <div class="sg-order-form-actions">
                    <p class="sg-order-helper">ابتدا وزن مرسوله را تایید کنید</p>
                    <button type="button" class="basalam-primary-button basalam-p basalam-btn-fill salam-gate-get-rates">
                        <span>مرحله بعد</span>
                        <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <!-- مرحله ۲: نرخ‌ها -->
            <div class="salam-gate-modal__stage" data-stage="quotes" style="display:none;">
                <div class="sg-order-quotes-layout">
                <section class="salam-gate-fieldset sg-order-shipping-panel" aria-label="شیوه ارسال">
                    <h4 class="sg-order-section-title"><span class="dashicons dashicons-car" aria-hidden="true"></span>شیوه ارسال</h4>
                    <p class="sg-order-helper">قیمت‌ها کرایه پستی بعد از تخفیف هستند؛ مبلغ نهایی با سایر هزینه‌ها در مرحله تأیید نمایش داده می‌شود.</p>
                    <p class="sg-order-shipping-preference" data-sg-order-shipping-preference hidden><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><span>روش ارسال انتخاب شده توسط کاربر: <strong data-sg-order-customer-shipping-method></strong></span></p>
                    <div class="salam-gate-quotes sg-quotes-cards"></div>
                </section>
                <section class="sg-order-cost-breakdown" data-sg-order-cost-breakdown hidden aria-label="جزئیات هزینه روش ارسال انتخاب‌شده" aria-live="polite"></section>
                </div>

                <fieldset class="salam-gate-fieldset sg-payment-method-fieldset" data-sg-preload-balances style="display:none;">
                    <legend class="sg-order-section-title"><span class="dashicons dashicons-money-alt" aria-hidden="true"></span>شیوه پرداخت</legend>
                    <div class="basalam-form-row">
                        <div class="basalam-form-group basalam-form-group-full basalam-p">
                            <?php
                            $sgPaymentSelectClass = 'basalam-select sg-pay-method';
                            $sgPaymentSelectId = 'sg-order-modal-pay-method';
                            $sgPaymentRadioName = 'sg_order_modal_payment_method';
                            $sgPaymentDefault = SalamGateSettings::instance()->get(SalamGateSettings::KEY_PAY_METHOD_DEFAULT);
                            $sgPaymentDisabled = false;
                            include __DIR__ . '/partials/payment-methods.php';
                            ?>
                        </div>
                    </div>
                </fieldset>

                <section class="sg-order-funding" data-sg-order-basalam-funding hidden>
                    <span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
                    <div><strong>پرداخت از مبلغ همین سفارش باسلام</strong><p>هزینه ارسال از مبلغی که مشتری هنگام خرید این سفارش پرداخت کرده کسر می‌شود؛ نیازی به پرداخت در درگاه بانکی نیست و کیف پول یا تراز عمومی غرفه استفاده نمی‌شود.</p></div>
                </section>

                <div class="sg-order-modal-payment-feedback" data-sg-order-modal-payment-feedback role="alert" aria-atomic="true" style="display:none;"></div>

                <div class="sg-order-checkout-footer">
                    <div class="sg-order-checkout-total" aria-live="polite" aria-atomic="true">
                        <strong data-sg-order-selected-cost>—</strong>
                    </div>
                    <div class="basalam-center-block sg-quote-actions">
                        <button type="button" class="basalam-secondary-button basalam-p salam-gate-back-form">مرحله قبل</button>
                        <button type="button" class="basalam-primary-button basalam-p" data-sg-order-review disabled>ثبت سفارش و پرداخت</button>
                    </div>
                </div>
            </div>
            <section class="salam-gate-modal__stage sg-order-confirm" data-stage="confirm" style="display:none;" aria-labelledby="sg-order-confirm-title">
                <h4 class="sg-order-section-title" id="sg-order-confirm-title" tabindex="-1"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>بررسی نهایی و پرداخت</h4>
                <p class="sg-order-helper" data-sg-order-confirm-payment-hint>مشخصات و مبلغ را بررسی کنید. با تأیید نهایی، هزینه ارسال از روش انتخاب‌شده کسر می‌شود.</p>
                <div class="sg-order-confirm-layout">
                <div class="sg-order-review" data-sg-order-review-summary></div>
                <section class="sg-order-cost-breakdown" data-sg-order-cost-breakdown hidden aria-label="جزئیات هزینه تأییدشده ارسال"></section>
                </div>
                <div class="sg-order-weight-confirmation" data-sg-order-weight-confirmation>
                    <label for="sg-order-weight-confirm-review"><input id="sg-order-weight-confirm-review" type="checkbox" form="" data-sg-order-confirm-weight aria-describedby="sg-order-weight-confirm-review-error"><span data-sg-order-weight-confirm-label>وزن بسته با بسته‌بندی را بررسی کرده‌ام و تأیید می‌کنم.</span></label>
                    <p id="sg-order-weight-confirm-review-error" class="sg-order-weight-error" data-sg-order-weight-error role="alert" hidden></p>
                </div>
                <div class="sg-order-form-actions"><button type="button" class="basalam-secondary-button" data-sg-order-back-quotes>مرحله قبل</button><button type="button" class="basalam-primary-button salam-gate-select-quote" disabled>تأیید و پرداخت هزینه ارسال</button></div>
            </section>
            <div class="sg-order-remove-overlay" data-sg-order-remove-modal style="display:none;">
                <section class="sg-order-remove-dialog" role="dialog" aria-modal="true" aria-labelledby="sg-order-remove-title"><h4 id="sg-order-remove-title">حذف قلم مرسوله</h4><p data-sg-order-remove-message></p><p class="sg-order-helper" data-sg-order-remove-hint></p><div><button type="button" class="basalam-secondary-button" data-sg-order-remove-cancel autofocus>انصراف</button><button type="button" class="basalam-primary-button" data-sg-order-remove-confirm>حذف قلم</button></div></section>
            </div>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="salam-gate-locations-tree"><?php echo wp_json_encode($provinceTree); ?></script>
