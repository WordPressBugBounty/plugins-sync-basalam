<?php

if (! defined('ABSPATH')) exit;

/**
 * view=post-offices — مراکز تحویل (پارت ۵).
 * انتخاب شهر عمومی → مناطق پستی provider → مراکز با اطلاعات واقعی.
 * فقط برای مشاهده است — بازکردن آن default فرستنده را عوض نمی‌کند.
 *
 * @var array $data
 */

?>
<div class="sg-view sg-view--post-offices" data-view="post-offices">
    <section class="sg-card sg-po-section" aria-labelledby="sg-po-title">
        <div class="sg-po-heading">
            <span class="sg-po-heading__icon dashicons dashicons-location-alt" aria-hidden="true"></span>
            <div>
                <h3 class="sg-card__title" id="sg-po-title">پیداکردن مرکز تحویل</h3>
                <p class="sg-card__text">نزدیک‌ترین مرکز را پیدا کنید و پس از پرداخت هزینه ارسال، بسته را به آن تحویل دهید.</p>
            </div>
        </div>

        <div class="sg-po-filters">
            <div class="sg-field">
                <label class="sg-field__label" for="sg-po-province">استان <span class="sg-req">*</span></label>
                <select class="sg-field__select" id="sg-po-province" data-po-province aria-describedby="sg-po-reference-state" disabled>
                    <option value="">در حال دریافت استان‌ها…</option>
                </select>
                <p class="sg-field__state" id="sg-po-reference-state" data-po-reference-state role="status" aria-live="polite"></p>
                <button type="button" class="sg-button sg-button--secondary" data-po-retry hidden>تلاش دوباره</button>
            </div>
            <div class="sg-field">
                <label class="sg-field__label" for="sg-po-location">منطقه پستی <span class="sg-req">*</span></label>
                <select class="sg-field__select" id="sg-po-location" data-po-location aria-describedby="sg-po-location-state" disabled>
                    <option value="">ابتدا استان را انتخاب کنید</option>
                </select>
                <p class="sg-field__state" id="sg-po-location-state" data-po-location-state role="status" aria-live="polite"></p>
            </div>
        </div>
        <div data-po-result-section hidden>
        <div class="sg-po-result-heading">
            <h4 class="sg-section__title" id="sg-po-results-title">مراکز منطقه انتخاب‌شده</h4>
            <span class="sg-po-result-count" data-po-result-count role="status"></span>
        </div>
        <div class="sg-po-results" data-po-results aria-labelledby="sg-po-results-title" aria-live="polite">
        </div>
        </div>
    </section>
</div>
