<?php

if (! defined('ABSPATH')) exit;

use SyncBasalam\SalamGate\Admin\OrderMetabox;
use SyncBasalam\SalamGate\Contracts\LabelEligibility;
use SyncBasalam\SalamGate\Contracts\ParcelStatuses;
use SyncBasalam\SalamGate\FeatureAvailability;
use SyncBasalam\SalamGate\Order\OrderContext;
use SyncBasalam\SalamGate\Order\BasalamParcelOrder;
use SyncBasalam\SalamGate\Settings\SalamGateSettings;
use SyncBasalam\SalamGate\Utils\Units;

/** @var \WC_Order $order */

// Native Basalam parcels are selected explicitly; the parent is never a shipment.
if (!$order instanceof BasalamParcelOrder && OrderContext::marketFor((int) $order->get_id()) === OrderContext::MARKET_BASALAM) {
    include __DIR__ . '/order-native-parcels.php';
    return;
}

$parcelId = (int) $order->get_meta('_salam_gate_parcel_id');
$statusMeta = $order->get_meta('_salam_gate_status');
$parcelStatusId = (is_int($statusMeta) || (is_string($statusMeta) && preg_match('/^[1-9]\d*$/D', $statusMeta)))
    ? (int) $statusMeta : 0;
$statusMissing = !ParcelStatuses::isValid($parcelStatusId);
$trackingMeta = $order->get_meta('_salam_gate_tracking_code');
$trackingCode = is_string($trackingMeta) ? trim($trackingMeta) : '';
$canPrintLabel = LabelEligibility::hasPrintableReceipt(
    $order->get_meta('_salam_gate_parcel_id'), $order->get_meta('_salam_gate_status'), $trackingMeta
);
$deliveryNode = (string) $order->get_meta('_salam_gate_delivery_node');
$labelMeta = $order->get_meta('_salam_gate_label');

$market = (string) ($order->get_meta('_salam_gate_market') ?: OrderContext::marketFor((int) $order->get_id()));
$isWebsite = $market === OrderContext::MARKET_WEBSITE;

$isActionable = OrderMetabox::isActionableStatus($market, $order->get_status());
// پرداخت‌شده = رسید پستی صادر شده (ماشین وضعیت) — مقایسه عددی ممنوع؛ 44 ابطال رسیدِ فعال نیست.
$isPaid = $parcelId > 0 && ParcelStatuses::hasReceipt($parcelStatusId);
$paymentUnknown = ! $isPaid && ($order->get_meta('_salam_gate_pay_unknown')
    || $order->get_meta('_salam_gate_paid_at') || (int) $order->get_meta('_salam_gate_invoice_id') > 0);
$available = FeatureAvailability::isVisible();
$shippingCostMeta = $order->get_meta('_salam_gate_shipping_cost_rial');
$shippingCostRial = (is_int($shippingCostMeta) || (is_string($shippingCostMeta) && preg_match('/^(0|[1-9]\d*)$/D', $shippingCostMeta)))
    ? filter_var($shippingCostMeta, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;

$statusLabels = [
    ParcelStatuses::NEW                => 'در انتظار پرداخت',
    ParcelStatuses::POSTAL_BARCODE     => 'بارکد صادر شد',
    ParcelStatuses::SHIPPED            => 'ارسال شد',
    ParcelStatuses::DELIVERED          => 'تحویل شد',
    ParcelStatuses::HANDED_TO_POSTMAN  => 'تحویل نامه‌رسان',
    ParcelStatuses::RETURNED_TO_SENDER => 'مرجوع',
    ParcelStatuses::VOIDED             => 'ابطال',
];
$statusLabel = $statusLabels[$parcelStatusId] ?? 'وضعیت ارسال هنوز دریافت نشده';
if (!$isPaid && $paymentUnknown && ($statusMissing || $parcelStatusId === ParcelStatuses::NEW)) {
    $statusLabel = 'در حال بررسی پرداخت';
}
$labelTemplates = [
    'invoice'     => 'فاکتور کامل',
    'invoice_new' => 'فاکتور کامل (طراحی جدید)',
    'summary'     => 'لیبل خلاصه',
    'label_10x15' => 'لیبل ۱۰×۱۵ (لیبل‌زن)',
];
$defaultTemplate = (string) SalamGateSettings::instance()->get(SalamGateSettings::KEY_LABEL_TEMPLATE_DEFAULT);

if (!$available) :
    ?>
    <p class="basalam-p" style="margin:0;">غرفه سلام‌گیت فعال نیست — وضعیت را از صفحه <a href="<?php echo esc_url(admin_url('admin.php?page=salam_gate')); ?>">سلام‌گیت</a> بررسی کنید.</p>
    <?php
    return;
endif;

if (!$parcelId) :
    ?>
    <?php if ($isActionable) : ?>
        <p class="basalam-p sg-order-helper"><?php echo $isWebsite
            ? 'ارسال سفارش سایت خودتان با پست + بارکد رهگیری.'
            : 'ارسال با پست + بارکد رهگیری؛ هزینه از مبلغ سفارش کسر می‌شود.'; ?></p>
        <button type="button" class="basalam-button basalam-p sg-order-primary salam-gate-open-modal">ارسال با سلام‌گیت</button>
    <?php else : ?>
        <p class="basalam-p" style="margin:0;">—</p>
    <?php endif; ?>
    <?php
    return;
endif;
?>
<ul class="salam-gate-summary">
    <li class="sg-order-summary-row sg-order-summary-header">
        <span class="dashicons dashicons-archive sg-order-parcel-icon" aria-hidden="true"></span>
        <strong class="sg-order-meta-label">مرسوله:</strong>
        <span class="sg-order-meta-value sg-order-parcel-id" dir="ltr">#<?php echo esc_html((string) $parcelId); ?></span>
    </li>
    <li class="sg-order-summary-row sg-order-summary-status">
        <strong class="sg-order-meta-label">وضعیت ارسال:</strong>
        <span class="salam-gate-badge sg-order-meta-value<?php echo $isPaid ? ' is-paid' : (($statusMissing || $parcelStatusId === ParcelStatuses::NEW) ? ' is-pending' : ''); ?>" role="status" aria-live="polite"<?php echo $statusMissing ? ' data-sg-order-status-missing' : ''; ?>><?php echo esc_html($statusLabel); ?></span>
    </li>
    <?php if ($isPaid && $shippingCostRial !== false) : ?>
    <li class="sg-order-summary-row"><strong class="sg-order-meta-label">هزینه نهایی ارسال:</strong><span class="sg-order-meta-value"><?php echo esc_html(Units::formatToman(Units::toToman($shippingCostRial)) . ' تومان'); ?></span></li>
    <?php endif; ?>
</ul>

<?php if (!$isPaid) : ?>
    <?php if ($isActionable || $paymentUnknown) : ?>
    <div class="sg-order-payment-panel">
        <div class="sg-order-payment-feedback" data-sg-order-payment-feedback data-sg-feedback-notice data-sg-feedback-kind="warning" role="alert" <?php echo $paymentUnknown ? '' : 'style="display:none;"'; ?>>
            <?php if ($paymentUnknown) : ?><p>نتیجه پرداخت قبلی هنوز تأیید نشده است؛ وضعیت را از فهرست مرسولات بررسی کنید و دوباره پرداخت نکنید.</p><?php endif; ?>
        </div>
        <?php if ($isActionable) : ?>
            <div class="sg-order-payment-actions">
                <button type="button" class="basalam-button basalam-p sg-order-primary salam-gate-pay" <?php disabled($paymentUnknown); ?>>ارسال با سلام‌گیت</button>
                <?php if ($isWebsite) : ?><p class="basalam-p sg-order-helper">شیوه ارسال، مبلغ و روش پرداخت را در مرحله بعد بررسی کنید.</p><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php else : ?>
    <div class="sg-order-receipt-panel">
        <?php if ($trackingCode !== '') : ?>
            <div class="sg-order-tracking-panel">
                <h4 class="sg-order-section-heading">کد رهگیری</h4>
                <div class="salam-gate-tracking">
                    <span class="salam-gate-tracking__code" dir="ltr"><?php echo esc_html($trackingCode); ?></span>
                    <button type="button" class="button button-small salam-gate-copy" data-code="<?php echo esc_attr($trackingCode); ?>">کپی</button>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canPrintLabel) : ?>
        <div class="sg-order-label-panel">
            <h4 class="sg-order-section-heading">لیبل مرسوله</h4>
            <div class="salam-gate-label-row">
                <select class="salam-gate-label-template">
                    <?php foreach ($labelTemplates as $value => $title) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $defaultTemplate); ?>><?php echo esc_html($title); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="basalam-button basalam-p sg-order-primary salam-gate-get-label"><span class="dashicons dashicons-printer" aria-hidden="true"></span> چاپ برچسب پستی</button>
            </div>
            <p class="salam-gate-label-status basalam-p description" style="display:none;margin:4px 0 0;font-size:0.75rem;"></p>
        </div>
        <?php else : ?>
            <p class="sg-order-helper">پرداخت ثبت شده است؛ برچسب پس از صدور کد رهگیری در دسترس قرار می‌گیرد.</p>
        <?php endif; ?>

        <?php if ($deliveryNode !== '') : ?>
            <p class="basalam-p salam-gate-guide" style="margin:8px 0 0;font-size:0.75rem;">⚠️ مرسوله را ظرف ۲۴ ساعت به مرکز «<?php echo esc_html($deliveryNode); ?>» تحویل دهید.</p>
        <?php endif; ?>

    </div>
<?php endif; ?>
