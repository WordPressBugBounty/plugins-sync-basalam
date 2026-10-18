<?php

defined('ABSPATH') || exit;
?>
<div class="salam-gate-page sg-order-list-shell">
<div class="sg-modal-overlay" id="sg-order-list-native-modal" style="display:none;">
    <div class="sg-modal sg-order-list-native" role="dialog" aria-modal="true" aria-labelledby="sg-order-list-native-title">
        <div class="sg-modal__header">
            <h3 id="sg-order-list-native-title">مرسوله‌های سفارش باسلام</h3>
            <button type="button" class="sg-modal__close" data-close-modal data-sg-list-native-close aria-label="بستن">&times;</button>
        </div>
        <div class="sg-modal__body">
            <div class="sg-list-native-loading" data-sg-list-native-loading role="status" aria-live="polite" hidden>
                <span class="sg-list-native-loading__spinner" aria-hidden="true"></span>
                <p>در حال دریافت اطلاعات…</p>
            </div>
            <div data-sg-list-native-content>
            <div class="salam-gate-order-box" data-sg-order-list-host data-order-id="" data-basalam-order="0"></div>
            </div>
        </div>
    </div>
</div>
<?php $previewReadOnly = true; include __DIR__ . '/partials/order-preview-modal.php'; unset($previewReadOnly); ?>
</div>
<?php include_once __DIR__ . '/parcel-modal.php'; ?>
