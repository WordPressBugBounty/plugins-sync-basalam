<?php

use SyncBasalam\Services\VendorSyncPolicy;

defined('ABSPATH') || exit;

$mode = $vendorSyncState['mode'] ?? VendorSyncPolicy::MODE_ACTIVE;
$inactiveDays = (int) ($vendorSyncState['inactive_days'] ?? 0);
$statusName = trim((string) ($vendorSyncState['status_name'] ?? ''));
$noticeClass = $mode === VendorSyncPolicy::MODE_INACTIVE_SUSPENDED ? 'notice-error' : 'notice-warning';
?>
<div class="notice <?php echo esc_attr($noticeClass); ?> sync-basalam-vendor-status-notice">
    <?php if ($mode === VendorSyncPolicy::MODE_INACTIVE_LIMITED): ?>
        <p>
            <strong>غرفه باسلام شما غیرفعال است.</strong>
            ایجاد محصول جدید متوقف شده و تا پایان هفته سوم فقط قیمت و موجودی محصولات بروزرسانی می‌شود.
            <?php if ($inactiveDays > 0): ?>
                مدت غیرفعالی ثبت‌شده: <?php echo esc_html($inactiveDays); ?> روز.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <p>
            <strong>همگام‌سازی محصولات متوقف شده است.</strong>
            غرفه بیش از ۳ هفته غیرفعال بوده و تا زمان فعال‌شدن دوباره، هیچ محصولی ایجاد یا بروزرسانی نمی‌شود.
        </p>
    <?php endif; ?>

    <?php if ($statusName !== ''): ?>
        <p>وضعیت فعلی غرفه در باسلام: <strong><?php echo esc_html($statusName); ?></strong></p>
    <?php endif; ?>
</div>
