<?php
defined('ABSPATH') || exit;
$previewReadOnly = $previewReadOnly ?? false;
?>
<div class="sg-modal-overlay sg-order-preview-overlay" id="sg-order-preview-modal" style="display:none;" aria-hidden="true">
    <div class="sg-modal sg-order-preview<?php echo $previewReadOnly ? ' sg-order-preview--readonly' : ''; ?>" role="dialog" aria-modal="true" aria-labelledby="sg-order-preview-title" tabindex="-1">
        <div class="sg-modal__header">
            <span class="sg-order-preview__icon dashicons dashicons-archive" aria-hidden="true"></span>
            <h4 id="sg-order-preview-title" tabindex="-1" autofocus>جزئیات سفارش</h4>
            <button type="button" class="sg-modal__close" data-close-modal data-sg-preview-close aria-label="بستن جزئیات سفارش">×</button>
        </div>
        <div class="sg-modal__body" aria-busy="false" data-sg-preview-body>
            <div class="sg-order-preview__loading" data-sg-preview-loading role="status" hidden><span class="sg-order-preview__spinner" aria-hidden="true"></span>در حال دریافت اطلاعات…</div>
            <div data-sg-preview-content></div>
            <div class="sg-order-preview__error" data-sg-preview-error hidden><p data-sg-preview-message></p><button type="button" class="sg-button sg-button--secondary" data-sg-preview-retry>تلاش دوباره</button></div>
        </div>
        <div class="sg-modal__footer">
            <p class="sg-order-preview__reason" data-sg-preview-reason hidden></p>
            <button type="button" class="sg-button sg-button--secondary" data-sg-preview-close>بستن</button>
            <?php if (!$previewReadOnly) : ?>
            <button type="button" class="sg-button sg-button--primary" data-sg-preview-ship hidden><span class="dashicons dashicons-car" aria-hidden="true"></span>ارسال با سلام‌گیت</button>
            <?php endif; ?>
        </div>
    </div>
</div>
