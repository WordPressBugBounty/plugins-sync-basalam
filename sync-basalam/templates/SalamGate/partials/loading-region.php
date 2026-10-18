<?php

if (! defined('ABSPATH')) exit;

/** A shared read-only loader; content stays hidden until all required reads settle. */
?>
<div class="sg-load-indicator" data-sg-load-indicator role="status" aria-live="polite" aria-atomic="true">
    <span class="sg-loading-spinner" data-sg-load-spinner aria-hidden="true"></span>
    <strong data-sg-load-title>در حال دریافت اطلاعات…</strong>
    <p data-sg-load-message hidden></p>
    <button type="button" class="sg-button sg-button--secondary" data-sg-load-retry hidden>تلاش دوباره</button>
    <noscript><p>برای نمایش این بخش جاوااسکریپت مرورگر را فعال کنید و صفحه را دوباره باز کنید.</p></noscript>
</div>
