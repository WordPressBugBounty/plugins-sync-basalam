<?php

if (! defined('ABSPATH')) exit;

/** @var array $data */
?>
<div class="sg-state sg-state--loading" data-state="loading" role="status" aria-live="polite">
    <div class="sg-load-indicator">
        <span class="sg-loading-spinner" aria-hidden="true"></span>
        <strong class="sg-loading__text">در حال دریافت اطلاعات…</strong>
        <p data-sg-account-load-message hidden></p>
        <button type="button" class="sg-button sg-button--secondary" data-sg-account-retry hidden>تلاش دوباره</button>
        <noscript><p>برای بررسی وضعیت حساب، جاوااسکریپت مرورگر را فعال کنید و صفحه را دوباره باز کنید.</p></noscript>
    </div>
</div>
