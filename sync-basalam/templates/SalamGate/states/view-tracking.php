<?php

defined('ABSPATH') || exit;
?>
<div class="sg-view sg-view--tracking" data-view="tracking" data-sg-tracking>
    <section class="sg-card sg-tracking__search" aria-labelledby="sg-tracking-title">
        <div class="sg-tracking__intro">
            <span class="sg-tracking__icon" aria-hidden="true"><span class="dashicons dashicons-search"></span></span>
            <div>
                <h2 class="sg-card__title" id="sg-tracking-title">مرسوله‌تان کجاست؟</h2>
            </div>
        </div>
        <form class="sg-tracking__form" data-sg-tracking-form action="https://tracking.post.ir/" method="get">
            <div class="sg-field">
                <label class="sg-field__label" for="sg-tracking-code">کد رهگیری مرسوله</label>
                <input class="sg-field__input" id="sg-tracking-code" name="id" data-sg-tracking-code type="text" dir="ltr" inputmode="numeric" maxlength="64" required autocomplete="off" spellcheck="false" aria-describedby="sg-tracking-help sg-tracking-message" placeholder="کد رهگیری ۲۴ رقمی">
                <p class="sg-field__hint" id="sg-tracking-help">کد درج‌شده روی برچسب پستی را وارد کنید.</p>
            </div>
            <button class="sg-button sg-button--primary" type="submit" data-sg-tracking-submit>
                <span class="dashicons dashicons-search" data-sg-tracking-submit-icon aria-hidden="true"></span>
                <span data-sg-tracking-submit-label>پیگیری در سایت پست</span>
            </button>
        </form>
        <p id="sg-tracking-message" class="sg-field__hint" data-sg-tracking-live role="status" aria-live="polite" aria-atomic="true"></p>
    </section>
    <div class="sg-tracking__result" data-sg-tracking-result aria-busy="false">
        <section class="sg-card sg-tracking__empty" data-sg-tracking-empty>
            <span class="sg-tracking__empty-icon dashicons dashicons-location-alt" aria-hidden="true"></span>
            <h3>سامانه رسمی رهگیری پست</h3>
            <p>کد روی برچسب پستی را وارد کنید؛ نتیجه پیگیری در سایت پست نمایش داده می‌شود، نه داخل ووسلام.</p>
        </section>
    </div>
</div>
