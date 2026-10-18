<?php

if (! defined('ABSPATH')) exit;

/**
 * view=calculator — ماشین‌حساب هزینه ارسال (پارت ۵).
 * POST /v1/base/calculator — بدون ساخت parcel و بدون hash قابل پرداخت.
 * نتیجه برآوردی است؛ تضمین هزینه نهایی نیست.
 *
 * @var array $data
 */

$backUrl = admin_url('admin.php?page=salam_gate');
?>
<div class="sg-view sg-view--calculator" data-view="calculator">
    <section class="sg-card sg-calculator__main" aria-labelledby="sg-calculator-title">
        <h3 class="sg-card__title" id="sg-calculator-title">ماشین‌حساب هزینه ارسال</h3>
        <p class="sg-card__text">برآورد هزینه ارسال بین دو شهر — بدون نیاز به ثبت مرسوله. نتیجه برآوردی است و هزینه نهایی هنگام ثبت محاسبه می‌شود.</p>

        <div class="sg-wizard__row2">
            <div class="sg-field">
                <label class="sg-field__label" for="calc-sender-province">استان فرستنده <span class="sg-req">*</span></label>
                <select class="sg-field__select" id="calc-sender-province" data-calc-sender-province required disabled><option value="">در حال دریافت استان‌ها…</option></select>
            </div>
            <div class="sg-field">
                <label class="sg-field__label" for="calc-sender-city">شهر فرستنده <span class="sg-req">*</span></label>
                <select class="sg-field__select" id="calc-sender-city" data-calc-sender-city required disabled><option value="">ابتدا استان فرستنده را انتخاب کنید</option></select>
            </div>
        </div>

        <div class="sg-wizard__row2">
            <div class="sg-field">
                <label class="sg-field__label" for="calc-receiver-province">استان گیرنده <span class="sg-req">*</span></label>
                <select class="sg-field__select" id="calc-receiver-province" data-calc-receiver-province required disabled><option value="">در حال دریافت استان‌ها…</option></select>
            </div>
            <div class="sg-field">
                <label class="sg-field__label" for="calc-receiver-city">شهر گیرنده <span class="sg-req">*</span></label>
                <select class="sg-field__select" id="calc-receiver-city" data-calc-receiver-city required disabled><option value="">ابتدا استان گیرنده را انتخاب کنید</option></select>
            </div>
        </div>

        <div class="sg-wizard__row2">
            <div class="sg-field">
                <label class="sg-field__label" for="calc-weight">وزن نهایی مرسوله (گرم) <span class="sg-req">*</span></label>
                <input class="sg-field__input" id="calc-weight" type="number" min="50" max="30000" step="1" placeholder="مثلاً 850" inputmode="numeric" required data-sg-validate data-sg-label="وزن مرسوله" data-sg-min-message="حداقل وزن مجاز مرسوله ۵۰ گرم است." data-sg-unit="گرم" data-sg-integer="1">
            </div>
            <div class="sg-field">
                <label class="sg-field__label" for="calc-value">ارزش مرسوله (تومان) <span class="sg-req">*</span></label>
                <input class="sg-field__input" id="calc-value" type="number" min="2000" max="214748364" step="1" placeholder="مثلاً 500000" inputmode="numeric" required data-sg-validate data-sg-label="ارزش مرسوله" data-sg-min-message="حداقل ارزش مرسوله ۲٬۰۰۰ تومان است." data-sg-unit="تومان" data-sg-integer="1">
            </div>
        </div>

        <div class="sg-wizard__row2">
            <div class="sg-field">
                <label class="sg-field__label" for="calc-box">نوع بسته‌بندی <span class="sg-req">*</span></label>
                <select class="sg-field__select" id="calc-box" data-calc-box disabled aria-describedby="calc-box-state"><option value="">در حال دریافت بسته‌بندی‌ها…</option></select>
                <p class="sg-field__state" id="calc-box-state" data-calc-box-state role="status" aria-live="polite"></p>
            </div>
            <div class="sg-field">
                <label class="sg-field__label" for="calc-service">روش ارسال</label>
                <select class="sg-field__select" id="calc-service" data-calc-service>
                    <option value="">پیش‌فرض (پیشتاز)</option>
                    <option value="1">پیشتاز</option>
                    <option value="3">ویژه</option>
                    <option value="5">اکسپرس</option>
                </select>
            </div>
        </div>

        <div class="sg-calculator__actions">
            <button type="button" class="sg-button sg-button--primary" data-calc-submit disabled>
                <span data-calc-submit-label>محاسبه هزینه ارسال</span>
                <span class="spinner" data-calc-spinner style="display:none;"></span>
            </button>
            <button type="button" class="sg-button sg-button--secondary" data-calc-retry hidden>دریافت دوباره اطلاعات</button>
        </div>

        <div class="sg-calc-result" data-calc-result style="display:none;" role="status" aria-live="polite"></div>
    </section>
</div>
