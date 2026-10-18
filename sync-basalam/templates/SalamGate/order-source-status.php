<?php

defined('ABSPATH') || exit;

use SyncBasalam\SalamGate\Order\BasalamOrderState;

require_once dirname(__DIR__, 2) . '/includes/SalamGate/Order/BasalamOrderState.php';
$sourceState = BasalamOrderState::cached($order);
$sourceCancelled = $sourceState !== null && $sourceState['cancelled'];
?>
<section class="sg-order-source<?php echo $sourceCancelled ? ' is-cancelled' : ''; ?>" data-sg-order-source-status data-sg-basalam-order-id="<?php echo esc_attr((string) \SyncBasalam\SalamGate\Order\BasalamOrderContext::getInvoiceId((int) $order->get_id())); ?>" aria-live="polite">
    <div class="sg-order-source__heading">
        <span class="dashicons dashicons-shield" aria-hidden="true"></span>
        <strong>وضعیت سفارش در باسلام</strong>
        <button type="button" class="sg-order-source__refresh" data-sg-order-source-refresh aria-label="بررسی دوباره وضعیت سفارش باسلام" title="بررسی دوباره وضعیت">
            <span class="dashicons dashicons-update" aria-hidden="true"></span>
        </button>
    </div>
    <span class="sg-order-source__badge" data-sg-order-source-title><?php echo esc_html($sourceState['status_title'] ?? 'در حال بررسی وضعیت…'); ?></span>
    <p class="sg-order-source__message" data-sg-order-source-message><?php echo esc_html($sourceState['blocked_reason'] ?? 'وضعیت اصلی از باسلام بررسی می‌شود؛ وضعیت محلی ووکامرس به‌تنهایی مجوز ارسال نیست.'); ?></p>
    <p class="sg-order-source__note">لغو در باسلام، رسید قبلی یا سابقه سفارش را حذف نمی‌کند. ثبت و پرداخت تازه فقط پس از تأیید وضعیت اصلی مجاز است.</p>
    <a class="sg-order-source__link" href="https://vendor.basalam.com/orders" target="_blank" rel="noopener noreferrer">بررسی در سفارش‌های باسلام <span aria-hidden="true">↗</span></a>
</section>
